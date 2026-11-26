<?php

namespace App\Http\Middleware;

use App\Models\AffiliateLink;
use App\Models\AppointmentLink;
use App\Models\Category;
use App\Models\Country;
use App\Models\Employee;
use App\Models\MarketplaceOrderItem;
use App\Models\PlatformMessage;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\SecureUploadService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

/**
 * Partage les données globales Inertia sans jamais exposer de secrets.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $tenant = app(TenantContext::class)->getTenant();
        $user = $request->user('web');
        $uploadService = app(SecureUploadService::class);
        $logoPath = $tenant?->settings['logo_path'] ?? null;

        $userPhotoUrl = null;
        if ($user && $tenant) {
            $photoPath = $user->avatar_path ?: Cache::remember(
                "mboacar_emp_photo_{$user->id}",
                300,
                fn () => Employee::where('user_id', $user->id)->value('photo_path')
            );
            if ($photoPath) {
                $userPhotoUrl = $uploadService->signedUrl($photoPath, 60);
            }
        }

        // Alerte de paie : active de J-7 jusqu'à J-1 avant le jour de paie configuré (s'arrête la veille/jour J)
        $payrollAlert = null;
        $payrollDay = $tenant?->settings['payroll_day_of_month'] ?? null;
        if ($tenant && $payrollDay) {
            $today = now()->startOfDay();
            $targetDay = min((int) $payrollDay, $today->daysInMonth);
            $payDate = $today->copy()->day($targetDay);

            if ($payDate->lt($today)) {
                $nextMonth = $today->copy()->addMonthNoOverflow();
                $payDate = $nextMonth->day(min((int) $payrollDay, $nextMonth->daysInMonth));
            }

            $daysRemaining = (int) $today->diffInDays($payDate, false);
            if ($daysRemaining >= 1 && $daysRemaining <= 7) {
                $payrollAlert = [
                    'daysRemaining' => $daysRemaining,
                    'payDate' => $payDate->format('d/m/Y'),
                ];
            }
        }

        $unreadMessagesCount = 0;
        $recentNotifications = [];
        $pendingMarketplaceOrdersCount = 0;
        $pendingAppointmentRequestsCount = 0;
        $affiliateAlert = null;

        if ($user && $tenant) {
            $navBundle = Cache::remember("mboacar_tenant_nav_{$tenant->id}", 20, function () use ($tenant): array {
                $aff = AffiliateLink::where('tenant_id', $tenant->id)->where('is_blocked', true)->first(['code', 'blocked_reason']);

                return [
                    'unreadMessagesCount' => PlatformMessage::where('tenant_id', $tenant->id)
                        ->where('recipient_type', PlatformMessage::TYPE_TENANT)
                        ->where('is_read', false)
                        ->count(),
                    'pendingMarketplaceOrdersCount' => MarketplaceOrderItem::where('tenant_id', $tenant->id)
                        ->where('is_seen_by_seller', false)
                        ->count(),
                    'pendingAppointmentRequestsCount' => AppointmentLink::where('tenant_id', $tenant->id)
                        ->where('status', AppointmentLink::STATUS_SUBMITTED)
                        ->count(),
                    'recentNotifications' => PlatformMessage::where('tenant_id', $tenant->id)
                        ->where('recipient_type', PlatformMessage::TYPE_TENANT)
                        ->latest('id')
                        ->take(6)
                        ->get(['id', 'sender_type', 'sender_name', 'subject', 'body', 'is_read', 'created_at'])
                        ->toArray(),
                    'affiliateAlert' => $aff ? ['code' => $aff->code, 'reason' => $aff->blocked_reason] : null,
                ];
            });

            $unreadMessagesCount = $navBundle['unreadMessagesCount'];
            $pendingMarketplaceOrdersCount = $navBundle['pendingMarketplaceOrdersCount'];
            $pendingAppointmentRequestsCount = $navBundle['pendingAppointmentRequestsCount'];
            $recentNotifications = $navBundle['recentNotifications'];
            $affiliateAlert = $navBundle['affiliateAlert'];
        }

        $cart = $request->hasSession() ? $request->session()->get('marketplace_cart', []) : [];
        $marketplaceCartCount = is_array($cart) ? array_sum(array_map('intval', $cart)) : 0;
        $marketplaceCart = is_array($cart) && ! empty($cart)
            ? app(\App\Http\Controllers\MarketplaceController::class)->buildCartSummary()
            : ['items' => [], 'count' => 0, 'total' => 0];

        $marketplaceCategories = [];
        if ($request->is('marketplace*') || $request->is('panier') || $request->is('commander')) {
            $marketplaceCategories = Cache::remember('mboacar_mp_categories_nav', 600, function (): array {
                return Category::with(['subCategories' => fn ($q) => $q->where('is_active', true)->orderBy('name')->select('id', 'category_id', 'name', 'slug')])
                    ->whereNull('tenant_id')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'icon'])
                    ->toArray();
            });
        }

        $siteSettings = PlatformSetting::siteSettings();
        $baseDomain = config('garagepro.tenant_base_domain', 'mboacar.com');

        // Résout le tenant de l'utilisateur authentifié même sur le domaine racine (landing / marketplace)
        $authTenant = $tenant;
        if (! $authTenant && $user && $user->tenant_id) {
            $authTenant = Tenant::find($user->tenant_id);
        }

        $isDemo = (bool) ($authTenant && $authTenant->slug === 'demo-douala');
        $dashboardUrl = null;
        if ($user && $authTenant && ! $isDemo) {
            $host = strtolower($request->getHost());
            $isLocalOrIp = $host === 'localhost'
                || filter_var($host, FILTER_VALIDATE_IP) !== false
                || str_ends_with($host, '.local')
                || str_ends_with($host, '.test');

            $dashboardUrl = $isLocalOrIp
                ? url('/tableau-de-bord')
                : "https://{$authTenant->slug}.{$baseDomain}/tableau-de-bord";
        }

        $tenantDialCode = '+237';
        if ($tenant) {
            $countryCode = strtoupper(trim((string) ($tenant->country ?? '')));
            if ($countryCode !== '') {
                $resolvedDial = Cache::remember(
                    "mboacar_dial_code_{$countryCode}",
                    3600,
                    fn () => Country::where('code', $countryCode)->value('dial_code')
                );
                if ($resolvedDial) {
                    $tenantDialCode = $resolvedDial;
                }
            }
            if ($tenantDialCode === '+237' && ! empty($tenant->phone) && preg_match('/^(\+\d{1,4})/', trim((string) $tenant->phone), $m)) {
                $tenantDialCode = $m[1];
            }
        }

        return [
            ...parent::share($request),
            'unreadMessagesCount' => $unreadMessagesCount,
            'pendingMarketplaceOrdersCount' => $pendingMarketplaceOrdersCount,
            'pendingAppointmentRequestsCount' => $pendingAppointmentRequestsCount,
            'marketplaceCartCount' => $marketplaceCartCount,
            'marketplaceCart' => $marketplaceCart,
            'marketplaceCategories' => $marketplaceCategories,
            'recentNotifications' => $recentNotifications,
            'app' => [
                'name' => $siteSettings['name'] ?? 'MboaCar',
                'currency' => config('garagepro.currency_label', 'FCFA'),
                'baseDomain' => $baseDomain,
                'site' => $siteSettings,
            ],
            'locale' => app()->getLocale(),
            'supportedLocales' => array_values(config('garagepro.supported_locales', [])),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'type' => $tenant->type ?? 'garage',
                'country' => $tenant->country ?? 'CM',
                'dialCode' => $tenantDialCode,
                'status' => $tenant->status,
                'isReadOnly' => $tenant->isReadOnly(),
                'isInTrialGrace' => $tenant->isInTrialGrace(),
                'isInSubscriptionGrace' => $tenant->isInSubscriptionGrace(),
                'trialEndsAt' => $tenant->trial_ends_at?->toIso8601String(),
                'subscriptionEndsAt' => $tenant->subscription_ends_at?->toIso8601String(),
                'graceEndsAt' => $tenant->grace_ends_at?->toIso8601String(),
                'logoUrl' => $logoPath ? $uploadService->signedUrl($logoPath, 60) : null,
            ] : null,
            'payrollAlert' => $payrollAlert,
            'affiliateAlert' => $affiliateAlert,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'photoUrl' => $userPhotoUrl,
                    'roles' => $user->getRoleNames()->values(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                ] : null,
                'isDemo' => $isDemo,
                'dashboardUrl' => $dashboardUrl,
                'tenant' => $authTenant ? [
                    'id' => $authTenant->id,
                    'name' => $authTenant->name,
                    'slug' => $authTenant->slug,
                    'type' => $authTenant->type ?? 'garage',
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
