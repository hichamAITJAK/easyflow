<?php

namespace App\Http\Controllers\DeliveryCouriers;

use App\Http\Controllers\Concerns\ConnectsDeliveryAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryAccountRequest;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryCourrierConnectionController extends Controller
{
    use ConnectsDeliveryAccount;

    /**
     * List the business's existing courier connections.
     */
    public function index(Request $request): Response
    {
        abort_if($request->user()->business_id === null, 403);

        $accounts = DeliveryAccount::with('courier', 'collectCity')
            ->where('business_id', $request->user()->business_id)
            ->get();

        return Inertia::render('delivery-couriers/index', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * List every available courier for the business to connect.
     */
    public function create(Request $request): Response
    {
        abort_if($request->user()->business_id === null, 403);

        return Inertia::render('delivery-couriers/create', [
            'couriers' => DeliveryCourrier::orderBy('name')->get(),
            'citiesByCourier' => DeleveryCourrierCity::orderBy('name')->get()->groupBy('courrier_id'),
            'businessSlug' => $request->user()->business->slug,
        ]);
    }

    /**
     * Connect a delivery courier for the current user's business.
     */
    public function store(StoreDeliveryAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $courier = DeliveryCourrier::findOrFail((int) $data['courier_id']);

        $result = $this->testAndCreateDeliveryAccount($request->user()->business_id, $courier, $data);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return to_route('delivery-couriers.connected', $result);
    }

    /**
     * Show the success page for a newly connected delivery account.
     */
    public function connected(Request $request, DeliveryAccount $deliveryAccount): Response
    {
        abort_unless($deliveryAccount->business_id === $request->user()->business_id, 403);

        return Inertia::render('delivery-couriers/connected', [
            'account' => $deliveryAccount->load('courier', 'collectCity'),
        ]);
    }

    /**
     * Disconnect a delivery account.
     */
    public function destroy(Request $request, DeliveryAccount $deliveryAccount): RedirectResponse
    {
        abort_unless($deliveryAccount->business_id === $request->user()->business_id, 403);

        $deliveryAccount->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery courier disconnected.')]);

        return to_route('delivery-couriers.index');
    }
}
