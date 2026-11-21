<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bulletin de Paie — {{ $payslip->period_month }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
        .header { border-bottom: 2px solid #0d6efd; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 18px; font-weight: bold; color: #0d6efd; }
        .meta { width: 100%; margin-bottom: 16px; }
        .meta td { padding: 4px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.items th, table.items td { border: 1px solid #cbd5e1; padding: 8px; }
        table.items th { background: #f1f5f9; text-align: left; }
        .text-end { text-align: right; }
        .total-row { font-size: 14px; font-weight: bold; background: #e0f2fe; }
        .footer { margin-top: 30px; font-size: 11px; color: #64748b; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ $tenant->name }}</div>
        <div>Bulletin de Paie — Période : <strong>{{ $payslip->period_month }}</strong></div>
    </div>

    <table class="meta">
        <tr>
            <td><strong>Salarié :</strong> {{ $payslip->employee?->full_name }}</td>
            <td><strong>Poste :</strong> {{ $payslip->employee?->role_title ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Statut :</strong> {{ strtoupper($payslip->status) }}</td>
            <td><strong>Mode de paiement :</strong> {{ $payslip->payment_method ?? '-' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Rubrique</th>
                <th class="text-end">Gains (+)</th>
                <th class="text-end">Retenues (-)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Salaire de base</td>
                <td class="text-end">{{ number_format($payslip->base_salary, 0, ',', ' ') }} FCFA</td>
                <td class="text-end">-</td>
            </tr>
            <tr>
                <td>Primes &amp; Indemnités</td>
                <td class="text-end">{{ number_format($payslip->primes_total, 0, ',', ' ') }} FCFA</td>
                <td class="text-end">-</td>
            </tr>
            <tr>
                <td>Commissions Atelier / Performance</td>
                <td class="text-end">{{ number_format($payslip->commissions_total, 0, ',', ' ') }} FCFA</td>
                <td class="text-end">-</td>
            </tr>
            <tr>
                <td>Heures supplémentaires</td>
                <td class="text-end">{{ number_format($payslip->overtime_total, 0, ',', ' ') }} FCFA</td>
                <td class="text-end">-</td>
            </tr>
            <tr>
                <td>Retenues retards ({{ $payslip->late_minutes_total }} min)</td>
                <td class="text-end">-</td>
                <td class="text-end">{{ number_format($payslip->late_penalties_total, 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr>
                <td>Avances sur salaire (Acomptes)</td>
                <td class="text-end">-</td>
                <td class="text-end">{{ number_format($payslip->advances_total, 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr class="total-row">
                <td>NET À PAYER</td>
                <td colspan="2" class="text-end">{{ number_format($payslip->net_salary, 0, ',', ' ') }} FCFA</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Document généré automatiquement par MboaCar le {{ now()->format('d/m/Y à H:i') }}.
    </div>
</body>
</html>
