<!DOCTYPE html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.6;">
    <h2>Bienvenue sur MboaCar, {{ $user->name }} !</h2>
    <p>Votre espace garage <strong>{{ $tenant->name }}</strong> a été créé avec succès.</p>
    <p>Vous bénéficiez de <strong>7 jours d'essai gratuit</strong> avec accès à toutes les fonctionnalités (sans carte bancaire), jusqu'au {{ $tenant->trial_ends_at?->format('d/m/Y') }}.</p>
    <p>Accédez à votre garage à tout moment via :</p>
    <p><a href="{{ $tenantUrl }}">{{ $tenantUrl }}</a></p>
    <p>À très bientôt,<br>L'équipe MboaCar</p>
</body>
</html>
