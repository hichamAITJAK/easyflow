<?php

namespace App\Http\Controllers\Creatives;

use App\Enums\ContentRequestStatus;
use App\Enums\CreativeProductStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommissionLedgerEntry;
use App\Models\ContentRequest;
use App\Models\ContentRequestItem;
use App\Models\CreativeProduct;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Creatives page. One route, two screens: the admin runs products,
 * the review queue and commissions; a creatives editor sees only their
 * own products and requests.
 */
class CreativeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return $user->role === UserRole::CREATIVES_EDITOR
            ? $this->editorPage($user)
            : $this->adminPage($user);
    }

    private function adminPage(User $user): Response
    {
        $businessId = $user->business_id;

        // Every prop is a closure: a partial reload (`only: [...]`) after an
        // action runs just the queries it asked for. Commissions are deferred
        // — the page paints first, that tab fills in a second request.
        return Inertia::render('creatives/admin', [
            'products' => fn () => $this->adminProducts()->map(fn (CreativeProduct $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'kind' => $p->kind,
                'links' => $p->links,
                'description' => $p->description,
                'status' => $p->status->value,
                'works_count' => $p->works_count,
                'last_push_at' => $p->last_push_at?->toIso8601String(),
                'editors' => $p->editors->map(fn (User $e) => $this->person($e))->values(),
                'history' => $p->requests->map(fn (ContentRequest $r) => [
                    'id' => $r->id,
                    'rev' => $r->rev,
                    'validated_at' => $r->validated_at?->toIso8601String(),
                    'editor' => $r->editor?->name,
                    'items' => $this->items($r),
                    'amount' => (int) round((float) ($r->commission?->amount ?? 0)),
                    'drive_url' => $r->drive_url,
                ])->values(),
            ])->values(),
            'queue' => fn () => ContentRequest::where('status', '!=', ContentRequestStatus::VALIDATED)
                ->with(['items', 'editor:id,name', 'product:id,name,kind,links,status'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ContentRequest $r) => $this->requestPayload($r))
                ->values(),
            'commissions' => Inertia::defer(fn () => $this->adminCommissions($businessId)),
            'editors' => fn () => User::where('business_id', $businessId)
                ->where('role', UserRole::CREATIVES_EDITOR)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $e) => $this->person($e))
                ->values(),
        ]);
    }

    private function adminProducts()
    {
        return CreativeProduct::with(['editors:id,name'])
            ->with(['requests' => fn ($q) => $q->where('status', ContentRequestStatus::VALIDATED)
                ->with(['items', 'editor:id,name', 'commission:id,content_request_id,amount'])
                ->orderByDesc('validated_at')])
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function adminCommissions(int $businessId): array
    {
        $commissions = CommissionLedgerEntry::where('business_id', $businessId)
            ->where('entry_type', 'creative')
            ->with(['user:id,name', 'invoice:id,invoice_number,status', 'contentRequest:id,creative_product_id,validated_at', 'contentRequest.product:id,name'])
            ->latest('created_at')
            ->get();

        $monthStart = Carbon::now()->startOfMonth();
        $isPaid = fn (CommissionLedgerEntry $e) => $e->invoice?->status === 'paid';

        return [
            'rows' => $commissions->map(fn (CommissionLedgerEntry $e) => [
                'id' => $e->id,
                'product' => $e->contentRequest?->product?->name ?? '—',
                'label' => $e->description,
                'editor' => $e->user?->name ?? __('Deleted editor'),
                'validated_at' => Carbon::parse($e->created_at)->toIso8601String(),
                'amount' => (int) round((float) $e->amount),
                'paid' => $isPaid($e),
                'invoice_number' => $e->invoice?->invoice_number,
            ])->values(),
            'pending' => (int) round((float) $commissions->reject($isPaid)->sum('amount')),
            'paidThisMonth' => (int) round((float) $commissions->filter($isPaid)->filter(fn ($e) => Carbon::parse($e->created_at)->greaterThanOrEqualTo($monthStart))->sum('amount')),
            'countThisMonth' => $commissions->filter(fn ($e) => Carbon::parse($e->created_at)->greaterThanOrEqualTo($monthStart))->count(),
        ];
    }

    private function editorPage(User $editor): Response
    {
        return Inertia::render('creatives/editor', [
            'products' => fn () => CreativeProduct::where('status', CreativeProductStatus::ACTIVE)
                ->whereHas('editors', fn ($q) => $q->whereKey($editor->id))
                ->orderBy('name')
                ->get()
                ->map(fn (CreativeProduct $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'kind' => $p->kind,
                    'links' => $p->links,
                    'description' => $p->description,
                    'works_count' => $p->works_count,
                    'last_push_at' => $p->last_push_at?->toIso8601String(),
                ])->values(),
            'requests' => fn () => ContentRequest::where('editor_id', $editor->id)
                ->with(['items', 'product:id,name,kind,links,status'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ContentRequest $r) => $this->requestPayload($r))
                ->values(),
            'commissions' => Inertia::defer(fn () => $this->editorCommissions($editor)),
            // The editor's screen names the person who reviews their work.
            'admin' => fn () => User::where('business_id', $editor->business_id)
                ->where('role', UserRole::ADMIN)
                ->orderBy('id')
                ->value('name') ?? __('your admin'),
        ]);
    }

    /** @return array<string, mixed> */
    private function editorCommissions(User $editor): array
    {
        $entries = CommissionLedgerEntry::where('business_id', $editor->business_id)
            ->where('user_id', $editor->id)
            ->where('entry_type', 'creative')
            ->with(['invoice:id,status', 'contentRequest:id,creative_product_id', 'contentRequest.product:id,name'])
            ->latest('created_at')
            ->get();

        $monthStart = Carbon::now()->startOfMonth();
        $isPaid = fn (CommissionLedgerEntry $e) => $e->invoice?->status === 'paid';
        $thisMonth = fn (CommissionLedgerEntry $e) => Carbon::parse($e->created_at)->greaterThanOrEqualTo($monthStart);

        return [
            'rows' => $entries->map(fn (CommissionLedgerEntry $e) => [
                'id' => $e->id,
                'product' => $e->contentRequest?->product?->name ?? '—',
                'label' => $e->description,
                'validated_at' => Carbon::parse($e->created_at)->toIso8601String(),
                'amount' => (int) round((float) $e->amount),
                'paid' => $isPaid($e),
            ])->values(),
            'pending' => (int) round((float) $entries->reject($isPaid)->sum('amount')),
            'paidThisMonth' => (int) round((float) $entries->filter($isPaid)->filter($thisMonth)->sum('amount')),
            'countThisMonth' => $entries->filter($thisMonth)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function requestPayload(ContentRequest $r): array
    {
        $when = match ($r->status) {
            ContentRequestStatus::RETURNED => $r->returned_at,
            ContentRequestStatus::EDITS => $r->edits_requested_at,
            ContentRequestStatus::VALIDATED => $r->validated_at,
            default => $r->created_at,
        };

        return [
            'id' => $r->id,
            'product' => [
                'id' => $r->product->id,
                'name' => $r->product->name,
                'kind' => $r->product->kind,
                'links_count' => count($r->product->links ?? []),
                'status' => $r->product->status->value,
            ],
            'editor' => $r->editor?->name ?? __('Deleted editor'),
            'editor_id' => $r->editor_id,
            'origin' => $r->origin,
            'status' => $r->status->value,
            'rev' => $r->rev,
            'when' => ($when ?? $r->created_at)->toIso8601String(),
            'admin_note' => $r->admin_note,
            'drive_url' => $r->drive_url,
            'editor_note' => $r->editor_note,
            'direction_points' => $r->direction_points ?? [],
            'items' => $this->items($r),
        ];
    }

    /** @return list<array{type: string, count: int, directions: array<int, string>}> */
    private function items(ContentRequest $r): array
    {
        return $r->items->map(fn (ContentRequestItem $i) => [
            'type' => $i->type,
            'count' => $i->count,
            'directions' => $i->directions ?? [],
        ])->values()->all();
    }

    /** @return array{id: int, name: string, initials: string} */
    private function person(User $user): array
    {
        $parts = preg_split('/\s+/', trim($user->name)) ?: [];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'initials' => mb_strtoupper(implode('', array_map(fn (string $p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2)))),
        ];
    }
}
