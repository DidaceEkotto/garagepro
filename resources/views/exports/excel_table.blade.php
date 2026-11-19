<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th colspan="{{ count($headers) }}" style="background-color:#1e3a8a;color:#ffffff;font-weight:bold;">
                    {{ $title }} — Exporté le {{ now()->format('d/m/Y H:i') }}
                </th>
            </tr>
            <tr>
                @foreach($headers as $header)
                    <th style="background-color:#e2e8f0;font-weight:bold;">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
