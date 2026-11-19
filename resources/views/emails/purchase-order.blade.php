<!DOCTYPE html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.6;">
    <h2>{{ $isReminder ? 'Relance — ' : '' }}Bon de commande {{ $purchaseOrder->po_number }}</h2>
    <p>Bonjour {{ $purchaseOrder->supplier?->name }},</p>
    @if($isReminder)
        <p>Nous vous relançons concernant le bon de commande ci-dessous qui n'a pas encore été réceptionné :</p>
    @else
        <p>Veuillez trouver ci-dessous notre bon de commande :</p>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
        <thead>
            <tr style="background: #f3f4f6;">
                <th style="border: 1px solid #ddd; padding: 6px; text-align: left;">Désignation</th>
                <th style="border: 1px solid #ddd; padding: 6px; text-align: right;">Quantité</th>
                <th style="border: 1px solid #ddd; padding: 6px; text-align: right;">PU (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchaseOrder->items as $item)
            <tr>
                <td style="border: 1px solid #ddd; padding: 6px;">{{ $item->description }}</td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">{{ $item->quantity_ordered }}</td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">{{ number_format($item->unit_cost, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p><strong>Montant total :</strong> {{ number_format($purchaseOrder->total_amount, 0, ',', ' ') }} FCFA</p>
    <p>Cordialement,<br>{{ $purchaseOrder->agency?->name }}</p>
</body>
</html>
