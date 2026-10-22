<?php

namespace App\Http\Controllers\Finance;

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\ResolvesReportPeriod;
use App\Http\Controllers\Controller;
use App\Models\CourierSettlement;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCashSettlement;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\Settlements\CourierSettlementService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance › Cash tracking: one row per carrier (courier account or own
 * delivery guy) with how the period's parcels went and how much cash
 * they are still holding.
 *
 * Two kinds of figure sit side by side on purpose:
 *  - period figures (shipped, delivered, returned, collected, delivery
 *    cost) follow the date filter — they describe work done in the window;
 *  - "still owed" and "last settlement" ignore it — a balance is a fact
 *    about now, not about a period, and filtering it by date would hide
 *    money that is genuinely outstanding.
 */
class CashTrackingController extends Controller
{
    use ResolvesReportPeriod;

    private const RETURNED = [
        OrderDeliveryStatus::REFUSED,
        OrderDeliveryStatus::RETURNED_IN_TRANSIT,
        OrderDeliveryStatus::RETURN_RECEIVED,
    ];

    public function index(Request $request): Response
    {
        $businessId = $request->user()->business_id;

        $period = $request->string('period')->toString() ?: '30d';
        [$since, $until] = $this->resolveReportPeriod($period, $request);

        return Inertia::render('finance/cash', [
            'filters' => [
                'period' => $period !== '30d' ? $period : null,
                'date_from' => $period === 'custom' ? $since->toDateString() : null,
                'date_to' => $period === 'custom' ? $until->toDateString() : null,
            ],
            'couriers' => $this->courierRows($businessId, $since, $until),
            'deliveryGuys' => $this->deliveryGuyRows($businessId, $since, $until),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function courierRows(int $businessId, Carbon $since, Carbon $until): array
    {
        $accounts = DeliveryAccount::where('business_id', $businessId)
            ->with('courier:id,name,logo')
            ->orderBy('label')
            ->get();

        $period = $this->periodFigures($businessId, 'delivery_account_id', $since, $until);

        // Delivered parcels no closed settlement period covers yet, net of
        // what the courier charges to deliver them. Same rule as the
        // dashboard's "Expected" so the two never disagree.
        $owed = $this->shippedOrders($businessId, 'delivery_account_id')
            ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('courier_settlements')
                ->whereColumn('courier_settlements.delivery_account_id', 'orders.delivery_account_id')
                ->where('courier_settlements.status', '!=', 'pending')
                ->whereNull('courier_settlements.deleted_at')
                ->whereColumn('courier_settlements.period_start', '<=', 'orders.shipped_at')
                ->whereColumn('courier_settlements.period_end', '>=', 'orders.shipped_at'))
            ->toBase()
            ->selectRaw('delivery_account_id as id, count(*) as parcels, coalesce(sum(total_amount - coalesce(delivery_cost, 0)), 0) as amount')
            ->groupBy('delivery_account_id')
            ->get()
            ->keyBy('id');

        $lastSettlements = CourierSettlement::where('business_id', $businessId)
            ->where('status', '!=', 'pending')
            ->whereIn('id', fn ($query) => $query->selectRaw('max(id)')
                ->from('courier_settlements')
                ->where('business_id', $businessId)
                ->where('status', '!=', 'pending')
                ->whereNull('deleted_at')
                ->groupBy('delivery_account_id'))
            ->get()
            ->keyBy('delivery_account_id');

        return $accounts->map(function (DeliveryAccount $account) use ($period, $owed, $lastSettlements) {
            $last = $lastSettlements->get($account->id);

            return [
                'id' => $account->id,
                'name' => $account->label,
                'subtitle' => $account->courier?->name,
                'image' => $account->courier?->logo,
                ...$this->rowFigures($period->get($account->id)),
                'stillOwedMad' => round((float) ($owed->get($account->id)?->amount ?? 0), 2),
                'unsettledParcels' => (int) ($owed->get($account->id)?->parcels ?? 0),
                'lastSettlement' => $last === null ? null : $this->settlementSummary(
                    $last->period_end,
                    (float) ($last->difference_amount ?? 0),
                ),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deliveryGuyRows(int $businessId, Carbon $since, Carbon $until): array
    {
        $guys = User::where('business_id', $businessId)
            ->where('role', UserRole::DELIVERY_GUY)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar', 'phone']);

        $period = $this->periodFigures($businessId, 'delivery_guy_id', $since, $until);

        // Delivered parcels not yet tied to a hand-over, net of his pay.
        $owed = $this->shippedOrders($businessId, 'delivery_guy_id')
            ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
            ->whereNull('delivery_cash_settlement_id')
            ->toBase()
            ->selectRaw('delivery_guy_id as id, count(*) as parcels, coalesce(sum(total_amount - coalesce(delivery_cost, 0)), 0) as amount')
            ->groupBy('delivery_guy_id')
            ->get()
            ->keyBy('id');

        // Plus whatever his last hand-over left open (a short hand-over
        // carries forward), which is also the "last settlement" shown.
        $lastHandovers = DeliveryCashSettlement::where('business_id', $businessId)
            ->whereIn('id', fn ($query) => $query->selectRaw('max(id)')
                ->from('delivery_cash_settlements')
                ->where('business_id', $businessId)
                ->groupBy('user_id'))
            ->get()
            ->keyBy('user_id');

        return $guys->map(function (User $guy) use ($period, $owed, $lastHandovers) {
            $last = $lastHandovers->get($guy->id);
            $carried = (float) ($last?->balance_after ?? 0);

            return [
                'id' => $guy->id,
                'name' => $guy->name,
                'subtitle' => $guy->phone,
                'image' => $guy->avatar,
                ...$this->rowFigures($period->get($guy->id)),
                'stillOwedMad' => round((float) ($owed->get($guy->id)?->amount ?? 0) + $carried, 2),
                'unsettledParcels' => (int) ($owed->get($guy->id)?->parcels ?? 0),
                'lastSettlement' => $last === null ? null : $this->settlementSummary(
                    $last->created_at,
                    (float) ($last->difference_amount ?? 0),
                ),
            ];
        })->values()->all();
    }

    /**
     * Non-test orders handed to a carrier of the given kind.
     *
     * @return Builder<Order>
     */
    private function shippedOrders(int $businessId, string $column): Builder
    {
        return Order::where('business_id', $businessId)
            ->where('is_test', false)
            ->whereNotNull($column);
    }

    /**
     * The period's parcels per carrier, grouped in one query: a parcel
     * belongs to the window of the day it was handed over (shipped_at),
     * and counts by where it stands now.
     *
     * @return Collection<int, object>
     */
    private function periodFigures(int $businessId, string $column, Carbon $since, Carbon $until): Collection
    {
        $delivered = OrderDeliveryStatus::DELIVERED->value;
        $returned = implode(',', array_map(fn (OrderDeliveryStatus $status) => "'{$status->value}'", self::RETURNED));

        return $this->shippedOrders($businessId, $column)
            ->whereBetween('shipped_at', [$since->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->toBase()
            ->selectRaw("{$column} as id, count(*) as shipped")
            ->selectRaw('sum(case when delivery_status = ? then 1 else 0 end) as delivered', [$delivered])
            ->selectRaw("sum(case when delivery_status in ({$returned}) then 1 else 0 end) as returned")
            ->selectRaw('sum(case when delivery_status = ? then total_amount else 0 end) as collected', [$delivered])
            ->selectRaw('sum(case when delivery_status = ? then coalesce(delivery_cost, 0) else 0 end) as delivery_cost', [$delivered])
            ->groupBy($column)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<string, int|float|null>
     */
    private function rowFigures(?object $figures): array
    {
        $shipped = (int) ($figures->shipped ?? 0);
        $delivered = (int) ($figures->delivered ?? 0);
        $returned = (int) ($figures->returned ?? 0);

        return [
            'shipped' => $shipped,
            'delivered' => $delivered,
            'returned' => $returned,
            'inTransit' => max(0, $shipped - $delivered - $returned),
            'deliveryRatePct' => $shipped > 0 ? round($delivered / $shipped * 100) : null,
            'returnRatePct' => $shipped > 0 ? round($returned / $shipped * 100) : null,
            'collectedMad' => round((float) ($figures->collected ?? 0), 2),
            'deliveryCostMad' => round((float) ($figures->delivery_cost ?? 0), 2),
        ];
    }

    /**
     * @return array{at: string, status: 'matched'|'short', differenceMad: float}
     */
    private function settlementSummary(CarbonInterface $at, float $difference): array
    {
        // Only a shortfall is worth flagging: an overpayment or a match
        // reads as "all good".
        $short = $difference <= -CourierSettlementService::MATCH_TOLERANCE_MAD;

        return [
            'at' => $at->toDateString(),
            'status' => $short ? 'short' : 'matched',
            'differenceMad' => round($difference, 2),
        ];
    }
}
