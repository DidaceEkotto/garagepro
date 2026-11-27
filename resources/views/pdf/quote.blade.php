<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Devis {{ $quote->quote_number }} (v{{ $quote->version }})</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1e293b; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        th { background: #f1f5f9; font-weight: bold; color: #0f172a; }
        .text-right { text-align: right; }
        .header { margin-bottom: 18px; border-bottom: 2px solid #0f172a; padding-bottom: 10px; }
        .header h2 { margin: 0 0 6px; color: #0f172a; }
        .header p { margin: 2px 0; }
        .declined-row { color: #94a3b8; text-decoration: line-through; background: #f8fafc; }
        .totals { margin-top: 14px; text-align: right; }
        .totals p { margin: 3px 0; }
        .totals .grand { font-size: 14px; font-weight: bold; color: #1d4ed8; margin-top: 6px; }
        .signature-box {
            margin-top: 24px;
            border: 1.5px solid #16a34a;
            background: #f0fdf4;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .signature-title { font-weight: bold; color: #15803d; margin-bottom: 6px; font-size: 12px; }
        .signature-meta { font-size: 10.5px; color: #334155; margin: 2px 0; }
        .signature-img {
            margin-top: 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            padding: 4px 8px;
            max-height: 76px;
            max-width: 240px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>{{ $tenant->name }} @if($quote->agency?->name) — {{ $quote->agency->name }} @endif</h2>
        <p><strong>Devis :</strong> {{ $quote->quote_number }} (Version v{{ $quote->version }})</p>
        <p><strong>Client :</strong> {{ trim(($quote->customer?->first_name ? $quote->customer->first_name.' ' : '').($quote->customer?->name ?? '')) }} ({{ $quote->customer?->whatsapp_phone ?: $quote->customer?->phone }})</p>
        <p><strong>Véhicule :</strong> {{ $quote->vehicle?->brand }} {{ $quote->vehicle?->model }} — {{ $quote->vehicle?->license_plate }}</p>
        @if($quote->valid_until)
            <p><strong>Valable jusqu'au :</strong> {{ $quote->valid_until->format('d/m/Y') }}</p>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Type</th>
                <th>Niveau</th>
                <th class="text-right">Qté</th>
                <th class="text-right">PU (FCFA)</th>
                <th class="text-right">Total (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quote->items as $item)
            <tr class="{{ $item->is_accepted === false ? 'declined-row' : '' }}">
                <td>
                    {{ $item->description }}
                    @if($item->is_accepted === false)
                        <em>(Non retenu par le client)</em>
                    @endif
                </td>
                <td>{{ $item->type }}</td>
                <td>{{ $item->urgency }}</td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($item->total_price, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <p><strong>Sous-total HT :</strong> {{ number_format($quote->subtotal_ht, 0, ',', ' ') }} FCFA</p>
        @if((int) $quote->discount_amount > 0)
            <p><strong>Remise :</strong> - {{ number_format($quote->discount_amount, 0, ',', ' ') }} FCFA</p>
        @endif
        <p><strong>TVA ({{ $quote->vat_rate }} %) :</strong> {{ number_format($quote->vat_amount, 0, ',', ' ') }} FCFA</p>
        <p class="grand">Total TTC : {{ number_format($quote->total_ttc, 0, ',', ' ') }} FCFA</p>
    </div>

    @if($quote->notes)
        <p style="margin-top: 12px;"><strong>Notes :</strong> {{ $quote->notes }}</p>
    @endif

    @if($quote->signed_at)
        <div class="signature-box">
            <div class="signature-title">
                BON POUR ACCORD — {{ $quote->status === \App\Models\Quote::STATUS_PARTIALLY_ACCEPTED ? 'VALIDATION PARTIELLE' : 'VALIDÉ' }} &amp; SIGNÉ ÉLECTRONIQUEMENT
            </div>
            <p class="signature-meta">
                <strong>Signataire :</strong> {{ $quote->signed_by_name ?: trim(($quote->customer?->first_name ? $quote->customer->first_name.' ' : '').($quote->customer?->name ?? 'Client')) }}
            </p>
            <p class="signature-meta">
                <strong>Date et heure de validation :</strong> {{ $quote->signed_at->format('d/m/Y à H:i') }}
                @if($quote->signed_ip)
                    — <strong>IP :</strong> {{ $quote->signed_ip }}
                @endif
            </p>
            @if(!empty($signatureDataUri))
                <img src="{{ $signatureDataUri }}" alt="Signature manuscrite client" class="signature-img">
            @endif
        </div>
    @endif
</body>
</html>
