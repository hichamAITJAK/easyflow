<?php

namespace App\Services\Operations\Settlements;

use App\Enums\OrderDeliveryStatus;
use App\Models\DeliveryCashSettlement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cash a delivery guy holds on delivered parcels, and the hand-over that
 * clears it. Mirrors CourierSettlementService for own delivery: the
 * parcels say what is expected, the admin types what was received, and a
 * gap larger than the tolerance marks the hand-over disputed.
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

    public function owed(User $guy): float
    {
        return round((float) $this->owedParcels($guy)->sum('total_amount'), 2);
    }

    /**
     * Tie every unsettled delivered parcel to one new settlement. With no
     * received amount the hand-over is taken as complete.
     */
    public function settle(User $guy, User $by, ?float $received = null, ?string $note = null): ?DeliveryCashSettlement
    {
        return DB::transaction(function () use ($guy, $by, $received, $note) {
            $parcels = $this->owedParcels($guy)->lockForUpdate()->get(['id', 'total_amount']);

            if ($parcels->isEmpty()) {
                return null;
            }

            $expected = round((float) $parcels->sum('total_amount'), 2);
            $received = $received === null ? $expected : round($received, 2);
            $difference = round($received - $expected, 2);

            $settlement = DeliveryCashSettlement::create([
                'business_id' => $guy->business_id,
                'user_id' => $guy->id,
                'settled_by_user_id' => $by->id,
                'amount' => $expected,
                'received_amount' => $received,
                'difference_amount' => $difference,
                'parcels_count' => $parcels->count(),
                'status' => abs($difference) < CourierSettlementService::MATCH_TOLERANCE_MAD ? 'reconciled' : 'disputed',
                'note' => $note,
            ]);

            Order::whereIn('id', $parcels->pluck('id'))->update(['delivery_cash_settlement_id' => $settlement->id]);

            return $settlement;
        });
    }
}
