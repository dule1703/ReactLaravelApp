{{--
    PDF of an offer (dompdf). Everything comes from OfferPresenter::detail(), that is from the SNAPSHOT
    stored in the offer: never the live client profile or the catalog, and never a JMBG. Amounts are the
    stored columns (integer cents, formatted by Money::format); an unknown amount is "-".
    dompdf: no JavaScript, no remote files (the logo is a data URI), font "DejaVu Sans" (has ć č đ š ž).
--}}
@php
    use App\Support\Money;

    $money = fn (?int $cents): string => $cents === null ? '-' : Money::format($cents);
    // A value the catalog may add later (new fuel type, new drive) keeps working: the raw value is the fallback.
    $label = function (string $prefix, ?string $value): string {
        $text = __($prefix.'.'.$value);

        return $text === $prefix.'.'.$value ? (string) $value : $text;
    };
    $rate = Money::formatPercentBp($offer['vat_rate_bp']);
    $client = $offer['client'];
@endphp
<!DOCTYPE html>
<html lang="sr-Latn">
<head>
    <meta charset="utf-8">
    <title>{{ __('Offer :number', ['number' => $offer['number']]) }}</title>
    <style>
        @page { margin: 18mm 15mm 18mm 15mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #1B1F1D; }
        h1 { font-size: 15pt; margin: 0 0 2mm 0; }
        h2 { font-size: 10pt; margin: 6mm 0 2mm 0; padding-bottom: 1mm; border-bottom: 0.4mm solid #4BA82E; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .head td { vertical-align: middle; }
        .meta td { padding: 0.6mm 0; }
        .meta td.k { width: 32mm; color: #6b7280; }
        .items th { background: #F4F6F5; text-align: left; font-size: 8pt; padding: 1.6mm 2mm; }
        .items td { padding: 1.6mm 2mm; border-bottom: 0.2mm solid #d1d5db; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .option td { padding: 0.6mm 2mm 0.6mm 6mm; border: 0; font-size: 8pt; }
        .tag { font-size: 7pt; color: #6b7280; }
        .totals { width: 70mm; margin-left: auto; margin-top: 4mm; }
        .totals td { padding: 1mm 0; }
        .totals .grand td { border-top: 0.4mm solid #1B1F1D; font-weight: bold; font-size: 11pt; padding-top: 2mm; }
        .note { white-space: pre-line; }
        .withdrawn { margin: 0 0 4mm 0; padding: 2mm 3mm; border: 0.6mm solid #b91c1c; color: #b91c1c; text-align: center; font-weight: bold; font-size: 14pt; }
        .withdrawn small { display: block; font-size: 8pt; font-weight: normal; }
    </style>
</head>
<body>
    @if (filled($offer['withdrawn_at']))
        <div class="withdrawn">
            {{ __('WITHDRAWN') }}
            <small>{{ __('Withdrawn on :date', ['date' => $offer['withdrawn_at']]) }}</small>
        </div>
    @endif

    <table class="head">
        <tr>
            <td style="width: 24mm;">
                @if ($logo)
                    <img src="{{ $logo }}" alt="" style="height: 16mm;">
                @endif
            </td>
            <td>
                <strong style="font-size: 12pt;">{{ __('Škoda Configurator') }}</strong>
            </td>
            <td class="right">
                <h1>{{ __('Offer :number', ['number' => $offer['number']]) }}</h1>
                <span class="muted">{{ __('Date') }}: {{ $offer['offer_date'] }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ __('Client') }}</h2>
    <table class="meta">
        <tr><td class="k">{{ __('Full name or company name') }}</td><td>{{ $client['name'] ?: '-' }}</td></tr>
        @if (filled($client['pib']))
            <tr><td class="k">PIB</td><td>{{ $client['pib'] }}</td></tr>
        @endif
        <tr><td class="k">{{ __('Address') }}</td><td>{{ $client['address'] ?: '-' }}</td></tr>
        <tr><td class="k">{{ __('City') }}</td><td>{{ trim(($client['postal_code'] ?? '').' '.($client['city'] ?? '')) ?: '-' }}</td></tr>
        <tr><td class="k">{{ __('Country') }}</td><td>{{ $client['country'] ?: '-' }}</td></tr>
    </table>

    <h2>{{ __('Items') }}</h2>
    @if (count($offer['items']) === 0)
        <p class="muted">{{ __('This offer has no items.') }}</p>
    @else
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 6mm;">#</th>
                    <th>{{ __('Version') }}</th>
                    <th class="right" style="width: 12mm;">{{ __('Quantity') }}</th>
                    <th class="right" style="width: 28mm;">{{ __('Price of one vehicle') }}</th>
                    <th class="right" style="width: 30mm;">{{ __('Line without VAT') }}</th>
                </tr>
            </thead>
            @foreach ($offer['items'] as $item)
                <tbody>
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>
                            <strong>{{ $item['car_model_name'] }} {{ $item['trim_name'] }}</strong><br>
                            <span class="muted">{{ $item['engine_name'] }}, {{ $item['power_kw'] }} kW, {{ $label('fuel', $item['fuel_type']) }}, {{ $item['transmission_name'] }}, {{ $label('drive', $item['drive']) }}</span>
                        </td>
                        <td class="right">{{ $item['quantity'] }}</td>
                        <td class="right nowrap">{{ $money($item['version_price_cents']) }}</td>
                        <td class="right nowrap"><strong>{{ $money($item['line_net_cents']) }}</strong></td>
                    </tr>
                    @foreach ($item['options'] as $option)
                        @php
                            $tags = array_filter([
                                $label('equipment.category', $option['category']),
                                $option['group_name'],
                                $option['is_surcharge'] ? __('surcharge') : null,
                            ], fn ($tag) => filled($tag));
                        @endphp
                        <tr class="option">
                            <td></td>
                            <td colspan="3">
                                {{ $option['name'] }}
                                <span class="tag">({{ implode(', ', $tags) }})</span>
                            </td>
                            <td class="right nowrap">+ {{ $money($option['price_cents']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    @endif

    <table class="totals">
        <tr><td>{{ __('Total without VAT') }}</td><td class="right nowrap">{{ $money($offer['total_net_cents']) }}</td></tr>
        <tr><td>{{ __('VAT :rate%', ['rate' => $rate]) }}</td><td class="right nowrap">{{ $money($offer['vat_cents']) }}</td></tr>
        <tr class="grand"><td>{{ __('TOTAL OFFER') }}</td><td class="right nowrap">{{ $money($offer['total_gross_cents']) }}</td></tr>
    </table>

    @if (filled($offer['note']))
        <h2>{{ __('Note') }}</h2>
        <p class="note">{{ $offer['note'] }}</p>
    @endif
</body>
</html>
