<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Balance SYSCOHADA — {{ $tenant->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 5px; text-align: left; }
        th { background: #f3f4f6; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <h2>{{ $tenant->name }} — Balance Générale SYSCOHADA</h2>
    <table>
        <thead>
            <tr>
                <th>Compte</th>
                <th>Intitulé</th>
                <th class="text-right">Débit (FCFA)</th>
                <th class="text-right">Crédit (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($balance as $row)
            <tr>
                <td>{{ $row->code }}</td>
                <td>{{ $row->name }}</td>
                <td class="text-right">{{ number_format($row->total_debit, 0, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($row->total_credit, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
