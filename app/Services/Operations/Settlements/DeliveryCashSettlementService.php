<?php

namespace App\Services\Operations\Settlements;

use App\Enums\OrderDeliveryStatus;
use App\Models\DeliveryCashSettlement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cash a delivery guy holds, and the hand-over that clears it.
 *
 * What he owes on a delivered parcel is its total minus his pay for it
 * (the order's delivery_cost, set when he marks it delivered; zero on a
 * salary). What he owes overall is that, over every unsettled delivered
 * parcel, plus whatever was left open by his last hand-over: a short
 * hand-over keeps the shortfall on his balance instead of zeroing it.
 */
class DeliveryCashSettlementService
{
    /** @return Builder<Order> */
    public function owedParcels(User $guy): Builder
    {
        return Order::where('business_id', $guy->business_id)
            ->where('delivery_guy_id', $guy->id)
            ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
            ->whereNull('delivery_cash_settlement_id');
    }

    /** Cash still to hand over from the parcels alone. */
    public function parcelsNet(User $guy): float
    {
        return round((float) $this->owedParcels($guy)
            ->toBase()
            ->selectRaw('coalesce(sum(total_amount - coalesce(delivery_cost, 0)), 0) as net')
            ->value('net'), 2);
    }

    /** What his last hand-over left open (positive: still owes). */
    public function carried(User $guy): float
    {
        return round((float) DeliveryCashSettlement::where('business_id', $guy->business_id)
            ->where('user_id', $guy->id)
            ->latest('id')
            ->value('balance_after'), 2);
    }

    public function owed(User $guy): float
    {
        return round($this->parcelsNet($guy) + $this->carried($guy), 2);
    }

    /**
     * Tie every unsettled delivered parcel to one new hand-over. With no
     * received amount the hand-over is taken as complete. What is still
     * missing afterwards stays on his balance for the next one.
     */
    public function settle(User $guy, User $by, ?float $received = null, ?string $note = null): ?DeliveryCashSettlement
    {
        return DB::transaction(function () use ($guy, $by, $received, $note) {
            $parcels = $this->owedParcels($guy)->lockForUpdate()->get(['id', 'total_amount', 'delivery_cost']);
            $carried = $this->carried($guy);
            $net = round((float) $parcels->sum(fn (Order $order) => (float) $order->total_amount - (float) ($order->delivery_cost ?? 0)), 2);
            $expected = round($net + $carried, 2);

            if ($parcels->isEmpty() && abs($carried) < 0.01) {
                return null;
            }

            $received = $received === null ? $expected : round($received, 2);
            $difference = round($received - $expected, 2);

            $settlement = DeliveryCashSettlement::create([
                'business_id' => $guy->business_id,
                'user_id' => $guy->id,
                'settled_by_user_id' => $by->id,
                'amount' => $expected,
                'received_amount' => $received,
                'difference_amount' => $difference,
                'balance_after' => abs($difference) < 0.01 ? 0 : round(-$difference, 2),
                'parcels_count' => $parcels->count(),
                'status' => abs($difference) < CourierSettlementService::MATCH_TOLERANCE_MAD ? 'reconciled' : 'disputed',
                'note' => $note,
            ]);

            if ($parcels->isNotEmpty()) {
                Order::whereIn('id', $parcels->pluck('id'))->update(['delivery_cash_settlement_id' => $settlement->id]);
            }

            return $settlement;
        });
    }
}
