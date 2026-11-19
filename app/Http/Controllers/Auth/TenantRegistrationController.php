<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterTenantRequest;
use App\Mail\WelcomeTenantMail;
use App\Models\AffiliateLink;
use App\Models\AffiliateReferral;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\OneTimeLoginToken;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TenantRegistrationController extends Controller
{
    public function show(Request $request): Response
    {
        if (Country::count() === 0) {
            Country::seedDefaults();
        }

        $ref = strtoupper(trim((string) $request->query('ref', '')));
        if ($ref !== '') {
            $link = AffiliateLink::where('code', $ref)->where('is_blocked', false)->first();
            if ($link) {
                $link->increment('clicks_count');
            }
        }

        return Inertia::render('Auth/Register', [
            'baseDomain' => config('garagepro.tenant_base_domain', 'mboacar.com'),
            'trialDays' => PlatformSetting::trialDays(),
            'monthlyPrice' => PlatformSetting::subscriptionPrice(),
            'countries' => Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']),
            'promoCode' => $ref,
            'referralDiscount' => PlatformSetting::affiliateReferralDiscount(),
        ]);
    }

    public function store(
        RegisterTenantRequest $request,
        TenantRoleService $roleService,
        TenantContext $tenantContext
    ): HttpResponse {
        $data = $request->validated();
        $countryRecord = Country::where('code', $data['country'])->first();
        if (! empty($data['phone']) && $countryRecord && ! str_starts_with(trim($data['phone']), '+')) {
            $data['phone'] = $countryRecord->dial_code.' '.ltrim(trim($data['phone']));
        }
        $trialDays = PlatformSetting::trialDays();
        $baseDomain = config('garagepro.tenant_base_domain', 'garagepro.app');
        $scheme = $request->isSecure() || app()->isProduction() ? 'https' : 'http';

        $result = DB::transaction(function () use ($data, $trialDays, $roleService, $tenantContext, $request) {
            $tenant = Tenant::create([
                'name' => $data['garage_name'],
                'slug' => $data['slug'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'country' => $data['country'],
                'city' => $data['city'] ?? null,
                'status' => Tenant::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays($trialDays),
            ]);

            $tenantContext->setTenant($tenant);
            $roleService->provisionForTenant($tenant);

            $agencies = [];
            $count = (int) $data['agencies_count'];
            for ($i = 1; $i <= $count; $i++) {
                $agencies[] = Agency::create([
                    'tenant_id' => $tenant->id,
                    'name' => $i === 1 ? 'Agence Principale' : "Agence {$i}",
                    'code' => 'AG'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'phone' => $i === 1 ? ($data['phone'] ?? null) : null,
                    'city' => $i === 1 ? ($data['city'] ?? null) : null,
                    'is_main' => $i === 1,
                    'is_active' => true,
                ]);
            }

            $ownerFullName = trim(($data['owner_first_name'] ?? '').' '.($data['owner_last_name'] ?? ''));

            $user = User::create([
                'tenant_id' => $tenant->id,
                'default_agency_id' => $agencies[0]->id,
                'name' => $ownerFullName !== '' ? $ownerFullName : $data['garage_name'].' (Gérant)',
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $user->assignRole('gerant');

            foreach ($agencies as $agency) {
                $user->agencies()->attach($agency->id, ['tenant_id' => $tenant->id]);
            }

            $plainToken = OneTimeLoginToken::issueFor($tenant, $user, ttlMinutes: 5);

            // Traitement sécurisé du code promo / lien d'affiliation
            $promoCode = strtoupper(trim((string) ($data['promo_code'] ?? '')));
            if ($promoCode !== '') {
                $affLink = AffiliateLink::where('code', $promoCode)
                    ->where('is_blocked', false)
                    ->lockForUpdate()
                    ->first();

                if ($affLink && (int) $affLink->tenant_id !== (int) $tenant->id) {
                    $ownerUser = User::withoutTenantScope()->find($affLink->user_id);
                    $ip = $request->ip() ?: '127.0.0.1';
                    $sameOwnerEmail = $ownerUser && strtolower($ownerUser->email) === strtolower($user->email);
                    $sameIpRecent = AffiliateReferral::where('affiliate_link_id', $affLink->id)
                        ->where('ip_address', $ip)
                        ->where('created_at', '>=', now()->subDays(30))
                        ->exists();

                    if (! $sameOwnerEmail && ! $sameIpRecent) {
                        $commission = PlatformSetting::affiliateCommission();
                        $discount = PlatformSetting::affiliateReferralDiscount();
                        $trigger = PlatformSetting::affiliateCommissionTrigger();
                        $creditNow = $trigger === 'signup';

                        AffiliateReferral::create([
                            'affiliate_link_id' => $affLink->id,
                            'referred_tenant_id' => $tenant->id,
                            'referred_user_id' => $user->id,
                            'commission_amount' => $commission,
                            'discount_amount' => $discount,
                            'status' => $creditNow ? 'credited' : 'pending',
                            'credited_at' => $creditNow ? now() : null,
                            'ip_address' => $ip,
                        ]);

                        if ($creditNow) {
                            $affLink->increment('balance', $commission);
                            $affLink->increment('total_earned', $commission);
                        }
                    }
                }
            }

            AuditLog::record(
                action: 'tenant.registered',
                subject: $tenant,
                actor: $user,
                tenantId: $tenant->id,
                metadata: ['agencies_count' => $count],
                request: $request
            );

            return [$tenant, $user, $plainToken];
        });

        /** @var Tenant $tenant */
        /** @var User $user */
        /** @var string $plainToken */
        [$tenant, $user, $plainToken] = $result;

        $host = $request->getHost();
        $isLocalOrIp = $host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');

        // En local (ex: http://127.0.0.1:5675), on reste sur l'hôte+port courant ;
        // sur un vrai domaine (ex: garagepro.app), on redirige vers https://{slug}.{domaine}.
        if ($isLocalOrIp) {
            $tenantUrl = $request->getSchemeAndHttpHost();
        } else {
            $effectiveBaseDomain = str_starts_with($host, 'www.') ? substr($host, 4) : $baseDomain;
            $tenantUrl = "{$scheme}://{$tenant->slug}.{$effectiveBaseDomain}";
        }

        Mail::to($user->email)->queue(new WelcomeTenantMail($tenant, $user, $tenantUrl));

        // Lien signé à usage unique et courte durée (5 min).
        URL::forceRootUrl($tenantUrl);
        try {
            $signedSsoUrl = URL::temporarySignedRoute(
                'tenant.sso',
                now()->addMinutes(5),
                ['token' => $plainToken]
            );
        } finally {
            URL::forceRootUrl(null);
        }

        return Inertia::location($signedSsoUrl);
    }

    public function consumeSso(Request $request, string $token, TenantContext $tenantContext): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Lien de connexion invalide ou expiré.');
        }

        $record = null;
        if ($tenantContext->check()) {
            $record = OneTimeLoginToken::consume($token, $tenantContext->id());
        } else {
            // Mode local/IP sans sous-domaine : consomme le jeton et stocke active_tenant_id en session.
            $hash = hash('sha256', $token);
            $candidate = OneTimeLoginToken::where('token_hash', $hash)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first();

            if ($candidate) {
                $record = OneTimeLoginToken::consume($token, $candidate->tenant_id);
            }
        }

        if (! $record) {
            abort(403, 'Ce lien de connexion a déjà été utilisé ou a expiré.');
        }

        $user = User::withoutTenantScope()->findOrFail($record->user_id);

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $record->tenant_id);

        AuditLog::record(
            action: 'auth.sso_login',
            subject: $user,
            actor: $user,
            tenantId: $record->tenant_id,
            request: $request
        );

        return redirect()->route('tenant.dashboard')
            ->with('success', 'Bienvenue sur votre espace GaragePro ! Votre essai gratuit de 7 jours est actif.');
    }
}
