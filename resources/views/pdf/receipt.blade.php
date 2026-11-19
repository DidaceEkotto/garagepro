<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu {{ $payment->reference_code }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        .box { border: 1px solid #ddd; padding: 16px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>{{ $tenant->name }} {{ $payment->agency ? '— '.$payment->agency->name : '' }}</h2>
        <h3>REÇU DE PAIEMENT</h3>
        <p><strong>Référence :</strong> {{ $payment->reference_code }}</p>
        <p><strong>Date :</strong> {{ $payment->paid_at?->format('d/m/Y H:i') }}</p>
        <p><strong>Mode de règlement :</strong> {{ $payment->payment_method }}</p>
        <p><strong>Montant encaissé :</strong> {{ number_format($payment->amount, 0, ',', ' ') }} {{ $payment->currency }}</p>
        <p><strong>Statut :</strong> {{ strtoupper($payment->status) }}</p>
    </div>
</body>
</html>
