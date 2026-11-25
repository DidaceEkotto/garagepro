<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu de paiement {{ $payment->reference_code }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #0f172a; margin: 24px; background: #f8fafc; }
        .receipt-box { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 28px; }
        .header { display: flex; justify-content: space-between; align-items: start; border-bottom: 2px solid #2563eb; padding-bottom: 14px; margin-bottom: 18px; }
        .row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
        .total { font-size: 20px; font-weight: bold; color: #16a34a; border-top: 1px dashed #cbd5e1; padding-top: 14px; margin-top: 14px; }
        .actions { text-align: center; margin-top: 22px; }
        .btn { background: #2563eb; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        @media print {
            body { background: #fff; margin: 0; }
            .receipt-box { border: none; box-shadow: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-box">
        <div class="header">
            <div>
                <h2 style="margin:0;">{{ $tenant?->name ?? 'MboaCar' }}</h2>
                <div style="font-size:12px;color:#64748b;">{{ $payment->agency?->name ?? 'Agence principale' }}</div>
            </div>
            <div style="text-align:right;">
                <strong>REÇU DE PAIEMENT</strong><br>
                <span style="font-size:12px;color:#64748b;">Réf : {{ $payment->reference_code }}</span>
            </div>
        </div>

        <div class="row"><span>Date :</span><strong>{{ ($payment->paid_at ?? $payment->created_at)?->format('d/m/Y H:i') }}</strong></div>
        <div class="row"><span>Mode de règlement :</span><strong>{{ strtoupper($payment->payment_method) }}</strong></div>
        <div class="row"><span>Statut :</span><strong>{{ strtoupper($payment->status) }}</strong></div>
        <div class="row total"><span>Montant payé :</span><span>{{ number_format($payment->amount, 0, ',', ' ') }} {{ $payment->currency }}</span></div>

        <div class="actions">
            <button type="button" class="btn" onclick="window.print()">Imprimer ce reçu</button>
        </div>
    </div>
</body>
</html>
