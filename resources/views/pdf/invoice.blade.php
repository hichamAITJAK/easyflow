{{--
    Commission invoice, rendered server-side by dompdf.

    dompdf supports only a narrow slice of CSS — no flexbox, no grid, no
    custom properties — so this deliberately uses tables and inline-ish
    styles rather than reaching for the app's Tailwind build, which would
    not survive the renderer.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 40px 44px; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.5;
            color: #18181b;
            margin: 0;
        }

        .masthead { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
        .masthead td { vertical-align: top; padding: 0; }

        .business { font-size: 15px; font-weight: bold; letter-spacing: -0.01em; }
        .business-detail { font-size: 9px; color: #52525b; margin-top: 2px; }
        .business-ids { font-size: 8px; color: #71717a; margin-top: 4px; }
        .business-logo { max-height: 46px; max-width: 170px; margin-bottom: 6px; }
        .doc-type { font-size: 20px; font-weight: bold; letter-spacing: -0.02em; text-align: right; }
        .doc-number { font-size: 11px; color: #71717a; text-align: right; margin-top: 2px; }

        .meta { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .meta td { vertical-align: top; padding: 0; width: 33.33%; }
        .meta-label {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: #71717a;
            padding-bottom: 3px;
        }
        .meta-value { font-size: 11px; }

        /* Status reads as a word, not a color chip — a printed invoice is
           often photocopied or faxed in this market, where a tinted pill
           degrades to an unreadable gray block. */
        .status { font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
        .status-paid { color: #047857; }
        .status-pending { color: #b45309; }

        .lines { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .lines th {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: #71717a;
            text-align: left;
            font-weight: normal;
            padding: 0 0 6px;
            border-bottom: 1px solid #e4e4e7;
        }
        .lines td { padding: 7px 0; border-bottom: 1px solid #f4f4f5; }
        .lines .amount, .lines th.amount { text-align: right; }
        .reversal { color: #b91c1c; }
        .muted { color: #71717a; }

        .totals { width: 100%; border-collapse: collapse; }
        .totals td { padding: 0; }
        .totals .spacer { width: 60%; }
        .total-row td {
            border-top: 2px solid #18181b;
            padding-top: 8px;
            font-size: 14px;
            font-weight: bold;
        }
        .total-row .amount { text-align: right; }

        .footnote {
            margin-top: 32px;
            padding-top: 10px;
            border-top: 1px solid #e4e4e7;
            font-size: 9px;
            color: #71717a;
        }
    </style>
</head>
<body>
    <table class="masthead">
        <tr>
            <td>
                @php($business = $invoice->business)
                @php($logoPath = $business?->getRawOriginal('logo'))

                {{-- dompdf reads from the filesystem, so the stored path is
                     resolved here rather than through the model's public-URL
                     accessor. --}}
                @if ($logoPath && file_exists(storage_path('app/public/'.$logoPath)))
                    <img class="business-logo" src="{{ storage_path('app/public/'.$logoPath) }}" alt="">
                @endif

                <div class="business">{{ $business?->legal_name ?: ($business?->name ?? config('app.name')) }}</div>

                @if ($business?->address || $business?->city)
                    <div class="business-detail">
                        {{ collect([$business->address, $business->city])->filter()->implode(', ') }}
                    </div>
                @endif

                @if ($business?->phone || $business?->email)
                    <div class="business-detail">
                        {{ collect([$business->phone, $business->email])->filter()->implode(' · ') }}
                    </div>
                @endif

                @php($identifiers = collect([
                    $business?->ice ? 'ICE: '.$business->ice : null,
                    $business?->rc ? 'RC: '.$business->rc : null,
                    $business?->if_number ? 'IF: '.$business->if_number : null,
                ])->filter())

                @if ($identifiers->isNotEmpty())
                    <div class="business-ids">{{ $identifiers->implode('  ·  ') }}</div>
                @endif
            </td>
            <td>
                <div class="doc-type">Invoice</div>
                <div class="doc-number">{{ $invoice->invoice_number }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td>
                <div class="meta-label">Billed to</div>
                <div class="meta-value">{{ $invoice->user?->name ?? 'Unassigned agent' }}</div>
                @if ($invoice->user?->email)
                    <div class="meta-value muted">{{ $invoice->user->email }}</div>
                @endif
            </td>
            <td>
                <div class="meta-label">Period</div>
                <div class="meta-value">
                    {{ $invoice->period_start?->format('d M Y') }} &ndash; {{ $invoice->period_end?->format('d M Y') }}
                </div>
            </td>
            <td>
                <div class="meta-label">Status</div>
                <div class="meta-value status status-{{ $invoice->status === 'paid' ? 'paid' : 'pending' }}">
                    {{ $invoice->status }}
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Order</th>
                <th>Date</th>
                <th>Type</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->ledgerEntries as $entry)
                <tr>
                    {{-- A bonus has no order; its description names the
                         metric and period it was earned over instead. --}}
                    <td>{{ $entry->order?->reference ?? $entry->description ?? "#{$entry->order_id}" }}</td>
                    {{-- CommissionLedgerEntry sets $timestamps = false and
                         casts no dates, so created_at arrives as a raw
                         string; parse before formatting. --}}
                    <td class="muted">
                        {{ $entry->created_at ? \Illuminate\Support\Carbon::parse($entry->created_at)->format('d M Y') : '—' }}
                    </td>
                    <td class="{{ $entry->entry_type === 'reversal' ? 'reversal' : '' }}">
                        {{ match ($entry->entry_type) {
                            'reversal' => 'Reversal',
                            'bonus' => 'Bonus',
                            default => 'Commission',
                        } }}
                    </td>
                    <td class="amount {{ $entry->entry_type === 'reversal' ? 'reversal' : '' }}">
                        {{ number_format((float) $entry->amount, 2) }}
                    </td>
                </tr>
            @empty
                {{-- An invoice with no surviving line items is a data problem
                     worth showing plainly rather than rendering as a blank
                     table the admin has to interpret. --}}
                <tr>
                    <td colspan="4" class="muted">No line items are attached to this invoice.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr class="total-row">
            <td class="spacer"></td>
            <td>Total</td>
            <td class="amount">{{ number_format((float) $invoice->total_amount, 2) }} MAD</td>
        </tr>
    </table>

    @if ($invoice->notes)
        <div class="footnote">{{ $invoice->notes }}</div>
    @endif

    <div class="footnote">
        Generated {{ now()->format('d M Y') }} · {{ $invoice->ledgerEntries->count() }}
        {{ Str::plural('entry', $invoice->ledgerEntries->count()) }}
    </div>
</body>
</html>
