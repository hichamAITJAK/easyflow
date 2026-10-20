<?php

namespace App\Http\Controllers\Delivery;

use App\Enums\OrderDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The delivery guy's workspace: the parcels handed to him, in his city.
 * Read-only for now; marking them delivered / failed comes with the
 * delivery space proper.
 */
class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $parcels = Order::where('business_id', $user->business_id)
            ->where('delivery_guy_id', $user->id)
            ->whereIn('delivery_status', [
                OrderDeliveryStatus::AWAITING_PICKUP,
                OrderDeliveryStatus::READY_FOR_PICKUP,
                OrderDeliveryStatus::OUT_FOR_DELIVERY,
                OrderDeliveryStatus::POSTPONED,
                OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            ])
            ->orderByRaw("case when delivery_status = 'out_for_delivery' then 0 when delivery_status = 'ready_for_pickup' then 1 else 2 end")
            ->orderBy('shipped_at')
            ->get(['id', 'reference', 'courier_tracking_number', 'customer_name', 'customer_phone', 'customer_address', 'customer_city', 'total_amount', 'delivery_status', 'parcel_note', 'shipped_at'])
            ->each->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return Inertia::render('delivery/index', [
            'city' => $user->delivery_city,
            'parcels' => $parcels,
            'deliveredToday' => Order::where('business_id', $user->business_id)
                ->where('delivery_guy_id', $user->id)
                ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
                ->whereDate('updated_at', today())
                ->count(),
        ]);
    }
}
