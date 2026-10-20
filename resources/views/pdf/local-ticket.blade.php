{{--
    Own-delivery parcel label, one 100 × 100 mm page per parcel, rendered
    by dompdf: tables and plain CSS only (no flex/grid). Mirrors the
    courier labels the warehouse already sticks on parcels, so nothing
    changes in how a parcel is read: QR + barcode of the tracking number,
    who and where, the goods, the cash to collect.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Tickets</title>
    <style>
        @page { margin: 3mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #000; margin: 0; }
        .ticket { page-break-after: always; }
        .ticket:last-child { page-break-after: auto; }
        table { border-collapse: collapse; width: 100%; }
        td { vertical-align: top; padding: 0; }
        .box { border: 1.2px solid #000; }
        .cell { border: 1px solid #000; padding: 2px 3px; }
        .label { font-weight: bold; width: 26%; }
        .qr { width: 22mm; text-align: center; }
        .qr img { width: 20mm; height: 20mm; }
        .logo { text-align: center; }
        .logo img { max-width: 30mm; max-height: 8mm; }
        .site { font-size: 8px; text-align: center; }
        .date { font-weight: bold; font-size: 9px; text-align: center; margin-top: 2px; }
        .depart { font-weight: bold; text-align: center; font-size: 9px; }
        .ref { font-weight: bold; font-size: 9.5px; text-align: center; padding: 3px 2px; }
        .checks td { border: 1px solid #000; height: 4.2mm; width: 25%; font-size: 7px; padding-left: 2px; }
        .hub { font-weight: bold; text-align: center; font-size: 8.5px; padding: 3px 2px; }
        .phone { font-weight: bold; font-size: 10px; }
        .track { font-weight: bold; font-size: 13px; text-align: center; letter-spacing: 0.5px; margin: 1.5mm 0 0.5mm; }
        .barcode { text-align: center; }
        .barcode img { height: 10mm; width: 70mm; }
        .cod { font-weight: bold; font-size: 15px; text-align: center; padding: 2px; }
        .flags td { width: 50%; text-align: center; font-weight: bold; font-size: 9px; padding: 2px; }
        .guy { text-align: center; font-size: 8px; margin-top: 1mm; }
    </style>
</head>
<body>
@foreach ($tickets as $ticket)
    @php($order = $ticket['order'])
    @php($city = strtoupper((string) ($order->deliveryGuy?->delivery_city ?: $order->customer_city)))
    <div class="ticket">
        <table class="box head">
            <tr>
                <td class="qr cell"><img src="{{ $ticket['qr'] }}" alt=""></td>
                <td style="padding: 0;">
                    <table>
                        <tr>
                            <td class="cell logo" style="width: 50%; height: 11mm;"><img src="{{ $logo }}" alt=""></td>
                            <td class="cell" style="height: 11mm;">
                                <div class="site">{{ $business?->name }}</div>
                                <div style="border-top: 1px dashed #000; margin: 2px 0;"></div>
                                <div class="date">{{ now()->format('Y-m-d H:i') }}</div>
                            </td>
                        </tr>
                        <tr>
                            <td class="cell depart" style="height: 9mm;">Départ<br>{{ $business?->city ?: '—' }}</td>
                            <td class="cell" style="height: 9mm;">
                                {{ $order->store?->name ?? $business?->name }}<br>
                                {{ $business?->phone }}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table style="margin-top: 2mm;">
            <tr>
                <td style="width: 31mm; padding-right: 2mm;">
                    <table class="box">
                        <tr><td class="ref" colspan="4">{{ $order->reference }}</td></tr>
                        @foreach (['PR', 'INJ', 'RP', 'AN'] as $row)
                            <tr class="checks">
                                <td>{{ $row }}</td><td></td><td></td><td></td>
                            </tr>
                        @endforeach
                        <tr><td class="hub" colspan="4">HUB {{ $city }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="box">
                        <tr><td class="cell label">Client</td><td class="cell">{{ $order->customer_name }}</td></tr>
                        <tr><td class="cell label">Téléphone</td><td class="cell phone">{{ $order->customer_phone }}</td></tr>
                        <tr><td class="cell label">Ville</td><td class="cell">{{ $order->customer_city }}</td></tr>
                        <tr><td class="cell label">Adresse</td><td class="cell">{{ $order->customer_address }}</td></tr>
                        <tr><td class="cell label">Commentaire</td><td class="cell">{{ $order->parcel_note }}</td></tr>
                        <tr>
                            <td class="cell label">Marchandise</td>
                            <td class="cell">
                                @if (is_array($order->parcel_products) && $order->parcel_products !== [])
                                    {{ collect($order->parcel_products)->map(fn ($line) => "{$line['ref']} ({$line['qnty']})")->implode(', ') }}
                                @else
                                    {{ $order->parcel_nature }}
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class="track">{{ $order->courier_tracking_number }}</div>
        <div class="barcode"><img src="{{ $ticket['barcode'] }}" alt=""></div>

        <table class="box" style="margin-top: 1.5mm;">
            <tr><td class="cod cell" colspan="2">Crbt: {{ number_format((float) $order->total_amount, 0, '.', ' ') }} DH</td></tr>
            <tr class="flags">
                <td class="cell">{{ $order->parcel_open ? "Autorisé d'ouvrir" : "Ne pas ouvrir" }}</td>
                <td class="cell">{{ $order->parcel_fragile ? 'Colis Fragile' : ($order->parcel_replace ? 'Échange' : 'Colis Normal') }}</td>
            </tr>
        </table>

        <div class="guy">Livreur : {{ $order->deliveryGuy?->name }} · {{ $order->deliveryGuy?->delivery_city }}</div>
    </div>
@endforeach
</body>
</html>
