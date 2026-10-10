<?php

namespace App\Http\Controllers\Settlements;

use App\Http\Controllers\Controller;
use App\Models\CourierSettlement;
use App\Models\DeliveryAccount;
use App\Services\Operations\Settlements\CourierSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UC-15: expected-vs-actual courier settlement reconciliation. Owner/Manager
 * only (gated by can:manage-users at the route level) — this is a business
 * financial reconciliation tool, not something a confirmation/fulfilment
 * agent has any reason to see.
 */
class CourierSettlementController extends Controller
{
    public function __construct(private readonly CourierSettlementService $settlements) {}

    /**
     * List past settlements and the delivery accounts available to
     * reconcile a new period against.
     */
    public function index(Request $request): Response
    {
        $businessId = $request->user()->business_id;

        $settlements = CourierSettlement::where('business_id', $businessId)
            ->with(['deliveryAccount.courier:id,name,slug', 'reconciler:id,name'])
            ->latest('period_end')
            ->get();

        return Inertia::render('settlements/index', [
            'settlements' => $settlements,
            'deliveryAccounts' => DeliveryAccount::where('business_id', $businessId)
                ->with('courier:id,name,slug')
                ->get(['id', 'courier_id', 'label']),
        ]);
    }

    /**
     * Compute the expected amount for a delivery account and period,
     * without persisting anything — lets the reconciliation form show the
     * expected figure before the owner/manager enters the actual amount.
     */
    public function expected(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_account_id' => [
                'required',
                Rule::exists(DeliveryAccount::class, 'id')->where('business_id', $request->user()->business_id),
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $expected = $this->settlements->computeExpected(
            $request->user()->business_id,
            (int) $data['delivery_account_id'],
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end']),
        );

        return response()->json(['expected_amount' => $expected]);
    }

    /**
     * Record the actual amount received from the courier for a period,
     * reconciling (creating or updating) the settlement for that
     * account/period.
     */
    public function reconcile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delivery_account_id' => [
                'required',
                Rule::exists(DeliveryAccount::class, 'id')->where('business_id', $request->user()->business_id),
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'actual_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $settlement = $this->settlements->reconcile(
            $request->user()->business_id,
            (int) $data['delivery_account_id'],
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end']),
            (float) $data['actual_amount'],
            $request->user()->id,
            $data['notes'] ?? null,
        );

        Inertia::flash('toast', $settlement->status === 'reconciled'
            ? ['type' => 'success', 'message' => __('Settlement reconciled.')]
            : ['type' => 'error', 'message' => __('Amounts do not match (:difference MAD). The settlement is marked as disputed.', [
                'difference' => number_format((float) $settlement->difference_amount, 2),
            ])]);

        return back(fallback: route('settlements.index'));
    }

    /**
     * Mark a settlement as disputed instead of reconciled.
     */
    public function dispute(Request $request, CourierSettlement $settlement): RedirectResponse
    {
        abort_unless($settlement->business_id === $request->user()->business_id, 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->settlements->dispute($settlement, $data['notes'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settlement marked as disputed.')]);

        return back(fallback: route('settlements.index'));
    }
}
