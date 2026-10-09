<?php

namespace App\Services\Creatives;

use App\Enums\ContentRequestStatus;
use App\Enums\CreativeProductStatus;
use App\Models\CommissionLedgerEntry;
use App\Models\ContentRequest;
use App\Models\ContentRequestItem;
use App\Models\CreativeProduct;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The review-queue state machine (handoff §3). Every transition lives
 * here so the rules — who may act in which status, what bumps `rev`,
 * what validation writes — are stated once and shared by the admin and
 * editor controllers.
 *
 *   sent ──push──▶ returned ──edits──▶ edits ──resubmit──▶ returned
 *                     │
 *                 validate ──▶ validated (+ ledger row, + product counters)
 */
class ContentRequestService
{
    /**
     * Admin orders content: one `sent` row per editor, each with a full
     * copy of the items.
     *
     * @param  array<int, int>  $editorIds
     * @param  array<int, array{type: string, count: int, directions?: array<int, string>}>  $items
     * @return Collection<int, ContentRequest>
     */
    public function request(CreativeProduct $product, array $editorIds, array $items, ?string $note): Collection
    {
        return DB::transaction(fn () => collect($editorIds)->map(function (int $editorId) use ($product, $items, $note) {
            $request = ContentRequest::create([
                'business_id' => $product->business_id,
                'creative_product_id' => $product->id,
                'editor_id' => $editorId,
                'origin' => 'admin',
                'status' => ContentRequestStatus::SENT,
                'admin_note' => $note,
                'direction_points' => [],
            ]);

            $this->writeItems($request, $items);

            return $request;
        }));
    }

    /**
     * Editor's daily self-push: born directly as `returned`, no
     * directions, active products only.
     *
     * @param  array<int, array{type: string, count: int}>  $items
     */
    public function selfPush(User $editor, CreativeProduct $product, array $items, string $driveUrl, ?string $note): ContentRequest
    {
        if ($product->status !== CreativeProductStatus::ACTIVE) {
            throw new InvalidArgumentException('Only active products accept a self-push; test content starts from a request.');
        }

        return DB::transaction(function () use ($editor, $product, $items, $driveUrl, $note) {
            $request = ContentRequest::create([
                'business_id' => $product->business_id,
                'creative_product_id' => $product->id,
                'editor_id' => $editor->id,
                'origin' => 'editor',
                'status' => ContentRequestStatus::RETURNED,
                'drive_url' => $driveUrl,
                'editor_note' => $note,
                'direction_points' => [],
                'returned_at' => now(),
            ]);

            $this->writeItems($request, $items);

            return $request;
        });
    }

    /**
     * Admin edits counts / directions / note — only while `sent`, and
     * without touching `rev`.
     *
     * @param  array<int, array{type: string, count: int, directions?: array<int, string>}>  $items
     */
    public function updateSent(ContentRequest $request, array $items, ?string $note): ContentRequest
    {
        $this->assertStatus($request, ContentRequestStatus::SENT);

        return DB::transaction(function () use ($request, $items, $note) {
            $request->update(['admin_note' => $note]);
            $request->items()->delete();
            $this->writeItems($request, $items);

            return $request->refresh();
        });
    }

    /** Admin cancels — only while `sent`. The row is removed outright. */
    public function cancel(ContentRequest $request): void
    {
        $this->assertStatus($request, ContentRequestStatus::SENT);

        $request->delete();
    }

    /** Editor delivers (from `sent` or `edits`): drive link required. */
    public function push(ContentRequest $request, string $driveUrl, ?string $note): ContentRequest
    {
        $this->assertStatus($request, ContentRequestStatus::SENT, ContentRequestStatus::EDITS);

        $request->update([
            'status' => ContentRequestStatus::RETURNED,
            'drive_url' => $driveUrl,
            'editor_note' => $note,
            'returned_at' => now(),
        ]);

        return $request;
    }

    /** Editor corrects the link / note — only while `returned`. */
    public function updatePush(ContentRequest $request, string $driveUrl, ?string $note): ContentRequest
    {
        $this->assertStatus($request, ContentRequestStatus::RETURNED);

        $request->update(['drive_url' => $driveUrl, 'editor_note' => $note]);

        return $request;
    }

    /**
     * Admin sends it back with direction points. `rev` bumps here and
     * only here; the points replace the previous round's list (the UI
     * sends the full, edited list).
     *
     * @param  array<int, string>  $points
     */
    public function requestEdits(ContentRequest $request, array $points): ContentRequest
    {
        $this->assertStatus($request, ContentRequestStatus::RETURNED);

        $request->update([
            'status' => ContentRequestStatus::EDITS,
            'rev' => $request->rev + 1,
            'direction_points' => array_values(array_filter(array_map('trim', $points), fn (string $p) => $p !== '')),
            'edits_requested_at' => now(),
        ]);

        return $request;
    }

    /**
     * Terminal. Atomically: status + validated_at, the editor's pay row
     * in the shared commission ledger, and the product's counters.
     */
    public function validate(ContentRequest $request, int $amountMad): ContentRequest
    {
        $this->assertStatus($request, ContentRequestStatus::RETURNED);

        if ($request->editor_id === null) {
            throw new InvalidArgumentException('This request has no editor to pay — their account was deleted.');
        }

        return DB::transaction(function () use ($request, $amountMad) {
            $request->load(['items', 'product']);

            $request->update([
                'status' => ContentRequestStatus::VALIDATED,
                'validated_at' => now(),
            ]);

            CommissionLedgerEntry::create([
                'business_id' => $request->business_id,
                'user_id' => $request->editor_id,
                'order_id' => null,
                'content_request_id' => $request->id,
                'amount' => $amountMad,
                'entry_type' => 'creative',
                'description' => $request->product->name.' · '.$request->label(),
            ]);

            $request->product->increment('works_count', $request->totalCount());
            $request->product->update(['last_push_at' => now()]);

            return $request;
        });
    }

    /**
     * @param  array<int, array{type: string, count: int, directions?: array<int, string>}>  $items
     */
    private function writeItems(ContentRequest $request, array $items): void
    {
        foreach ($items as $item) {
            $count = (int) $item['count'];
            $directions = array_slice(array_values($item['directions'] ?? []), 0, $count);

            ContentRequestItem::create([
                'content_request_id' => $request->id,
                'type' => $item['type'],
                'count' => $count,
                'directions' => array_map(fn ($d) => trim((string) $d), $directions),
            ]);
        }
    }

    private function assertStatus(ContentRequest $request, ContentRequestStatus ...$allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            abort(403, __('This request is :status and cannot be changed that way.', ['status' => $request->status->value]));
        }
    }
}
