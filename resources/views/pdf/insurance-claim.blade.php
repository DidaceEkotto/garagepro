<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Dossier Sinistre {{ $claim->claim_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; font-size: 12px; margin: 24px; }
        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; margin-bottom: 18px; }
        .title { font-size: 18px; font-weight: bold; color: #1d4ed8; margin: 0; }
        .muted { color: #64748b; font-size: 11px; }
        .box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
        th { background: #f1f5f9; width: 32%; font-weight: bold; }
        .amount { font-size: 14px; font-weight: bold; color: #16a34a; }
        .footer { margin-top: 32px; border-top: 1px solid #cbd5e1; padding-top: 10px; font-size: 10px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">DOSSIER SINISTRE &amp; ACCORD DE PRISE EN CHARGE</p>
        <p class="muted">{{ $claim->insuranceTenant?->name ?? $tenant?->name ?? 'MboaCar Assurance' }} — N° {{ $claim->claim_number }}</p>
    </div>

    <table>
        <tr>
            <th>N° Sinistre / Date incident</th>
            <td><strong>{{ $claim->claim_number }}</strong> — {{ $claim->incident_date?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <th>Statut du dossier</th>
            <td><strong>{{ strtoupper(str_replace('_', ' ', $claim->status)) }}</strong></td>
        </tr>
        <tr>
            <th>Assuré (Client)</th>
            <td>{{ $claim->customer?->name ?? '-' }} {{ $claim->customer?->phone ? '('.$claim->customer->phone.')' : '' }}</td>
        </tr>
        <tr>
            <th>Véhicule concerné</th>
            <td><strong>{{ $claim->vehicle?->license_plate ?? '-' }}</strong> — {{ $claim->vehicle?->brand }} {{ $claim->vehicle?->model }}</td>
        </tr>
        <tr>
            <th>Assureur / Police</th>
            <td>{{ $claim->insuranceTenant?->name ?? $claim->policy?->insurer_name ?? '-' }} {{ $claim->policy ? '• Police N° '.$claim->policy->policy_number : '' }}</td>
        </tr>
        <tr>
            <th>Garage réparateur / OR</th>
            <td>{{ $claim->garageTenant?->name ?? '-' }} {{ $claim->repairOrder ? '• OR '.$claim->repairOrder->order_number : '' }}</td>
        </tr>
        <tr>
            <th>Montant devis estimé</th>
            <td>{{ number_format($claim->estimated_amount, 0, ',', ' ') }} FCFA</td>
        </tr>
        <tr>
            <th>Montant approuvé</th>
            <td class="amount">{{ number_format($claim->approved_amount, 0, ',', ' ') }} FCFA ({{ $claim->insurance_share_percent }}% Assurance)</td>
        </tr>
        <tr>
            <th>Part prise en charge Assurance</th>
            <td><strong>{{ number_format((int) round(($claim->approved_amount * $claim->insurance_share_percent) / 100), 0, ',', ' ') }} FCFA</strong></td>
        </tr>
        <tr>
            <th>Franchise à charge client</th>
            <td><strong>{{ number_format($claim->client_deductible_amount, 0, ',', ' ') }} FCFA</strong></td>
        </tr>
        <tr>
            <th>Expert désigné</th>
            <td>{{ $claim->expert_name ?: '-' }}</td>
        </tr>
    </table>

    <div class="box">
        <strong>Circonstances &amp; dommages déclarés :</strong>
        <p style="margin: 6px 0 0;">{{ $claim->description }}</p>
        @if($claim->expert_notes)
            <hr style="border: 0; border-top: 1px solid #cbd5e1; margin: 10px 0;">
            <strong>Notes d'expertise :</strong>
            <p style="margin: 6px 0 0;">{{ $claim->expert_notes }}</p>
        @endif
    </div>

    <div class="footer">
        Document généré le {{ now()->format('d/m/Y à H:i') }} via MboaCar — {{ $tenant?->name }}
    </div>
</body>
</html>
