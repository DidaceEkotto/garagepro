<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Code de vérification MboaCar</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; background-color: #f8fafc; padding: 24px; color: #0f172a; margin: 0;">
    @php
        $site = \App\Models\PlatformSetting::siteSettings();
        $siteName = !empty($site['name']) && $site['name'] !== 'GaragePro' ? $site['name'] : 'MboaCar';
        $rawLogo = $site['logo_url'] ?? '/assets/images/logo.png';
        $logoUrl = str_starts_with($rawLogo, 'http://') || str_starts_with($rawLogo, 'https://') ? $rawLogo : url($rawLogo);
    @endphp
    <div style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
        <div style="background: #0f172a; padding: 18px 24px; text-align: center;">
            <img src="{{ $logoUrl }}" alt="{{ $siteName }}" style="height: 42px; max-width: 200px; object-fit: contain; background: #ffffff; padding: 6px 12px; border-radius: 8px;">
        </div>
        <div style="padding: 28px;">
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
    </div>
</body>
</html>
