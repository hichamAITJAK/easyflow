<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SubscriptionRequestController extends Controller
{
    use BuildsTableQuery;

    /**
     * Columns the history table may be sorted by. `business_name` and
     * `plan_name` live on related tables, so they are joined rather than
     * passed straight to orderBy — see applyHistorySort.
     */
    private const SORTABLE = ['business_name', 'plan_name', 'status', 'starts_at', 'ends_at', 'updated_at'];

    /**
     * Review queue: pending payment requests first, then a searchable,
     * paginated history table.
     *
     * The history table owns its own query-string keys (history_*) because
     * it shares this URL with the pending queue — a bare `page`/`sort` would
     * be ambiguous the moment the queue ever gains its own paging.
     */
    public function index(Request $request): Response
    {
        $present = fn (Subscription $subscription): array => [
            'id' => $subscription->id,
            'businessName' => $subscription->business->name,
            'planName' => $subscription->plan->name ?? __('Free trial'),
            'planPrice' => $subscription->plan?->price,
            'planCurrency' => $subscription->plan?->currency,
            'status' => $subscription->status->value,
            'referenceCode' => $subscription->reference_code,
            'paymentMethod' => $subscription->payment_method?->value,
            'paymentReference' => $subscription->payment_reference,
            'receiptUrl' => $subscription->receipt_path
                ? '/storage/'.$subscription->receipt_path
                : null,
            'submittedAt' => $subscription->submitted_at?->toDateTimeString(),
            'startsAt' => $subscription->starts_at?->toDateString(),
            'endsAt' => $subscription->ends_at?->toDateString(),
            'activatedBy' => $subscription->activatedBy?->name,
            'rejectionReason' => $subscription->rejection_reason,
            'notes' => $subscription->notes,
        ];

        $pending = Subscription::query()
            ->with(['business:id,name', 'plan:id,name,price,currency'])
            ->where('status', SubscriptionStatus::PENDING)
            ->orderBy('submitted_at')
            ->get()
            ->map($present);

        $history = Subscription::query()
            ->with(['business:id,name', 'plan:id,name,price,currency', 'activatedBy:id,name'])
            ->whereNot('status', SubscriptionStatus::PENDING)
            ->when(
                SubscriptionStatus::tryFrom($request->string('history_status')->toString()),
                fn (Builder $query, SubscriptionStatus $status) => $query->where('status', $status),
            );

        $history = $this->applyHistorySearch($history, $request);
        $history = $this->applyHistorySort($history, $request);

        $paginated = $history
            ->paginate(
                $this->resolvePerPage($request, perPageKey: 'history_per_page'),
                pageName: 'history_page',
            )
            ->withQueryString();

        $paginated->through($present);

        return Inertia::render('super-admin/subscriptions/index', [
            'pending' => $pending,
            'history' => $paginated,
            'historyFilters' => $request->only([
                'history_search', 'history_status', 'history_sort',
                'history_direction', 'history_per_page',
            ]),
        ]);
    }

    /**
     * Search the history table by business name or reference code. The
     * business name lives on a related table, so it can't go through the
     * shared applySearch helper (own-column LIKEs only).
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private function applyHistorySearch(Builder $query, Request $request): Builder
    {
        $search = $request->string('history_search')->trim()->toString();

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($search) {
            $query
                ->where('reference_code', 'like', "%{$search}%")
                ->orWhereHas('business', fn (Builder $business) => $business->where('name', 'like', "%{$search}%"));
        });
    }

    /**
     * Sort the history table, joining out to the related tables for the two
     * columns that don't live on `subscriptions`. Restricted to an
     * allow-list, so an arbitrary column can never reach the query.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private function applyHistorySort(Builder $query, Request $request): Builder
    {
        $sort = $request->string('history_sort')->toString();
        $direction = $request->string('history_direction')->toString() === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, self::SORTABLE, true)) {
            return $query->orderByDesc('updated_at');
        }

        return match ($sort) {
            'business_name' => $query->orderBy(
                Business::select('name')->whereColumn('businesses.id', 'subscriptions.business_id'),
                $direction,
            ),
            'plan_name' => $query->orderBy(
                Plan::select('name')->whereColumn('plans.id', 'subscriptions.plan_id'),
                $direction,
            ),
            default => $query->orderBy($sort, $direction),
        };
    }

    /**
     * Bank transfer verified — activate the cycle.
     */
    public function approve(Request $request, Subscription $subscription, SubscriptionService $subscriptions): RedirectResponse
    {
        abort_unless($subscription->status === SubscriptionStatus::PENDING, HttpResponse::HTTP_CONFLICT);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $subscriptions->approve($subscription, $request->user(), $validated['notes'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':business is now active until :date.', [
                'business' => $subscription->business->name,
                'date' => $subscription->refresh()->ends_at->toDateString(),
            ]),
        ]);

        return back();
    }

    /**
     * No matching transfer — refuse with a reason the business will see.
     */
    public function reject(Request $request, Subscription $subscription, SubscriptionService $subscriptions): RedirectResponse
    {
        abort_unless($subscription->status === SubscriptionStatus::PENDING, HttpResponse::HTTP_CONFLICT);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $subscriptions->reject($subscription, $validated['reason']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Payment request rejected.'),
        ]);

        return back();
    }
}
