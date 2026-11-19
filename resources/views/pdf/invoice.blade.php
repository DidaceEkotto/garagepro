<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background: #f3f4f6; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <h2>{{ $tenant->name }} — {{ $invoice->agency?->name }}</h2>
    <p><strong>{{ strtoupper($invoice->type) }} N° :</strong> {{ $invoice->invoice_number }}</p>
    <p><strong>Date d'émission :</strong> {{ $invoice->issued_at?->format('d/m/Y') }}</p>
    <p><strong>Client :</strong> {{ $invoice->customer?->name }} ({{ $invoice->customer?->phone }})</p>
    <p><strong>Véhicule :</strong> {{ $invoice->vehicle?->brand }} {{ $invoice->vehicle?->model }} — {{ $invoice->vehicle?->license_plate }}</p>

    <table>
        <thead>
            <tr>
                <th>Désignation</th>
                <th>Type</th>
                <th class="text-right">Qté</th>
                <th class="text-right">PU (FCFA)</th>
                <th class="text-right">Total (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td>{{ $item->type }}</td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($item->total_price, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p class="text-right"><strong>Sous-total HT :</strong> {{ number_format($invoice->subtotal_ht, 0, ',', ' ') }} FCFA</p>
    <p class="text-right"><strong>TVA ({{ $invoice->vat_rate }} %) :</strong> {{ number_format($invoice->vat_amount, 0, ',', ' ') }} FCFA</p>
    <p class="text-right"><strong>Total TTC :</strong> {{ number_format($invoice->total_ttc, 0, ',', ' ') }} FCFA</p>
    @if($invoice->deposit_deducted > 0)
        <p class="text-right"><strong>Acompte déduit :</strong> -{{ number_format($invoice->deposit_deducted, 0, ',', ' ') }} FCFA</p>
    @endif
    <p class="text-right"><strong>Net à payer :</strong> {{ number_format($invoice->balanceDue(), 0, ',', ' ') }} FCFA</p>
</body>
</html>
