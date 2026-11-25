<!DOCTYPE html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.6;">
    <h2>Rappel concernant votre essai gratuit MboaCar</h2>
    @if($daysRemaining <= 0)
        <p>Votre essai gratuit pour <strong>{{ $tenant->name }}</strong> arrive à échéance aujourd'hui.</p>
    @else
        <p>Il reste <strong>{{ $daysRemaining }} jour(s)</strong> d'essai gratuit pour <strong>{{ $tenant->name }}</strong>.</p>
    @endif
    <p>Vos données ne seront jamais supprimées. Activez votre abonnement mensuel depuis votre espace pour continuer sans interruption.</p>
    <p>L'équipe MboaCar</p>
</body>
</html>
