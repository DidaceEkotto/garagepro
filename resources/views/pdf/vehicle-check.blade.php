<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Certificat MboaCar Check — {{ $report['vehicle']['license_plate'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0f172a; }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 18px; font-weight: bold; color: #1e3a8a; }
        .box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; margin-bottom: 12px; }
        .alert { background: #fee2e2; border: 1px solid #dc2626; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .ok { background: #dcfce7; border: 1px solid #16a34a; color: #166534; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px; text-align: left; }
        th { background: #f8fafc; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">MboaCar Check — Rapport Historique Véhicule Vérifié</div>
        <div>Généré le {{ $report['generated_at'] }} • Score de confiance : <strong>{{ $report['trust_score'] }} / 100</strong></div>
    </div>

    <div class="box">
        <strong>Véhicule :</strong> {{ $report['vehicle']['brand'] }} {{ $report['vehicle']['model'] }} ({{ $report['vehicle']['year'] ?? '-' }})<br>
        <strong>Immatriculation :</strong> {{ $report['vehicle']['license_plate'] }} &nbsp;|&nbsp; <strong>VIN :</strong> {{ $report['vehicle']['vin'] ?? 'Non renseigné' }}<br>
        <strong>Dernier kilométrage vérifié :</strong> {{ number_format($report['vehicle']['latest_mileage'], 0, ',', ' ') }} km
    </div>

    @if($report['odometer_fraud_detected'])
        <div class="alert">
            <strong>ALERTE FRAUDE COMPTEUR DÉTECTÉE :</strong>
            @foreach($report['fraud_alerts'] as $a)
                <div>• {{ $a }}</div>
            @endforeach
        </div>
    @else
        <div class="ok">
            <strong>Kilométrage cohérent :</strong> aucun recul suspect détecté sur le réseau MboaCar.
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Événement vérifié</th>
                <th>Kilométrage</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['timeline'] as $ev)
                <tr>
                    <td>{{ $ev['date'] }}</td>
                    <td>{{ $ev['label'] }}</td>
                    <td>{{ $ev['mileage'] ? number_format($ev['mileage'], 0, ',', ' ') . ' km' : '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="3">Aucun événement enregistré.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
