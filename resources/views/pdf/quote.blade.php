<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Devis {{ $quote->quote_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background: #f3f4f6; }
        .text-right { text-align: right; }
        .header { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>{{ $tenant->name }} — {{ $quote->agency?->name }}</h2>
        <p><strong>Devis :</strong> {{ $quote->quote_number }} (v{{ $quote->version }})</p>
        <p><strong>Client :</strong> {{ $quote->customer?->name }} ({{ $quote->customer?->phone }})</p>
        <p><strong>Véhicule :</strong> {{ $quote->vehicle?->brand }} {{ $quote->vehicle?->model }} — {{ $quote->vehicle?->license_plate }}</p>
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
            <tr>
                <td>{{ $item->description }}</td>
                <td>{{ $item->type }}</td>
                <td>{{ $item->urgency }}</td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($item->total_price, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p class="text-right"><strong>Sous-total HT :</strong> {{ number_format($quote->subtotal_ht, 0, ',', ' ') }} FCFA</p>
    <p class="text-right"><strong>TVA ({{ $quote->vat_rate }} %) :</strong> {{ number_format($quote->vat_amount, 0, ',', ' ') }} FCFA</p>
    <p class="text-right"><strong>Total TTC :</strong> {{ number_format($quote->total_ttc, 0, ',', ' ') }} FCFA</p>
</body>
</html>
