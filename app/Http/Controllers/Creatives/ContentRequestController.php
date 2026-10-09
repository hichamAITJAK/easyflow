<?php

namespace App\Http\Controllers\Creatives;

use App\Enums\CreativeProductStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ContentRequest;
use App\Models\CreativeProduct;
use App\Models\User;
use App\Services\Creatives\ContentRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * The review queue's actions. Admin actions are gated by `manage-users`
 * in the routes; the editor ones check that the request is theirs.
 */
class ContentRequestController extends Controller
{
    public function __construct(private readonly ContentRequestService $requests) {}

    /* ───────────── admin ───────────── */

    public function store(Request $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;

        $data = $request->validate([
            'product_id' => ['required', Rule::exists(CreativeProduct::class, 'id')->where('business_id', $businessId)],
            'editor_ids' => ['required', 'array', 'min:1'],
            'editor_ids.*' => ['integer', Rule::exists(User::class, 'id')->where('business_id', $businessId)->where('role', UserRole::CREATIVES_EDITOR->value)],
            ...$this->itemRules(withDirections: true),
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = CreativeProduct::findOrFail($data['product_id']);

        abort_if($product->status === CreativeProductStatus::INACTIVE, 422, __('Reactivate the product before requesting content.'));

        $created = $this->requests->request($product, array_unique($data['editor_ids']), $data['items'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request sent to :count editor(s) for ":name".', ['count' => $created->count(), 'name' => $product->name])]);

        return back();
    }

    public function update(Request $request, ContentRequest $contentRequest): RedirectResponse
    {
        $data = $request->validate([
            ...$this->itemRules(withDirections: true),
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->requests->updateSent($contentRequest, $data['items'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request updated — the editor sees the changes.')]);

        return back();
    }

    public function destroy(ContentRequest $contentRequest): RedirectResponse
    {
        $this->requests->cancel($contentRequest);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request cancelled.')]);

        return back();
    }

    public function edits(Request $request, ContentRequest $contentRequest): RedirectResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:50'],
            'points.*' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->requests->requestEdits($contentRequest, $data['points']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Edits requested — back to the editor (v:rev).', ['rev' => $updated->rev])]);

        return back();
    }

    public function validateWork(Request $request, ContentRequest $contentRequest): RedirectResponse
    {
        $data = $request->validate([
            'amount_mad' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        try {
            $this->requests->validate($contentRequest, (int) $data['amount_mad']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Work validated — :amount MAD added to the commissions.', ['amount' => number_format($data['amount_mad'])])]);

        return back();
    }

    /* ───────────── editor ───────────── */

    public function push(Request $request, ContentRequest $contentRequest): RedirectResponse
    {
        $this->assertOwnedBy($request->user(), $contentRequest);

        $data = $request->validate([
            'drive_url' => ['required', 'url', 'max:2048'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->requests->push($contentRequest, $data['drive_url'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content submitted — waiting for review.')]);

        return back();
    }

    public function pushUpdate(Request $request, ContentRequest $contentRequest): RedirectResponse
    {
        $this->assertOwnedBy($request->user(), $contentRequest);

        $data = $request->validate([
            'drive_url' => ['required', 'url', 'max:2048'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->requests->updatePush($contentRequest, $data['drive_url'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submission updated.')]);

        return back();
    }

    /** The editor's daily push on an active product they're assigned to. */
    public function selfPush(Request $request): RedirectResponse
    {
        $editor = $request->user();

        abort_unless($editor->role === UserRole::CREATIVES_EDITOR, 403);

        $data = $request->validate([
            'product_id' => ['required', Rule::exists(CreativeProduct::class, 'id')->where('business_id', $editor->business_id)],
            ...$this->itemRules(withDirections: false),
            'drive_url' => ['required', 'url', 'max:2048'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = CreativeProduct::whereHas('editors', fn ($q) => $q->whereKey($editor->id))->findOrFail($data['product_id']);

        try {
            $this->requests->selfPush($editor, $product, $data['items'], $data['drive_url'], $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Push submitted — waiting for review.')]);

        return back();
    }

    /** @return array<string, array<int, mixed>> */
    private function itemRules(bool $withDirections): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:2'],
            'items.*.type' => ['required', Rule::in(['video', 'static']), 'distinct'],
            'items.*.count' => ['required', 'integer', 'min:1', 'max:30'],
            ...($withDirections ? [
                'items.*.directions' => ['nullable', 'array', 'max:30'],
                'items.*.directions.*' => ['nullable', 'string', 'max:500'],
            ] : []),
        ];
    }

    private function assertOwnedBy(User $user, ContentRequest $contentRequest): void
    {
        abort_unless($user->role === UserRole::CREATIVES_EDITOR && $contentRequest->editor_id === $user->id, 403);
    }
}
