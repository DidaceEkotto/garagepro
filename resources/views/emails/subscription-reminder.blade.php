<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Renouvellement d'abonnement — {{ $siteName ?? 'MboaCar' }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; background-color: #f1f5f9; padding: 24px; color: #0f172a; margin: 0;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);">
        <div style="background: #0f172a; padding: 20px 28px; text-align: center;">
            <img src="{{ $logoUrl ?? asset('assets/images/logo.png') }}" alt="{{ $siteName ?? 'MboaCar' }}" style="height: 44px; max-width: 200px; object-fit: contain; background: #ffffff; padding: 6px 12px; border-radius: 8px;">
        </div>

        <div style="padding: 28px;">
            <h2 style="margin-top: 0; margin-bottom: 12px; color: #1d4ed8; font-size: 20px;">
                {{ ($contextType ?? 'subscription') === 'trial' ? "Fin prochaine de votre essai gratuit" : "Renouvellement de votre abonnement" }}
            </h2>

            <p style="margin: 0 0 12px; font-size: 14px; line-height: 1.6;">
                Bonjour <strong>{{ $tenant->name }}</strong>,
            </p>

            @if(isset($daysRemaining) && $daysRemaining > 0)
                <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">
                    Il ne vous reste plus que <strong style="color: #dc2626;">{{ $daysRemaining }} jour{{ $daysRemaining > 1 ? 's' : '' }}</strong> avant l'échéance de votre {{ ($contextType ?? 'subscription') === 'trial' ? 'période d\'essai gratuit' : 'abonnement mensuel' }} sur <strong>{{ $siteName ?? 'MboaCar' }}</strong>.
                </p>
            @else
                <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">
                    L'échéance de votre {{ ($contextType ?? 'subscription') === 'trial' ? 'période d\'essai gratuit' : 'abonnement mensuel' }} pour <strong>{{ $tenant->name }}</strong> est arrivée à terme.
                </p>
            @endif

            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 14px 18px; margin: 18px 0; font-size: 14px;">
                <div><strong>Établissement :</strong> {{ $tenant->name }}</div>
                <div><strong>Tarif mensuel :</strong> {{ number_format($priceFcfa, 0, ',', ' ') }} FCFA</div>
            </div>

            <p style="margin: 0 0 20px; font-size: 14px; line-height: 1.6;">
                Merci de procéder au renouvellement depuis votre espace professionnel afin d'éviter toute interruption de service. Vos données restent intégralement sécurisées.
            </p>

            <div style="text-align: center; margin: 24px 0 8px;">
                <a href="{{ $renewUrl ?? url('/abonnement') }}" style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; padding: 12px 24px; border-radius: 8px;">
                    Renouveler mon abonnement
                </a>
            </div>
        </div>

        <div style="background: #f8fafc; padding: 14px 28px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;">
            &copy; {{ date('Y') }} {{ $siteName ?? 'MboaCar' }} — Gestion Automobile Professionnelle
        </div>
    </div>
</body>
</html>
