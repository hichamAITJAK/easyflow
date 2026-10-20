<?php

namespace App\Http\Controllers\Fulfillment;

use App\Enums\OrderDeliveryStatus;
use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Http\Controllers\Controller;
use App\Models\Order;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Own delivery from the warehouse side: the parcels handed to the
 * business's delivery guys, and their labels. The warehouse prints the
 * tickets, sticks them on, scans each parcel out (the ordinary scan — a
 * LOC- tracking number resolves like any courier's) and hands the lot to
 * the guy of that city.
 */
class LocalDeliveryController extends Controller
{
    use ScopesAgentAccess;

    /** Statuses a parcel can be in while it is still the warehouse's business. */
    private const OPEN = [
        OrderDeliveryStatus::AWAITING_PICKUP,
        OrderDeliveryStatus::READY_FOR_PICKUP,
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filter = $request->string('status')->toString();

        $parcels = $this->applyFulfilmentStoreScope(Order::query(), $user)
            ->where('business_id', $user->business_id)
            ->whereNotNull('delivery_guy_id')
            ->when(
                $filter === 'ready',
                fn ($query) => $query->where('delivery_status', OrderDeliveryStatus::READY_FOR_PICKUP),
                fn ($query) => $query->when(
                    $filter !== 'all',
                    fn ($query) => $query->where('delivery_status', OrderDeliveryStatus::AWAITING_PICKUP),
                    fn ($query) => $query->whereIn('delivery_status', [...self::OPEN, OrderDeliveryStatus::OUT_FOR_DELIVERY]),
                ),
            )
            ->with(['deliveryGuy:id,name,delivery_city', 'store:id,name'])
            ->orderBy('customer_city')
            ->orderByDesc('shipped_at')
            ->get(['id', 'reference', 'courier_tracking_number', 'customer_name', 'customer_phone', 'customer_address', 'customer_city', 'total_amount', 'delivery_status', 'delivery_guy_id', 'store_id', 'shipped_at', 'ready_for_pickup_at'])
            ->each->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        $counts = $this->applyFulfilmentStoreScope(Order::query(), $user)
            ->where('business_id', $user->business_id)
            ->whereNotNull('delivery_guy_id')
            ->whereIn('delivery_status', self::OPEN)
            ->toBase()
            ->selectRaw('delivery_status, count(*) as total')
            ->groupBy('delivery_status')
            ->pluck('total', 'delivery_status');

        return Inertia::render('fulfillment/local', [
            'filter' => in_array($filter, ['ready', 'all'], true) ? $filter : 'to_prepare',
            'parcels' => $parcels,
            'counts' => [
                'to_prepare' => (int) ($counts[OrderDeliveryStatus::AWAITING_PICKUP->value] ?? 0),
                'ready' => (int) ($counts[OrderDeliveryStatus::READY_FOR_PICKUP->value] ?? 0),
            ],
        ]);
    }

    /**
     * The labels for the chosen parcels, one 100 × 100 mm page each, in
     * the courier-label layout the warehouse already knows: QR and
     * barcode of the tracking number, who it is for and where, the goods,
     * the cash to collect, and the delivery guy's name.
     */
    public function tickets(Request $request): HttpResponse
    {
        $user = $request->user();
        $ids = collect(explode(',', $request->string('ids')->toString()))
            ->filter(fn (string $id) => ctype_digit($id))
            ->map(fn (string $id) => (int) $id)
            ->unique()
            ->take(100);

        $orders = $this->applyFulfilmentStoreScope(Order::query(), $user)
            ->where('business_id', $user->business_id)
            ->whereNotNull('delivery_guy_id')
            ->whereIn('id', $ids)
            ->with(['deliveryGuy:id,name,delivery_city', 'store:id,name', 'business:id,name,phone,city,logo'])
            ->orderBy('customer_city')
            ->get()
            ->each->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        abort_if($orders->isEmpty(), 404);

        $qr = new Writer(new ImageRenderer(new RendererStyle(180, 0), new SvgImageBackEnd));
        $barcode = new BarcodeGeneratorSVG;

        $tickets = $orders->map(fn (Order $order) => [
            'order' => $order,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($qr->writeString((string) $order->courier_tracking_number)),
            'barcode' => 'data:image/svg+xml;base64,'.base64_encode($barcode->getBarcode((string) $order->courier_tracking_number, $barcode::TYPE_CODE_128, 2, 60)),
        ]);

        $logoPath = $this->logoPath($orders->first()->business?->getRawOriginal('logo'));

        $pdf = Pdf::loadView('pdf.local-ticket', [
            'tickets' => $tickets,
            'business' => $orders->first()->business,
            'logo' => $logoPath,
        ])->setPaper([0, 0, 283.46, 283.46]); // 100 × 100 mm in points

        $name = $orders->count() === 1
            ? "ticket-{$orders->first()->courier_tracking_number}.pdf"
            : 'tickets-'.now()->format('Ymd-His').'.pdf';

        return $pdf->stream($name);
    }

    /**
     * The business's own logo when it uploaded one, else the platform
     * wordmark, as a file path dompdf can embed.
     */
    private function logoPath(?string $stored): string
    {
        if ($stored !== null && is_file(public_path('storage/'.$stored))) {
            return public_path('storage/'.$stored);
        }

        return public_path('assets/images/logo_wordmark.png');
    }
}
