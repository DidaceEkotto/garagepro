<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Réinitialisation de mot de passe — {{ $siteName }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; background-color: #f1f5f9; padding: 24px; color: #0f172a; margin: 0;">
    <div style="max-width: 540px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);">
        <div style="background: #0f172a; padding: 20px 28px; text-align: center;">
            <img src="{{ $logoUrl }}" alt="{{ $siteName }}" style="height: 44px; max-width: 200px; object-fit: contain; background: #ffffff; padding: 6px 12px; border-radius: 8px;">
        </div>

        <div style="padding: 28px;">
            <h2 style="margin-top: 0; margin-bottom: 12px; color: #1d4ed8; font-size: 20px;">
                Réinitialisation de votre mot de passe
            </h2>
            <p style="margin: 0 0 12px; font-size: 14px; line-height: 1.6;">
                Bonjour <strong>{{ $ownerName }}</strong>,
            </p>
            <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">
                Une demande de réinitialisation de mot de passe a été effectuée pour votre compte propriétaire <strong>{{ $garageName }}</strong> sur {{ $siteName }}. Voici votre code de vérification à usage unique :
            </p>

            <div style="font-size: 30px; font-weight: bold; letter-spacing: 8px; text-align: center; background: #eff6ff; color: #1d4ed8; border: 1px dashed #93c5fd; padding: 16px; border-radius: 10px; margin: 20px 0;">
                {{ $code }}
            </div>

            <p style="font-size: 13px; color: #475569; margin: 0 0 16px;">
                Ce code est strictement personnel et valable pendant <strong>5 minutes</strong> (3 tentatives maximum).
            </p>

            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; font-size: 12px; color: #78350f; line-height: 1.5;">
                <strong>Protection anti-phishing :</strong><br>
                • Ne partagez <strong>jamais</strong> ce code avec qui que ce soit (même un membre du support).<br>
                • Vérifiez toujours que l'adresse de votre navigateur se termine par <strong>{{ $officialDomain }}</strong> en HTTPS.<br>
                • Demande enregistrée le {{ $requestedAt }}@if($maskedIp) (IP : {{ $maskedIp }})@endif.<br>
                • Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet e-mail : votre mot de passe reste inchangé.
            </div>
        </div>

        <div style="background: #f8fafc; padding: 14px 28px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;">
            &copy; {{ date('Y') }} {{ $siteName }} — Sécurité des comptes professionnels
        </div>
    </div>
</body>
</html>
