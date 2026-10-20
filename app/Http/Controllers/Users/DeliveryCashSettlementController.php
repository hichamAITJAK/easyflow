<?php

namespace App\Http\Controllers\Users;

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DeliveryCashSettlement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The admin records that a delivery guy handed over the COD cash he was
 * holding: every delivered parcel of his with no settlement yet is tied
 * to one new settlement row, and his "cash owed" drops to zero.
 */
class DeliveryCashSettlementController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->business_id === $request->user()->business_id, 403);
        abort_unless($user->role === UserRole::DELIVERY_GUY, 404);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $settlement = DB::transaction(function () use ($request, $user, $data) {
            $parcels = Order::where('business_id', $user->business_id)
                ->where('delivery_guy_id', $user->id)
                ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
                ->whereNull('delivery_cash_settlement_id')
                ->lockForUpdate()
                ->get(['id', 'total_amount']);

            if ($parcels->isEmpty()) {
                return null;
            }

            $settlement = DeliveryCashSettlement::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'settled_by_user_id' => $request->user()->id,
                'amount' => round((float) $parcels->sum('total_amount'), 2),
                'parcels_count' => $parcels->count(),
                'note' => $data['note'] ?? null,
            ]);

            Order::whereIn('id', $parcels->pluck('id'))->update(['delivery_cash_settlement_id' => $settlement->id]);

            return $settlement;
        });

        Inertia::flash('toast', $settlement === null
            ? ['type' => 'info', 'message' => __(':name has no cash to hand over.', ['name' => $user->name])]
            : ['type' => 'success', 'message' => __('Cash settled: :amount MAD for :count parcels.', [
                'amount' => number_format((float) $settlement->amount, 2, '.', ','),
                'count' => $settlement->parcels_count,
            ])]);

        return back(fallback: route('users.index'));
    }
}
