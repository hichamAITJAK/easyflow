<?php

namespace App\Http\Controllers\Fulfillment;

use App\Http\Controllers\Api\Mobile\FulfillmentController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The browser-side entry point for the fulfilment workspace.
 *
 * Every data action delegates straight to the mobile
 * {@see FulfillmentController}: the scan rules (which delivery_status
 * permits which action, the 5-minute undo window, the append-only audit
 * reads) are decided in exactly one place, so the phone app and the web
 * workspace can never drift apart on what a scan is allowed to do. This
 * class only owns what differs between the two clients — session auth
 * instead of Sanctum, and rendering the Inertia shell.
 *
 * The JSON endpoints are kept as XHR rather than Inertia visits on
 * purpose: a scan/confirm round trip happens every few seconds in a
 * warehouse, and a full Inertia page reload per parcel would throw away
 * the live camera stream between every scan.
 */
class FulfillmentWebController extends Controller
{
    public function __construct(private readonly FulfillmentController $fulfillment) {}

    /**
     * The workspace shell. Counters are passed in on the initial render so
     * the agent sees their workload immediately instead of a flash of
     * zeroes while an XHR resolves.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('fulfillment/index', [
            'summary' => $this->fulfillment->summary($request)->getData(true),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->fulfillment->summary($request);
    }

    public function scan(Request $request): JsonResponse
    {
        return $this->fulfillment->scan($request);
    }

    public function confirm(Request $request): JsonResponse
    {
        return $this->fulfillment->confirm($request);
    }

    public function activity(Request $request): JsonResponse
    {
        return $this->fulfillment->activity($request);
    }

    public function undo(Request $request): JsonResponse
    {
        return $this->fulfillment->undo($request);
    }
}
