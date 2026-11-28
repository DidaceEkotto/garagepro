<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Étiquette QR — {{ $vehicle->license_plate }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; padding: 24px; color: #0f172a; }
        .grid { display: flex; flex-wrap: wrap; gap: 24px; justify-content: center; }
        .sticker { background: #fff; border: 2px dashed #2563eb; border-radius: 14px; padding: 18px; width: 320px; text-align: center; }
        .keychain { background: #fff; border: 2px dashed #0f172a; border-radius: 12px; padding: 12px; width: 190px; text-align: center; }
        .plate { font-size: 20px; font-weight: 800; letter-spacing: 1px; margin: 6px 0; }
        .muted { font-size: 12px; color: #64748b; }
        .btn { display: inline-block; margin-bottom: 18px; padding: 8px 18px; background: #2563eb; color: #fff; border-radius: 8px; border: 0; cursor: pointer; font-weight: 600; }
        @media print { .no-print { display: none; } body { background: #fff; padding: 0; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center;">
        <button class="btn" onclick="window.print()">Imprimer les étiquettes QR</button>
    </div>
    <div class="grid">
        <div class="sticker">
            <div style="font-weight: 700; color: #2563eb;">{{ $tenant->name }}</div>
            <div class="muted">Autocollant Pare-Brise — Check-in 3s &amp; Carnet Numérique</div>
            <div style="margin: 14px 0;">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={{ urlencode($scanUrl) }}" alt="QR Code" width="180" height="180" />
            </div>
            <div class="plate">{{ $vehicle->license_plate }}</div>
            <div class="muted">{{ $vehicle->brand }} {{ $vehicle->model }} {{ $vehicle->year ? "({$vehicle->year})" : '' }}</div>
            <div class="muted" style="margin-top: 6px;">Scannez pour l'historique d'entretien ou la prise de RDV</div>
        </div>

        <div class="keychain">
            <div style="font-weight: 700; font-size: 13px;">{{ $tenant->name }}</div>
            <div class="muted" style="font-size: 10px;">Porte-Clés Atelier</div>
            <div style="margin: 10px 0;">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data={{ urlencode($scanUrl) }}" alt="QR Code" width="120" height="120" />
            </div>
            <div style="font-weight: 800; font-size: 15px;">{{ $vehicle->license_plate }}</div>
            <div class="muted" style="font-size: 10px;">{{ $vehicle->brand }} {{ $vehicle->model }}</div>
        </div>
    </div>
</body>
</html>
