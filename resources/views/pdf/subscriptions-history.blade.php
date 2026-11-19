<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Historique des abonnements — {{ $tenant->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
        h1 { font-size: 18px; color: #0f172a; margin-bottom: 4px; }
        .meta { color: #64748b; margin-bottom: 16px; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 7px 8px; text-align: left; }
        th { background-color: #f1f5f9; font-weight: bold; }
        .badge { padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .ok { background: #dcfce7; color: #166534; }
        .ko { background: #fee2e2; color: #991b1b; }
        .wait { background: #fef9c3; color: #854d0e; }
    </style>
</head>
<body>
    <h1>Historique des souscriptions — {{ $tenant->name }}</h1>
    <div class="meta">
        Statut actuel : <strong>{{ strtoupper($tenant->status) }}</strong> •
        Généré le {{ now()->format('d/m/Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Référence</th>
                <th>Méthode</th>
                <th>Montant</th>
                <th>Statut</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $p)
            <tr>
                <td>{{ $p->reference_code }}</td>
                <td>{{ $p->payment_method ?: $p->provider }}</td>
                <td>{{ number_format($p->amount, 0, ',', ' ') }} {{ $p->currency }}</td>
                <td>
                    <span class="badge {{ $p->status === 'success' ? 'ok' : ($p->status === 'failed' ? 'ko' : 'wait') }}">
                        {{ $p->status }}
                    </span>
                </td>
                <td>{{ $p->created_at?->format('d/m/Y H:i') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center;">Aucune souscription enregistrée.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
