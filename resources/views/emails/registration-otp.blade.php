<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Code de vérification MboaCar</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f8fafc; padding: 24px; color: #0f172a;">
    <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border: 1px solid #e2e8f0;">
        <h2 style="margin-top: 0; color: #1d4ed8;">Vérification de votre inscription</h2>
        <p>Bonjour,</p>
        <p>Voici votre code de confirmation à 5 caractères pour le garage <strong>{{ $garageName }}</strong> :</p>
        <div style="font-size: 28px; font-weight: bold; letter-spacing: 6px; text-align: center; background: #eff6ff; color: #1d4ed8; padding: 14px; border-radius: 8px; margin: 18px 0;">
            {{ $code }}
        </div>
        <p style="font-size: 13px; color: #64748b;">
            Ce code est valable pendant <strong>5 minutes</strong>. Ne le partagez avec personne.
        </p>
    </div>
</body>
</html>
