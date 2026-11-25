<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Attestation Police {{ $policy->policy_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; font-size: 12px; margin: 24px; }
        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; margin-bottom: 18px; }
        .title { font-size: 18px; font-weight: bold; color: #1d4ed8; margin: 0; }
        .muted { color: #64748b; font-size: 11px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-weight: bold; font-size: 11px; color: #fff; background: #16a34a; }
        .badge-expired { background: #dc2626; }
        .badge-cancelled { background: #475569; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
        th { background: #f1f5f9; width: 32%; font-weight: bold; }
        .footer { margin-top: 32px; border-top: 1px solid #cbd5e1; padding-top: 10px; font-size: 10px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">ATTESTATION DE POLICE D'ASSURANCE AUTOMOBILE</p>
        <p class="muted">{{ $policy->insurer_name ?: ($tenant?->name ?? 'MboaCar Assurance') }} {{ $tenant?->city ? '— '.$tenant->city : '' }} {{ $tenant?->phone ? '• Tél : '.$tenant->phone : '' }}</p>
    </div>

    <p>
        <strong>N° Police :</strong> {{ $policy->policy_number }} &nbsp;&nbsp;
        <span class="badge {{ $policy->status === 'expiree' ? 'badge-expired' : ($policy->status === 'resiliee' ? 'badge-cancelled' : '') }}">
            {{ strtoupper($policy->status === 'active' ? 'ACTIVE' : ($policy->status === 'expiree' ? 'EXPIRÉE' : 'RÉSILIÉE')) }}
        </span>
    </p>

    <table>
        <tr>
            <th>Assuré (Client)</th>
            <td>{{ $policy->customer?->name ?? '-' }} {{ $policy->customer?->phone ? '('.$policy->customer->phone.')' : '' }}</td>
        </tr>
        <tr>
            <th>Véhicule assuré</th>
            <td><strong>{{ $policy->vehicle?->license_plate ?? '-' }}</strong> — {{ $policy->vehicle?->brand }} {{ $policy->vehicle?->model }} {{ $policy->vehicle?->vin ? '(VIN : '.$policy->vehicle->vin.')' : '' }}</td>
        </tr>
        <tr>
            <th>Formule de couverture</th>
            <td>{{ $policy->coverage_type === 'tous_risques' ? 'Tous Risques' : ($policy->coverage_type === 'tiers_plus' ? 'Tiers étendu' : 'Tiers simple') }}</td>
        </tr>
        <tr>
            <th>Période de validité</th>
            <td>Du <strong>{{ $policy->starts_at?->format('d/m/Y') }}</strong> au <strong>{{ $policy->expires_at?->format('d/m/Y') }}</strong></td>
        </tr>
        <tr>
            <th>Prime d'assurance</th>
            <td><strong>{{ number_format($policy->premium_amount, 0, ',', ' ') }} FCFA</strong></td>
        </tr>
        <tr>
            <th>Franchise contractuelle</th>
            <td>{{ number_format($policy->deductible_amount, 0, ',', ' ') }} FCFA</td>
        </tr>
        @if($policy->notes)
        <tr>
            <th>Observations</th>
            <td>{{ $policy->notes }}</td>
        </tr>
        @endif
    </table>

    <div class="footer">
        Document généré le {{ now()->format('d/m/Y à H:i') }} via MboaCar — {{ $tenant?->name }}
    </div>
</body>
</html>
