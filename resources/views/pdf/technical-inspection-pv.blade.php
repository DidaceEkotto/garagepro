<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PV Visite Technique {{ $pv->pv_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; font-size: 12px; margin: 24px; }
        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; margin-bottom: 16px; }
        .title { font-size: 18px; font-weight: bold; color: #1d4ed8; margin: 0 0 4px 0; }
        .muted { color: #64748b; font-size: 11px; margin: 0; }
        .verdict { display: inline-block; padding: 5px 12px; border-radius: 6px; font-weight: bold; font-size: 12px; color: #fff; margin-top: 8px; }
        .v-ok { background: #16a34a; }
        .v-cv { background: #d97706; }
        .v-immo { background: #dc2626; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 7px 9px; text-align: left; }
        th { background: #f1f5f9; font-weight: bold; }
        .sev-ok { color: #16a34a; font-weight: bold; }
        .sev-mineur { color: #0284c7; font-weight: bold; }
        .sev-majeur { color: #d97706; font-weight: bold; }
        .sev-critique { color: #dc2626; font-weight: bold; }
        .verify-box { margin-top: 18px; background: #f8fafc; border: 1px dashed #94a3b8; border-radius: 6px; padding: 10px 12px; font-size: 11px; }
        .footer { margin-top: 28px; border-top: 1px solid #cbd5e1; padding-top: 10px; font-size: 10px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">PROCÈS-VERBAL DE VISITE TECHNIQUE</p>
        <p class="muted">
            {{ $pv->tenant?->name ?? 'Centre Technique MboaCar' }}
            {{ $pv->agency ? '• '.$pv->agency->name : '' }}
            {{ $pv->tenant?->city ? '• '.$pv->tenant->city : '' }}
            {{ $pv->tenant?->phone ? '• Tél : '.$pv->tenant->phone : '' }}
        </p>
        <div class="verdict {{ $pv->verdict === 'favorable' ? 'v-ok' : ($pv->verdict === 'contre_visite' ? 'v-cv' : 'v-immo') }}">
            VERDICT : {{ $pv->verdict === 'favorable' ? 'FAVORABLE (APTE)' : ($pv->verdict === 'contre_visite' ? 'CONTRE-VISITE REQUISE' : 'IMMOBILISATION (CRITIQUE)') }}
        </div>
    </div>

    <table>
        <tr>
            <th style="width: 28%;">N° Procès-Verbal</th>
            <td><strong>{{ $pv->pv_number }}</strong> @if($pv->parentInspection) (Contre-visite du PV {{ $pv->parentInspection->pv_number }}) @endif</td>
            <th style="width: 24%;">Date du contrôle</th>
            <td>{{ $pv->inspected_at?->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <th>Véhicule</th>
            <td><strong>{{ $pv->vehicle?->license_plate ?? '-' }}</strong> — {{ $pv->vehicle?->brand }} {{ $pv->vehicle?->model }}</td>
            <th>Kilométrage</th>
            <td>{{ $pv->mileage ? number_format($pv->mileage, 0, ',', ' ').' km' : '-' }}</td>
        </tr>
        <tr>
            <th>Propriétaire</th>
            <td>{{ trim(($pv->customer?->name ?? '').' '.($pv->customer?->first_name ?? '')) ?: '-' }}</td>
            <th>Ligne / Contrôleur</th>
            <td>{{ $pv->inspection_line ?: 'Ligne 1' }} {{ $pv->inspector ? '• '.$pv->inspector->name : '' }}</td>
        </tr>
        @if($pv->expires_at || $pv->counter_visit_deadline)
        <tr>
            <th>Validité / Échéance</th>
            <td colspan="3">
                @if($pv->expires_at)
                    <strong>Valable jusqu'au : {{ $pv->expires_at->format('d/m/Y') }}</strong>
                @endif
                @if($pv->counter_visit_deadline)
                    <strong style="color: #d97706;">Date limite de contre-visite : {{ $pv->counter_visit_deadline->format('d/m/Y') }}</strong>
                @endif
            </td>
        </tr>
        @endif
    </table>

    <h3 style="margin-top: 18px; margin-bottom: 6px; font-size: 13px;">Points de contrôle réglementaires</h3>
    <table>
        <thead>
            <tr>
                <th style="width: 20%;">Catégorie</th>
                <th style="width: 42%;">Point contrôlé</th>
                <th style="width: 16%;">Résultat</th>
                <th style="width: 22%;">Observation</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pv->defects ?? [] as $d)
                @php
                    $sev = $d['severity'] ?? 'ok';
                    $sevLabel = match($sev) {
                        'mineur' => 'Défaut mineur',
                        'majeur' => 'Défaut majeur',
                        'critique' => 'Défaut critique',
                        default => 'Conforme',
                    };
                @endphp
                <tr>
                    <td>{{ $d['category'] ?? '-' }}</td>
                    <td>{{ $d['item'] ?? '-' }}</td>
                    <td class="sev-{{ $sev }}">{{ $sevLabel }}</td>
                    <td>{{ $d['note'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($pv->notes)
        <p style="margin-top: 12px;"><strong>Observations générales :</strong> {{ $pv->notes }}</p>
    @endif

    <div class="verify-box">
        <strong>Vérification d'authenticité en ligne :</strong><br>
        Ce procès-verbal officiel est vérifiable à tout moment via l'adresse sécurisée :<br>
        <code>{{ $publicUrl }}</code>
    </div>

    <div class="footer">
        Certificat généré le {{ now()->format('d/m/Y à H:i') }} via MboaCar — {{ $pv->tenant?->name }}
    </div>
</body>
</html>
