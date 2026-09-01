<?php

namespace App\Http\Controllers\DeliveryCouriers;

use App\Http\Controllers\Concerns\ConnectsDeliveryAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryAccountRequest;
use App\Models\DeliveryCourrier;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DeliveryAccountController extends Controller
{
    use ConnectsDeliveryAccount;

    /**
     * Connect a delivery courier for the store's business.
     */
    public function store(StoreDeliveryAccountRequest $request, Store $store): RedirectResponse
    {
        abort_unless($store->business_id === $request->user()->business_id, 403);

        $data = $request->validated();
        $courier = DeliveryCourrier::findOrFail($data['courier_id']);

        $result = $this->testAndCreateDeliveryAccount($store->business_id, $courier, $data);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery courier connected.')]);

        return to_route('stores.index');
    }
}
