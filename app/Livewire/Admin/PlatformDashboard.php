<?php

namespace App\Livewire\Admin;

use App\Mail\SubscriptionReminderMail;
use App\Models\Admin;
use App\Models\AffiliateLink;
use App\Models\AiDiagnosticConversation;
use App\Models\AiDiagnosticMessage;
use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\Category;
use App\Models\Country;
use App\Models\MarketplaceBanner;
use App\Models\OneTimeLoginToken;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PlatformMessage;
use App\Models\PlatformSetting;
use App\Models\SubCategory;
use App\Models\Tenant;
use App\Models\Testimonial;
use App\Services\GreenApiService;
use App\Services\HtmlSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Tableau de bord Livewire du Super-Admin plateforme.
 */
class PlatformDashboard extends Component
{
    use WithFileUploads;
    public string $activeSection = 'overview';
    public int $subscriptionPrice = 10000;
    public int $partsShopSubscriptionPrice = 10000;
    public int $trialDays = 7;
    public int $gracePeriodDays = 5;
    public string $structureEmail = 'jeannotgates@gmail.com';
    public int $affiliateCommission = 2500;
    public int $affiliateReferralDiscount = 5000;
    public string $affiliateCommissionTrigger = 'first_payment';
    public int $aiDailyQuota = 50;
    public string $aiSearch = '';
    public string $aiFilter = 'all';
    public ?int $selectedAiConversationId = null;
    public ?int $editingAiMessageId = null;
    public string $editingAiMessageContent = '';
    public string $siteName = 'MboaCar';
    public string $sitePhone = '';
    public string $siteWhatsapp = '';
    public string $siteSecondaryEmail = '';
    public string $sitePoBox = '';
    public string $socialFacebook = '';
    public string $socialLinkedin = '';
    public string $socialTwitter = '';
    public string $siteDescription = '';
    public string $siteKeywords = '';
    public bool $maintenanceMode = false;
    public string $maintenanceMessage = '';
    public $siteLogoFile = null;
    public $siteFaviconFile = null;
    public $seoImageFile = null;

    public ?int $editingAdminId = null;
    public string $newAdminName = '';
    public string $newAdminEmail = '';
    public string $newAdminRole = Admin::ROLE_SUPPORT;
    public string $newAdminGender = '';
    public string $newAdminJobTitle = '';
    public string $newAdminBio = '';
    public string $newAdminPhone = '';
    public string $newAdminBirthDate = '';
    public $newAdminAvatarFile = null;
    public string $newAdminPassword = '';
    public bool $newAdminActive = true;

    public string $profileName = '';
    public string $profileEmail = '';
    public string $profileGender = '';
    public string $profileJobTitle = '';
    public string $profileBio = '';
    public string $profilePhone = '';
    public string $profileBirthDate = '';
    public $profileAvatarFile = null;
    public string $profilePassword = '';
    public string $profilePasswordConfirmation = '';

    public string $termsContent = '';
    public string $privacyContent = '';

    public string $newCountryCode = '';
    public string $newCountryName = '';
    public string $newCountryDialCode = '';

    public ?int $adminMsgTenantId = null;
    public string $adminMsgSubject = '';
    public string $adminMsgBody = '';
    public ?int $replyingMsgId = null;
    public string $adminReplyBody = '';

    public string $mpCatName = '';
    public ?int $mpSubParentId = null;
    public string $mpSubName = '';

    public ?int $editingBannerId = null;
    public string $bannerTitle = '';
    public string $bannerSubtitle = '';
    public string $bannerPlacement = 'row_1';
    public string $bannerGradientFrom = '#0f172a';
    public string $bannerGradientTo = '#2563eb';
    public string $bannerButtonText = '';
    public string $bannerButtonUrl = '';

    public ?int $selectedAffiliateLinkId = null;
    public string $affiliateBlockReason = '';
    public $bannerImage = null;

    public string $newIp = '';
    public string $newIpReason = '';
    public string $feedbackMessage = '';
    public string $feedbackError = '';

    public string $tenantSearch = '';
    public string $tenantTypeFilter = 'all';
    public string $tenantStatusFilter = 'all';
    public int $analyticsMonths = 6;

    public string $testiAuthor = '';
    public string $testiRole = 'Gérant';
    public string $testiGarage = '';
    public string $testiCity = '';
    public string $testiQuote = '';
    public int $testiRating = 5;

    public string $partnerName = '';
    public string $partnerWebsiteUrl = '';
    public int $partnerSortOrder = 0;
    public $partnerLogoFile = null;

    private function authorizeSection(string $section): Admin
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor instanceof Admin && $actor->canAccessSection($section), 403, 'Accès non autorisé à cette action administrateur.');

        return $actor;
    }

    public function setSection(string $section): void
    {
        $admin = Auth::guard('admin')->user();
        if ($admin && ! $admin->canAccessSection($section)) {
            abort(403, 'Votre rôle administrateur ne vous autorise pas à accéder à cette section.');
        }

        $allowed = [
            'overview', 'tenants', 'ai_diagnostics', 'messages', 'affiliation',
            'categories', 'banners', 'testimonials', 'partners', 'countries',
            'terms', 'privacy',
            'security', 'admins', 'settings', 'profile',
        ];
        if (in_array($section, $allowed, true)) {
            $this->activeSection = $section;
        }
    }

    public function mount(string $section = 'overview'): void
    {
        $this->setSection($section);
        $this->subscriptionPrice = PlatformSetting::subscriptionPrice();
        $this->partsShopSubscriptionPrice = PlatformSetting::partsShopSubscriptionPrice();
        $this->trialDays = max(1, min(30, PlatformSetting::trialDays()));
        $this->gracePeriodDays = PlatformSetting::gracePeriodDays();
        $this->structureEmail = PlatformSetting::structureEmail();
        $this->affiliateCommission = PlatformSetting::affiliateCommission();
        $this->affiliateReferralDiscount = PlatformSetting::affiliateReferralDiscount();
        $this->affiliateCommissionTrigger = PlatformSetting::affiliateCommissionTrigger();
        $this->aiDailyQuota = (int) PlatformSetting::getValue('ai_daily_quota', 50);
        $this->maintenanceMode = PlatformSetting::isMaintenanceMode();
        $this->maintenanceMessage = PlatformSetting::maintenanceMessage();
        $site = PlatformSetting::siteSettings();
        $this->siteName = $site['name'];
        $this->sitePhone = $site['phone'];
        $this->siteWhatsapp = $site['whatsapp'];
        $this->siteSecondaryEmail = $site['secondary_email'];
        $this->sitePoBox = $site['po_box'];
        $this->socialFacebook = $site['facebook'];
        $this->socialLinkedin = $site['linkedin'];
        $this->socialTwitter = $site['twitter'];
        $this->siteDescription = $site['description'];
        $this->siteKeywords = $site['keywords'];
        $this->termsContent = PlatformSetting::termsOfUse();
        $this->privacyContent = PlatformSetting::privacyPolicy();

        $admin = Auth::guard('admin')->user();
        if ($admin) {
            $this->profileName = $admin->name;
            $this->profileEmail = $admin->email;
            $this->profileGender = (string) ($admin->gender ?? '');
            $this->profileJobTitle = (string) ($admin->job_title ?? '');
            $this->profileBio = (string) ($admin->bio ?? '');
            $this->profilePhone = (string) ($admin->phone ?? '');
            $this->profileBirthDate = $admin->birth_date ? $admin->birth_date->format('Y-m-d') : '';
        }
    }

    public function saveAdminProfile(): void
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin) {
            return;
        }

        $this->validate([
            'profileName' => ['required', 'string', 'min:2', 'max:120'],
            'profileEmail' => ['required', 'email', 'max:150', 'unique:admins,email,'.$admin->id],
            'profileGender' => ['nullable', 'in:homme,femme,autre'],
            'profileJobTitle' => ['nullable', 'string', 'max:120'],
            'profileBio' => ['nullable', 'string', 'max:1500'],
            'profilePhone' => ['nullable', 'string', 'max:50'],
            'profileBirthDate' => ['nullable', 'date'],
            'profileAvatarFile' => ['nullable', 'image', 'max:3072'],
            'profilePassword' => ['nullable', 'string', 'min:6', 'max:255', 'same:profilePasswordConfirmation'],
        ]);

        $payload = [
            'name' => trim($this->profileName),
            'email' => strtolower(trim($this->profileEmail)),
            'gender' => $this->profileGender ?: null,
            'job_title' => trim($this->profileJobTitle) ?: null,
            'bio' => trim($this->profileBio) ?: null,
            'phone' => trim($this->profilePhone) ?: null,
            'birth_date' => $this->profileBirthDate ?: null,
        ];

        if ($this->profileAvatarFile) {
            $payload['avatar_path'] = $this->profileAvatarFile->store('images/admins', 'public');
            $this->profileAvatarFile = null;
        }

        if (trim($this->profilePassword) !== '') {
            $payload['password'] = $this->profilePassword;
        }

        $admin->update($payload);
        $this->profilePassword = '';
        $this->profilePasswordConfirmation = '';

        AuditLog::record('admin.profile_updated', subject: $admin, actor: $admin);
        session()->flash('admin_success', 'Votre profil administrateur a été mis à jour.');
        $this->redirectRoute('admin.profile');
    }

    public function saveSettings(): void
    {
        $this->authorizeSection('settings');

        $this->validate([
            'subscriptionPrice' => ['required', 'integer', 'min:1'],
            'partsShopSubscriptionPrice' => ['required', 'integer', 'min:1'],
            'trialDays' => ['required', 'integer', 'min:1', 'max:30'],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:30'],
            'structureEmail' => ['required', 'email', 'max:150'],
            'affiliateCommission' => ['required', 'integer', 'min:1'],
            'affiliateReferralDiscount' => ['required', 'integer', 'min:1'],
            'affiliateCommissionTrigger' => ['required', 'in:first_payment,signup'],
            'aiDailyQuota' => ['required', 'integer', 'min:0', 'max:10000'],
            'siteName' => ['required', 'string', 'min:2', 'max:100'],
            'sitePhone' => ['nullable', 'string', 'max:50'],
            'siteWhatsapp' => ['nullable', 'string', 'max:50'],
            'siteSecondaryEmail' => ['nullable', 'email', 'max:150'],
            'sitePoBox' => ['nullable', 'string', 'max:100'],
            'socialFacebook' => ['nullable', 'url', 'max:255'],
            'socialLinkedin' => ['nullable', 'url', 'max:255'],
            'socialTwitter' => ['nullable', 'url', 'max:255'],
            'siteDescription' => ['nullable', 'string', 'max:500'],
            'siteKeywords' => ['nullable', 'string', 'max:300'],
            'maintenanceMessage' => ['nullable', 'string', 'max:1000'],
            'siteLogoFile' => ['nullable', 'image', 'max:4096'],
            'siteFaviconFile' => ['nullable', 'image', 'max:2048'],
            'seoImageFile' => ['nullable', 'image', 'max:5120'],
        ]);

        PlatformSetting::setValue('subscription_price', $this->subscriptionPrice);
        PlatformSetting::setValue('parts_shop_subscription_price', $this->partsShopSubscriptionPrice);
        PlatformSetting::setValue('trial_days', $this->trialDays);
        PlatformSetting::setValue('grace_period_days', $this->gracePeriodDays);
        PlatformSetting::setValue('structure_email', strtolower(trim($this->structureEmail)));
        PlatformSetting::setValue('affiliate_commission', $this->affiliateCommission);
        PlatformSetting::setValue('affiliate_referral_discount', $this->affiliateReferralDiscount);
        PlatformSetting::setValue('affiliate_commission_trigger', $this->affiliateCommissionTrigger);
        PlatformSetting::setValue('ai_daily_quota', $this->aiDailyQuota);
        PlatformSetting::setValue('site_name', trim($this->siteName));
        PlatformSetting::setValue('site_phone', trim($this->sitePhone));
        PlatformSetting::setValue('site_whatsapp', trim($this->siteWhatsapp));
        PlatformSetting::setValue('site_secondary_email', strtolower(trim($this->siteSecondaryEmail)));
        PlatformSetting::setValue('site_po_box', trim($this->sitePoBox));
        PlatformSetting::setValue('social_facebook', trim($this->socialFacebook));
        PlatformSetting::setValue('social_linkedin', trim($this->socialLinkedin));
        PlatformSetting::setValue('social_twitter', trim($this->socialTwitter));
        PlatformSetting::setValue('site_description', trim($this->siteDescription));
        PlatformSetting::setValue('site_keywords', trim($this->siteKeywords));
        PlatformSetting::setValue('maintenance_mode', $this->maintenanceMode ? '1' : '0');
        PlatformSetting::setValue('maintenance_message', trim($this->maintenanceMessage));

        if ($this->siteLogoFile) {
            PlatformSetting::setValue('site_logo', $this->siteLogoFile->store('site', 'public'));
            $this->siteLogoFile = null;
        }
        if ($this->siteFaviconFile) {
            PlatformSetting::setValue('site_favicon', $this->siteFaviconFile->store('site', 'public'));
            $this->siteFaviconFile = null;
        }
        if ($this->seoImageFile) {
            PlatformSetting::setValue('seo_image', $this->seoImageFile->store('site', 'public'));
            $this->seoImageFile = null;
        }

        AuditLog::record('admin.settings_updated', actor: Auth::guard('admin')->user(), metadata: [
            'subscription_price' => $this->subscriptionPrice,
            'trial_days' => $this->trialDays,
            'grace_period_days' => $this->gracePeriodDays,
            'structure_email' => $this->structureEmail,
            'affiliate_commission' => $this->affiliateCommission,
            'affiliate_referral_discount' => $this->affiliateReferralDiscount,
            'affiliate_commission_trigger' => $this->affiliateCommissionTrigger,
        ]);

        $this->feedbackMessage = 'Paramètres globaux et SEO enregistrés.';
    }

    public function suspendTenant(int $tenantId): void
    {
        $this->authorizeSection('tenants');

        $tenant = Tenant::findOrFail($tenantId);
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        AuditLog::record('admin.tenant_suspended', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id);
        $this->feedbackMessage = "Tenant {$tenant->name} suspendu.";
    }

    public function reactivateTenant(int $tenantId): void
    {
        $this->authorizeSection('tenants');

        $tenant = Tenant::findOrFail($tenantId);
        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);

        AuditLog::record('admin.tenant_reactivated', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id);
        $this->feedbackMessage = "Tenant {$tenant->name} réactivé.";
    }

    public function extendTrial(int $tenantId, int $days = 7): void
    {
        $this->authorizeSection('tenants');

        $tenant = Tenant::findOrFail($tenantId);
        $base = ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) ? $tenant->trial_ends_at : now();

        $tenant->update([
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => $base->copy()->addDays($days),
        ]);

        AuditLog::record('admin.trial_extended', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id, metadata: ['days' => $days]);
        $this->feedbackMessage = "Essai prolongé de {$days} jours pour {$tenant->name}.";
    }

    public function impersonateTenant(int $tenantId): void
    {
        $this->authorizeSection('tenants');

        $tenant = Tenant::with('users')->findOrFail($tenantId);
        $gerant = $tenant->users()->first();
        if (! $gerant) {
            $this->feedbackMessage = 'Aucun utilisateur trouvé pour ce tenant.';

            return;
        }

        $token = OneTimeLoginToken::issueFor($tenant, $gerant, ttlMinutes: 2);
        $baseDomain = config('garagepro.tenant_base_domain', 'mboacar.com');
        $tenantUrl = "https://{$tenant->slug}.{$baseDomain}";

        AuditLog::record('admin.impersonate_tenant', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id);

        URL::forceRootUrl($tenantUrl);
        try {
            $signedUrl = URL::temporarySignedRoute('tenant.sso', now()->addMinutes(2), ['token' => $token]);
        } finally {
            URL::forceRootUrl(null);
        }

        $this->redirect($signedUrl);
    }

    public function setAnalyticsMonths(int $months): void
    {
        $this->analyticsMonths = in_array($months, [6, 12], true) ? $months : 6;
    }

    public function resetTenantFilters(): void
    {
        $this->tenantTypeFilter = 'all';
        $this->tenantStatusFilter = 'all';
        $this->tenantSearch = '';
    }

    public function remindTenantEmail(int $tenantId): void
    {
        $this->authorizeSection('tenants');
        $this->feedbackMessage = '';
        $this->feedbackError = '';

        $tenant = Tenant::with(['users' => fn ($q) => $q->withoutGlobalScope('tenant')->orderBy('id')])->findOrFail($tenantId);
        $meta = $this->resolveTenantExpiration($tenant);

        if (! $meta['can_remind']) {
            $this->feedbackError = "La relance n'est disponible que lorsqu'il reste 7 jours ou moins avant l'échéance de {$tenant->name}.";

            return;
        }

        $email = trim((string) ($tenant->email ?: $tenant->users->first()?->email));
        if ($email === '') {
            $this->feedbackError = "Aucune adresse e-mail renseignée pour {$tenant->name}.";

            return;
        }

        $price = PlatformSetting::subscriptionPrice();

        try {
            Mail::to($email)->sendNow(new SubscriptionReminderMail(
                tenant: $tenant,
                priceFcfa: $price,
                daysRemaining: $meta['days_remaining'],
                contextType: $meta['context'],
            ));

            $settings = $tenant->settings ?? [];
            $settings['last_email_reminder_at'] = now()->toIso8601String();
            $settings['last_sub_reminder_at'] = now()->toIso8601String();
            $tenant->update(['settings' => $settings]);

            AuditLog::record('admin.tenant_reminded_email', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id, metadata: [
                'email' => $email,
                'days_remaining' => $meta['days_remaining'],
            ]);

            $this->feedbackMessage = "Relance par e-mail envoyée avec succès à {$tenant->name} ({$email}).";
        } catch (\Throwable $e) {
            $this->feedbackError = "Échec de l'envoi de l'e-mail à {$tenant->name} : {$e->getMessage()}";
        }
    }

    public function remindTenantWhatsapp(int $tenantId): void
    {
        $this->authorizeSection('tenants');
        $this->feedbackMessage = '';
        $this->feedbackError = '';

        $tenant = Tenant::with(['users' => fn ($q) => $q->withoutGlobalScope('tenant')->orderBy('id')])->findOrFail($tenantId);
        $meta = $this->resolveTenantExpiration($tenant);

        if (! $meta['can_remind']) {
            $this->feedbackError = "La relance n'est disponible que lorsqu'il reste 7 jours ou moins avant l'échéance de {$tenant->name}.";

            return;
        }

        $phone = $this->resolveTenantPhone($tenant);
        if (! $phone) {
            $this->feedbackError = "Aucun numéro WhatsApp/téléphone renseigné pour {$tenant->name}.";

            return;
        }

        $price = PlatformSetting::subscriptionPrice();
        $message = $this->buildReminderWhatsappMessage($tenant, $meta['days_remaining'], $meta['context'], $price);

        $result = app(GreenApiService::class)->sendMessage($phone, $message);
        if (! $result['ok']) {
            $this->feedbackError = "Échec WhatsApp pour {$tenant->name} : ".($result['error'] ?? 'erreur inconnue');

            return;
        }

        $settings = $tenant->settings ?? [];
        $settings['last_whatsapp_reminder_at'] = now()->toIso8601String();
        $tenant->update(['settings' => $settings]);

        AuditLog::record('admin.tenant_reminded_whatsapp', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id, metadata: [
            'phone' => $phone,
            'days_remaining' => $meta['days_remaining'],
        ]);

        $this->feedbackMessage = "Relance WhatsApp envoyée avec succès à {$tenant->name} ({$phone}).";
    }

    public function remindTenantBoth(int $tenantId): void
    {
        $this->authorizeSection('tenants');
        $this->feedbackMessage = '';
        $this->feedbackError = '';

        $tenant = Tenant::with(['users' => fn ($q) => $q->withoutGlobalScope('tenant')->orderBy('id')])->findOrFail($tenantId);
        $meta = $this->resolveTenantExpiration($tenant);

        if (! $meta['can_remind']) {
            $this->feedbackError = "La relance n'est disponible que lorsqu'il reste 7 jours ou moins avant l'échéance de {$tenant->name}.";

            return;
        }

        $price = PlatformSetting::subscriptionPrice();
        $settings = $tenant->settings ?? [];
        $sentChannels = [];
        $errors = [];

        $email = trim((string) ($tenant->email ?: $tenant->users->first()?->email));
        if ($email !== '') {
            try {
                Mail::to($email)->sendNow(new SubscriptionReminderMail(
                    tenant: $tenant,
                    priceFcfa: $price,
                    daysRemaining: $meta['days_remaining'],
                    contextType: $meta['context'],
                ));
                $settings['last_email_reminder_at'] = now()->toIso8601String();
                $settings['last_sub_reminder_at'] = now()->toIso8601String();
                $sentChannels[] = "E-mail ({$email})";
            } catch (\Throwable $e) {
                $errors[] = "E-mail : {$e->getMessage()}";
            }
        } else {
            $errors[] = 'Aucun e-mail renseigné';
        }

        $phone = $this->resolveTenantPhone($tenant);
        if ($phone) {
            $waMsg = $this->buildReminderWhatsappMessage($tenant, $meta['days_remaining'], $meta['context'], $price);
            $wa = app(GreenApiService::class)->sendMessage($phone, $waMsg);
            if ($wa['ok']) {
                $settings['last_whatsapp_reminder_at'] = now()->toIso8601String();
                $sentChannels[] = "WhatsApp ({$phone})";
            } else {
                $errors[] = 'WhatsApp : '.($wa['error'] ?? 'échec');
            }
        } else {
            $errors[] = 'Aucun numéro WhatsApp renseigné';
        }

        if (! empty($sentChannels)) {
            $tenant->update(['settings' => $settings]);
            AuditLog::record('admin.tenant_reminded_multi', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id, metadata: [
                'channels' => $sentChannels,
                'days_remaining' => $meta['days_remaining'],
            ]);
            $this->feedbackMessage = "Relance envoyée à {$tenant->name} via : ".implode(' & ', $sentChannels).'.';
        }

        if (! empty($errors)) {
            $this->feedbackError = 'Détails non envoyés : '.implode(' | ', $errors);
        }
    }

    public function remindAllExpiringTenants(): void
    {
        $this->authorizeSection('tenants');
        $this->feedbackMessage = '';
        $this->feedbackError = '';

        $price = PlatformSetting::subscriptionPrice();
        $greenApi = app(GreenApiService::class);
        $allTenants = Tenant::with(['users' => fn ($q) => $q->withoutGlobalScope('tenant')->orderBy('id')])->get();

        $emailCount = 0;
        $waCount = 0;
        $eligibleCount = 0;

        foreach ($allTenants as $tenant) {
            $meta = $this->resolveTenantExpiration($tenant);
            if (! $meta['can_remind']) {
                continue;
            }
            $eligibleCount++;
            $settings = $tenant->settings ?? [];
            $updated = false;

            $email = trim((string) ($tenant->email ?: $tenant->users->first()?->email));
            if ($email !== '') {
                try {
                    Mail::to($email)->sendNow(new SubscriptionReminderMail(
                        tenant: $tenant,
                        priceFcfa: $price,
                        daysRemaining: $meta['days_remaining'],
                        contextType: $meta['context'],
                    ));
                    $settings['last_email_reminder_at'] = now()->toIso8601String();
                    $settings['last_sub_reminder_at'] = now()->toIso8601String();
                    $emailCount++;
                    $updated = true;
                } catch (\Throwable) {
                    // Continue with other tenants
                }
            }

            $phone = $this->resolveTenantPhone($tenant);
            if ($phone && $greenApi->isConfigured()) {
                $waMsg = $this->buildReminderWhatsappMessage($tenant, $meta['days_remaining'], $meta['context'], $price);
                $wa = $greenApi->sendMessage($phone, $waMsg);
                if ($wa['ok']) {
                    $settings['last_whatsapp_reminder_at'] = now()->toIso8601String();
                    $waCount++;
                    $updated = true;
                }
            }

            if ($updated) {
                $tenant->update(['settings' => $settings]);
            }
        }

        if ($eligibleCount === 0) {
            $this->feedbackMessage = 'Aucun compte à 7 jours ou moins de son échéance à relancer.';

            return;
        }

        AuditLog::record('admin.tenants_bulk_reminded', actor: Auth::guard('admin')->user(), metadata: [
            'eligible' => $eligibleCount,
            'emails_sent' => $emailCount,
            'whatsapp_sent' => $waCount,
        ]);

        $this->feedbackMessage = "Relance groupée terminée ({$eligibleCount} compte(s) éligible(s)) : {$emailCount} e-mail(s) et {$waCount} message(s) WhatsApp envoyé(s).";
    }

    private function computeTrend(int|float $current, int|float $previous): array
    {
        $diff = $current - $previous;

        if ($previous == 0 && $current == 0) {
            return [
                'direction' => 'stable',
                'percent' => 0.0,
                'diff' => 0,
                'label' => 'Stable',
            ];
        }

        if ($previous == 0 && $current > 0) {
            return [
                'direction' => 'up',
                'percent' => 100.0,
                'diff' => $diff,
                'label' => 'En hausse',
            ];
        }

        $percent = round((($current - $previous) / $previous) * 100, 1);

        if ($percent > 0) {
            return [
                'direction' => 'up',
                'percent' => $percent,
                'diff' => $diff,
                'label' => 'En hausse',
            ];
        }

        if ($percent < 0) {
            return [
                'direction' => 'down',
                'percent' => $percent,
                'diff' => $diff,
                'label' => 'En baisse',
            ];
        }

        return [
            'direction' => 'stable',
            'percent' => 0.0,
            'diff' => 0,
            'label' => 'Stable',
        ];
    }

    private function resolveTenantExpiration(Tenant $tenant): array
    {
        $context = $tenant->status === Tenant::STATUS_TRIALING ? 'trial' : 'subscription';

        $expiresAt = match ($tenant->status) {
            Tenant::STATUS_ACTIVE => $tenant->subscription_ends_at,
            Tenant::STATUS_TRIALING => $tenant->trial_ends_at,
            Tenant::STATUS_PAST_DUE => $tenant->grace_ends_at ?? $tenant->subscription_ends_at,
            Tenant::STATUS_EXPIRED => $tenant->subscription_ends_at ?? $tenant->trial_ends_at,
            default => $tenant->subscription_ends_at ?? $tenant->trial_ends_at,
        };

        $daysRemaining = $expiresAt
            ? (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false)
            : null;

        $eligibleStatus = in_array($tenant->status, [
            Tenant::STATUS_ACTIVE,
            Tenant::STATUS_TRIALING,
            Tenant::STATUS_PAST_DUE,
            Tenant::STATUS_EXPIRED,
        ], true);

        $canRemind = $eligibleStatus && (
            ($daysRemaining !== null && $daysRemaining <= 7)
            || in_array($tenant->status, [Tenant::STATUS_PAST_DUE, Tenant::STATUS_EXPIRED], true)
        );

        return [
            'expires_at' => $expiresAt,
            'days_remaining' => $daysRemaining,
            'can_remind' => $canRemind,
            'context' => $context,
        ];
    }

    private function resolveTenantPhone(Tenant $tenant): ?string
    {
        $phone = trim((string) ($tenant->phone ?: $tenant->users->first()?->phone));

        return $phone !== '' ? $phone : null;
    }

    private function buildReminderWhatsappMessage(Tenant $tenant, ?int $daysRemaining, string $contextType, int $price): string
    {
        $siteName = PlatformSetting::siteSettings()['name'] ?? 'MboaCar';
        $baseDomain = config('garagepro.tenant_base_domain', 'mboacar.com');
        $renewUrl = "https://{$tenant->slug}.{$baseDomain}/abonnement";
        $label = $contextType === 'trial' ? "période d'essai gratuit" : 'abonnement mensuel';

        if ($daysRemaining !== null && $daysRemaining > 0) {
            $deadlineLine = "Il ne vous reste plus que *{$daysRemaining} jour(s)* avant l'échéance de votre {$label}.";
        } elseif ($daysRemaining === 0) {
            $deadlineLine = "Votre {$label} arrive à échéance *aujourd'hui*.";
        } else {
            $deadlineLine = "Votre {$label} est arrivé à échéance.";
        }

        return "Bonjour *{$tenant->name}*,\n\n"
            ."{$deadlineLine}\n"
            ."Tarif mensuel : *".number_format($price, 0, ',', ' ')." FCFA*.\n\n"
            ."Renouvelez dès maintenant depuis votre espace pour éviter toute interruption :\n{$renewUrl}\n\n"
            ."L'équipe {$siteName}";
    }

    public function blockIpManual(): void
    {
        $this->authorizeSection('security');

        $this->validate([
            'newIp' => ['required', 'ip'],
            'newIpReason' => ['required', 'string', 'max:255'],
        ]);

        BlockedIp::blockIp($this->newIp, $this->newIpReason, permanent: true, createdBy: 'admin');
        AuditLog::record('admin.ip_blocked', actor: Auth::guard('admin')->user(), metadata: ['ip' => $this->newIp]);

        $this->reset('newIp', 'newIpReason');
        $this->feedbackMessage = 'Adresse IP bloquée.';
    }

    public function unblockIp(int $id): void
    {
        $this->authorizeSection('security');

        $record = BlockedIp::findOrFail($id);
        $record->delete();
        AuditLog::record('admin.ip_unblocked', actor: Auth::guard('admin')->user(), metadata: ['ip' => $record->ip_address]);
        $this->feedbackMessage = "IP {$record->ip_address} débloquée.";
    }

    public function addTestimonial(): void
    {
        $this->authorizeSection('testimonials');

        $this->validate([
            'testiAuthor' => ['required', 'string', 'max:120'],
            'testiRole' => ['required', 'string', 'max:120'],
            'testiGarage' => ['required', 'string', 'max:150'],
            'testiCity' => ['nullable', 'string', 'max:100'],
            'testiQuote' => ['required', 'string', 'max:1000'],
            'testiRating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        Testimonial::create([
            'author_name' => $this->testiAuthor,
            'author_role' => $this->testiRole,
            'garage_name' => $this->testiGarage,
            'city' => $this->testiCity ?: null,
            'quote' => $this->testiQuote,
            'rating' => $this->testiRating,
            'is_published' => true,
        ]);

        $this->reset('testiAuthor', 'testiGarage', 'testiCity', 'testiQuote');
        $this->feedbackMessage = 'Témoignage ajouté et publié sur la page d\'accueil.';
    }

    public function toggleTestimonial(int $id): void
    {
        $this->authorizeSection('testimonials');

        $t = Testimonial::findOrFail($id);
        $t->update(['is_published' => ! $t->is_published]);
    }

    public function deleteTestimonial(int $id): void
    {
        $this->authorizeSection('testimonials');

        Testimonial::findOrFail($id)->delete();
        $this->feedbackMessage = 'Témoignage supprimé.';
    }

    public function addCountry(): void
    {
        $this->authorizeSection('countries');

        $this->validate([
            'newCountryCode' => ['required', 'string', 'size:2', 'alpha'],
            'newCountryName' => ['required', 'string', 'max:120'],
            'newCountryDialCode' => ['required', 'string', 'max:10', 'regex:/^\+\d{1,5}$/'],
        ]);

        Country::updateOrCreate(
            ['code' => strtoupper($this->newCountryCode)],
            [
                'name' => trim($this->newCountryName),
                'dial_code' => trim($this->newCountryDialCode),
                'is_active' => true,
            ]
        );

        $this->reset('newCountryCode', 'newCountryName', 'newCountryDialCode');
        $this->feedbackMessage = 'Pays ajouté / mis à jour.';
    }

    public function toggleCountry(int $id): void
    {
        $this->authorizeSection('countries');

        $country = Country::findOrFail($id);
        $country->update(['is_active' => ! $country->is_active]);
    }

    public function sendAdminMessage(): void
    {
        $admin = $this->authorizeSection('messages');

        $this->validate([
            'adminMsgTenantId' => ['required', 'integer', 'exists:tenants,id'],
            'adminMsgSubject' => ['required', 'string', 'min:2', 'max:200'],
            'adminMsgBody' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        PlatformMessage::create([
            'tenant_id' => $this->adminMsgTenantId,
            'sender_type' => PlatformMessage::TYPE_ADMIN,
            'sender_id' => $admin->id,
            'sender_name' => 'Administration MboaCar',
            'recipient_type' => PlatformMessage::TYPE_TENANT,
            'recipient_id' => $this->adminMsgTenantId,
            'subject' => $this->adminMsgSubject,
            'body' => $this->adminMsgBody,
            'is_read' => false,
        ]);

        $this->reset('adminMsgSubject', 'adminMsgBody');
        $this->feedbackMessage = 'Message interne envoyé au garage (sans e-mail).';
    }

    public function startAdminReply(int $messageId): void
    {
        $this->authorizeSection('messages');

        $this->replyingMsgId = $messageId;
        $this->adminReplyBody = '';
        $msg = PlatformMessage::find($messageId);
        if ($msg && $msg->recipient_type === PlatformMessage::TYPE_ADMIN && ! $msg->is_read) {
            $msg->update(['is_read' => true, 'read_at' => now()]);
        }
    }

    public function submitAdminReply(): void
    {
        $admin = $this->authorizeSection('messages');

        if (! $this->replyingMsgId) {
            return;
        }

        $this->validate([
            'adminReplyBody' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        $original = PlatformMessage::findOrFail($this->replyingMsgId);
        $subject = str_starts_with($original->subject, 'Re:') ? $original->subject : 'Re: '.$original->subject;

        PlatformMessage::create([
            'tenant_id' => $original->tenant_id,
            'parent_id' => $original->id,
            'sender_type' => PlatformMessage::TYPE_ADMIN,
            'sender_id' => $admin->id,
            'sender_name' => 'Administration MboaCar',
            'recipient_type' => PlatformMessage::TYPE_TENANT,
            'recipient_id' => $original->tenant_id,
            'subject' => $subject,
            'body' => $this->adminReplyBody,
            'is_read' => false,
        ]);

        $this->reset('replyingMsgId', 'adminReplyBody');
        $this->feedbackMessage = 'Réponse interne envoyée au garage.';
    }

    public function addMarketplaceCategory(): void
    {
        $this->authorizeSection('categories');

        $this->validate(['mpCatName' => ['required', 'string', 'min:2', 'max:120']]);
        $slug = \Illuminate\Support\Str::slug($this->mpCatName);
        if (Category::whereNull('tenant_id')->where('slug', $slug)->exists()) {
            $slug .= '-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
        }
        Category::create([
            'tenant_id' => null,
            'name' => trim($this->mpCatName),
            'slug' => $slug,
            'icon' => 'bi-gear-wide-connected',
            'is_active' => true,
        ]);
        $this->reset('mpCatName');
        $this->feedbackMessage = 'Catégorie Marketplace globale ajoutée.';
    }

    public function addMarketplaceSubCategory(): void
    {
        $this->authorizeSection('categories');

        $this->validate([
            'mpSubParentId' => ['required', 'integer', 'exists:marketplace_categories,id'],
            'mpSubName' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $slug = \Illuminate\Support\Str::slug($this->mpSubName);
        SubCategory::create([
            'tenant_id' => null,
            'category_id' => $this->mpSubParentId,
            'name' => trim($this->mpSubName),
            'slug' => $slug.'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
            'is_active' => true,
        ]);
        $this->reset('mpSubName');
        $this->feedbackMessage = 'Sous-catégorie Marketplace globale ajoutée.';
    }

    public function deleteMarketplaceCategory(int $id): void
    {
        $this->authorizeSection('categories');

        Category::findOrFail($id)->delete();
        $this->feedbackMessage = 'Catégorie supprimée.';
    }

    public function saveBanner(): void
    {
        $this->authorizeSection('banners');

        $this->validate([
            'bannerTitle' => ['required', 'string', 'min:2', 'max:180'],
            'bannerSubtitle' => ['nullable', 'string', 'max:255'],
            'bannerPlacement' => ['required', 'in:top,row_1,row_2'],
            'bannerGradientFrom' => ['required', 'string', 'max:30'],
            'bannerGradientTo' => ['required', 'string', 'max:30'],
            'bannerButtonText' => ['nullable', 'string', 'max:80'],
            'bannerButtonUrl' => ['nullable', 'string', 'max:500'],
            'bannerImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $payload = [
            'title' => trim($this->bannerTitle),
            'subtitle' => trim($this->bannerSubtitle) ?: null,
            'placement' => $this->bannerPlacement,
            'gradient_from' => $this->bannerGradientFrom,
            'gradient_to' => $this->bannerGradientTo,
            'button_text' => trim($this->bannerButtonText) ?: null,
            'button_url' => trim($this->bannerButtonUrl) ?: null,
            'is_active' => true,
        ];

        if ($this->bannerImage) {
            $payload['image_path'] = $this->bannerImage->store('marketplace/banners', 'local');
        } elseif (! $this->editingBannerId) {
            $payload['image_path'] = '/images/hero-garage.jpg';
        }

        if ($this->editingBannerId) {
            MarketplaceBanner::findOrFail($this->editingBannerId)->update($payload);
            $this->feedbackMessage = 'Bannière publicitaire mise à jour.';
        } else {
            MarketplaceBanner::create($payload);
            $this->feedbackMessage = 'Bannière publicitaire créée et publiée.';
        }

        $this->reset('editingBannerId', 'bannerTitle', 'bannerSubtitle', 'bannerButtonText', 'bannerButtonUrl', 'bannerImage');
    }

    public function editBanner(int $id): void
    {
        $this->authorizeSection('banners');

        $b = MarketplaceBanner::findOrFail($id);
        $this->editingBannerId = $b->id;
        $this->bannerTitle = $b->title;
        $this->bannerSubtitle = $b->subtitle ?? '';
        $this->bannerPlacement = $b->placement;
        $this->bannerGradientFrom = $b->gradient_from;
        $this->bannerGradientTo = $b->gradient_to;
        $this->bannerButtonText = $b->button_text ?? '';
        $this->bannerButtonUrl = $b->button_url ?? '';
    }

    public function toggleBanner(int $id): void
    {
        $this->authorizeSection('banners');

        $b = MarketplaceBanner::findOrFail($id);
        $b->update(['is_active' => ! $b->is_active]);
    }

    public function deleteBanner(int $id): void
    {
        $this->authorizeSection('banners');

        MarketplaceBanner::findOrFail($id)->delete();
        $this->feedbackMessage = 'Bannière supprimée.';
    }

    public function toggleAffiliateBlock(int $linkId): void
    {
        $this->authorizeSection('affiliation');

        $link = AffiliateLink::findOrFail($linkId);
        $willBlock = ! $link->is_blocked;

        $link->update([
            'is_blocked' => $willBlock,
            'blocked_reason' => $willBlock ? (trim($this->affiliateBlockReason) ?: 'Activité suspecte ou non-respect des règles d\'affiliation') : null,
        ]);

        $this->affiliateBlockReason = '';
        $this->feedbackMessage = $willBlock
            ? "Lien d'affiliation {$link->code} bloqué."
            : "Lien d'affiliation {$link->code} débloqué.";
    }

    public function inspectAffiliateReferrals(int $linkId): void
    {
        $this->authorizeSection('affiliation');

        $this->selectedAffiliateLinkId = $this->selectedAffiliateLinkId === $linkId ? null : $linkId;
    }

    public function saveAdminUser(): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('admins'), 403);

        $emailRule = $this->editingAdminId
            ? 'unique:admins,email,'.$this->editingAdminId
            : 'unique:admins,email';

        $this->validate([
            'newAdminName' => ['required', 'string', 'min:2', 'max:120'],
            'newAdminEmail' => ['required', 'email', 'max:150', $emailRule],
            'newAdminRole' => ['required', 'in:'.implode(',', array_keys(Admin::ROLES))],
            'newAdminGender' => ['nullable', 'in:homme,femme,autre'],
            'newAdminJobTitle' => ['nullable', 'string', 'max:120'],
            'newAdminBio' => ['nullable', 'string', 'max:1500'],
            'newAdminPhone' => ['nullable', 'string', 'max:50'],
            'newAdminBirthDate' => ['nullable', 'date'],
            'newAdminAvatarFile' => ['nullable', 'image', 'max:3072'],
            'newAdminPassword' => [$this->editingAdminId ? 'nullable' : 'required', 'string', 'min:6', 'max:255'],
        ]);

        $payload = [
            'name' => trim($this->newAdminName),
            'email' => strtolower(trim($this->newAdminEmail)),
            'role' => $this->newAdminRole,
            'gender' => $this->newAdminGender ?: null,
            'job_title' => trim($this->newAdminJobTitle) ?: null,
            'bio' => trim($this->newAdminBio) ?: null,
            'phone' => trim($this->newAdminPhone) ?: null,
            'birth_date' => $this->newAdminBirthDate ?: null,
            'is_active' => $this->newAdminActive,
        ];

        if ($this->newAdminAvatarFile) {
            $payload['avatar_path'] = $this->newAdminAvatarFile->store('images/admins', 'public');
            $this->newAdminAvatarFile = null;
        }

        if (trim($this->newAdminPassword) !== '') {
            $payload['password'] = $this->newAdminPassword;
        }

        if ($this->editingAdminId) {
            $target = Admin::findOrFail($this->editingAdminId);
            $target->update($payload);
            $this->feedbackMessage = "Administrateur {$target->name} mis à jour.";
        } else {
            $target = Admin::create($payload);
            $this->feedbackMessage = "Administrateur {$target->name} créé avec succès.";
        }

        AuditLog::record('admin.user_saved', subject: $target, actor: $actor, metadata: ['role' => $target->role]);
        $this->reset([
            'editingAdminId', 'newAdminName', 'newAdminEmail', 'newAdminPassword',
            'newAdminGender', 'newAdminJobTitle', 'newAdminBio', 'newAdminPhone', 'newAdminBirthDate',
        ]);
        $this->newAdminRole = Admin::ROLE_SUPPORT;
        $this->newAdminActive = true;
    }

    public function editAdminUser(int $id): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('admins'), 403);

        $target = Admin::findOrFail($id);
        $this->editingAdminId = $target->id;
        $this->newAdminName = $target->name;
        $this->newAdminEmail = $target->email;
        $this->newAdminRole = $target->role ?: Admin::ROLE_SUPER_ADMIN;
        $this->newAdminGender = (string) ($target->gender ?? '');
        $this->newAdminJobTitle = (string) ($target->job_title ?? '');
        $this->newAdminBio = (string) ($target->bio ?? '');
        $this->newAdminPhone = (string) ($target->phone ?? '');
        $this->newAdminBirthDate = $target->birth_date ? $target->birth_date->format('Y-m-d') : '';
        $this->newAdminActive = (bool) $target->is_active;
        $this->newAdminPassword = '';
    }

    public function toggleAdminUser(int $id): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('admins'), 403);

        $target = Admin::findOrFail($id);
        if ((int) $target->id === (int) $actor->id) {
            $this->feedbackMessage = 'Vous ne pouvez pas désactiver votre propre compte.';

            return;
        }
        $target->update(['is_active' => ! $target->is_active]);
    }

    public function deleteAdminUser(int $id): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('admins'), 403);

        $target = Admin::findOrFail($id);
        if ((int) $target->id === (int) $actor->id) {
            $this->feedbackMessage = 'Vous ne pouvez pas supprimer votre propre compte.';

            return;
        }
        $target->delete();
        $this->feedbackMessage = 'Administrateur supprimé.';
    }

    public function saveTerms(): void
    {
        $actor = $this->authorizeSection('terms');

        $clean = HtmlSanitizer::clean($this->termsContent);
        $this->termsContent = $clean;
        PlatformSetting::setValue('terms_of_use', $clean);
        AuditLog::record('admin.terms_updated', actor: $actor);
        $this->feedbackMessage = 'Conditions d\'utilisation enregistrées.';
    }

    public function savePrivacy(): void
    {
        $actor = $this->authorizeSection('privacy');

        $clean = HtmlSanitizer::clean($this->privacyContent);
        $this->privacyContent = $clean;
        PlatformSetting::setValue('privacy_policy', $clean);
        AuditLog::record('admin.privacy_updated', actor: $actor);
        $this->feedbackMessage = 'Politique de confidentialité enregistrée.';
    }

    public function selectAiConversation(int $conversationId): void
    {
        $this->selectedAiConversationId = $conversationId;
        $this->editingAiMessageId = null;
        $this->editingAiMessageContent = '';
    }

    public function restoreAiConversation(int $conversationId): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('ai_diagnostics'), 403);

        $conv = AiDiagnosticConversation::withoutTenantScope()->withTrashed()->findOrFail($conversationId);
        $conv->restore();
        AuditLog::record('admin.ai_conversation_restored', subject: $conv, actor: $actor);
        $this->feedbackMessage = 'Conversation IA restaurée dans l\'espace du garage.';
    }

    public function forceDeleteAiConversation(int $conversationId): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('ai_diagnostics'), 403);

        $conv = AiDiagnosticConversation::withoutTenantScope()->withTrashed()->findOrFail($conversationId);
        $conv->forceDelete();
        if ($this->selectedAiConversationId === $conversationId) {
            $this->selectedAiConversationId = null;
        }
        AuditLog::record('admin.ai_conversation_force_deleted', actor: $actor, metadata: ['conversation_id' => $conversationId]);
        $this->feedbackMessage = 'Conversation IA supprimée définitivement.';
    }

    public function toggleAiKnowledgeApproval(int $messageId): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('ai_diagnostics'), 403);

        $msg = AiDiagnosticMessage::withoutTenantScope()->findOrFail($messageId);
        $msg->update(['is_approved_for_knowledge' => ! $msg->is_approved_for_knowledge]);
        AuditLog::record('admin.ai_knowledge_toggled', subject: $msg, actor: $actor);
        $this->feedbackMessage = $msg->is_approved_for_knowledge
            ? 'Réponse autorisée dans la base de connaissances IA.'
            : 'Réponse exclue de la base de connaissances IA.';
    }

    public function markAiMessageResolved(int $messageId, bool $resolved): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('ai_diagnostics'), 403);

        $msg = AiDiagnosticMessage::withoutTenantScope()->findOrFail($messageId);
        $msg->update([
            'is_resolved' => $resolved,
            'feedback_at' => now(),
        ]);
        AuditLog::record('admin.ai_message_resolution_updated', subject: $msg, actor: $actor);
        $this->feedbackMessage = $resolved
            ? 'Diagnostic marqué comme résolu (éligible à la réutilisation IA).'
            : 'Diagnostic marqué comme non résolu.';
    }

    public function startEditAiMessage(int $messageId): void
    {
        $msg = AiDiagnosticMessage::withoutTenantScope()->findOrFail($messageId);
        $this->editingAiMessageId = $msg->id;
        $this->editingAiMessageContent = $msg->content;
    }

    public function cancelEditAiMessage(): void
    {
        $this->editingAiMessageId = null;
        $this->editingAiMessageContent = '';
    }

    public function saveEditAiMessage(): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('ai_diagnostics'), 403);

        if (! $this->editingAiMessageId) {
            return;
        }

        $this->validate([
            'editingAiMessageContent' => ['required', 'string', 'min:5', 'max:8000'],
        ]);

        $msg = AiDiagnosticMessage::withoutTenantScope()->findOrFail($this->editingAiMessageId);
        $msg->update(['content' => trim($this->editingAiMessageContent)]);

        AuditLog::record('admin.ai_message_moderated', subject: $msg, actor: $actor);
        $this->editingAiMessageId = null;
        $this->editingAiMessageContent = '';
        $this->feedbackMessage = 'Réponse IA mise à jour par modération.';
    }

    public function addPartner(): void
    {
        $this->authorizeSection('partners');
        $this->feedbackMessage = '';
        $this->feedbackError = '';

        $this->validate([
            'partnerName' => ['required', 'string', 'min:2', 'max:150'],
            'partnerWebsiteUrl' => ['nullable', 'url', 'max:255'],
            'partnerSortOrder' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'partnerLogoFile' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $logoPath = $this->partnerLogoFile->store('partners', 'public');

        $partner = Partner::create([
            'name' => trim($this->partnerName),
            'logo_path' => $logoPath,
            'website_url' => trim($this->partnerWebsiteUrl) ?: null,
            'sort_order' => max(0, (int) $this->partnerSortOrder),
            'is_active' => true,
        ]);

        AuditLog::record('admin.partner_created', subject: $partner, actor: Auth::guard('admin')->user(), metadata: ['name' => $partner->name]);
        $this->reset('partnerName', 'partnerWebsiteUrl', 'partnerSortOrder', 'partnerLogoFile');
        $this->feedbackMessage = "Partenaire « {$partner->name} » ajouté avec succès.";
    }

    public function togglePartner(int $id): void
    {
        $this->authorizeSection('partners');

        $partner = Partner::findOrFail($id);
        $partner->update(['is_active' => ! $partner->is_active]);
        AuditLog::record('admin.partner_toggled', subject: $partner, actor: Auth::guard('admin')->user());
        $this->feedbackMessage = $partner->is_active
            ? "Partenaire « {$partner->name} » activé sur la landing page."
            : "Partenaire « {$partner->name} » masqué de la landing page.";
    }

    public function deletePartner(int $id): void
    {
        $this->authorizeSection('partners');

        $partner = Partner::findOrFail($id);
        if ($partner->logo_path && Storage::disk('public')->exists($partner->logo_path)) {
            Storage::disk('public')->delete($partner->logo_path);
        }
        $name = $partner->name;
        $partner->delete();
        AuditLog::record('admin.partner_deleted', actor: Auth::guard('admin')->user(), metadata: ['name' => $name]);
        $this->feedbackMessage = "Partenaire « {$name} » supprimé.";
    }

    public function render()
    {
        // Charge TOUS les établissements (garages, centres techniques, assurances auto) sans limite arbitraire
        $allTenants = Tenant::query()
            ->with(['users' => fn ($q) => $q->withoutGlobalScope('tenant')->orderBy('id')])
            ->latest('id')
            ->get();

        $tenantMeta = [];
        $expiringSoonCount = 0;
        foreach ($allTenants as $t) {
            $exp = $this->resolveTenantExpiration($t);
            $phone = $this->resolveTenantPhone($t);
            $settings = $t->settings ?? [];
            if ($exp['can_remind']) {
                $expiringSoonCount++;
            }
            $tenantMeta[$t->id] = [
                'expires_at' => $exp['expires_at'],
                'days_remaining' => $exp['days_remaining'],
                'can_remind' => $exp['can_remind'],
                'context' => $exp['context'],
                'phone' => $phone,
                'email' => trim((string) ($t->email ?: $t->users->first()?->email)),
                'last_email_reminder_at' => $settings['last_email_reminder_at'] ?? $settings['last_sub_reminder_at'] ?? null,
                'last_whatsapp_reminder_at' => $settings['last_whatsapp_reminder_at'] ?? null,
            ];
        }

        $activeTenants = $allTenants->where('status', Tenant::STATUS_ACTIVE)->count();
        $trialTenants = $allTenants->where('status', Tenant::STATUS_TRIALING)->count();
        $pastDueTenants = $allTenants->where('status', Tenant::STATUS_PAST_DUE)->count();
        $expiredTenants = $allTenants->where('status', Tenant::STATUS_EXPIRED)->count();
        $suspendedTenants = $allTenants->where('status', Tenant::STATUS_SUSPENDED)->count();
        $totalTenants = $allTenants->count();

        $tenantCounts = [
            'total' => $totalTenants,
            'garage' => $allTenants->filter(fn ($t) => ($t->type ?? Tenant::TYPE_GARAGE) === Tenant::TYPE_GARAGE)->count(),
            'centre_technique' => $allTenants->where('type', Tenant::TYPE_TECHNICAL_CENTER)->count(),
            'assurance' => $allTenants->where('type', Tenant::TYPE_INSURANCE)->count(),
            'expiring_7d' => $expiringSoonCount,
        ];

        // Filtrage pour la vue /admin/garages
        $searchTerm = mb_strtolower(trim($this->tenantSearch));
        $filteredTenants = $allTenants->filter(function (Tenant $t) use ($searchTerm, $tenantMeta): bool {
            $type = $t->type ?: Tenant::TYPE_GARAGE;
            if ($this->tenantTypeFilter !== 'all' && $type !== $this->tenantTypeFilter) {
                return false;
            }

            if ($this->tenantStatusFilter === 'expiring_7d') {
                if (empty($tenantMeta[$t->id]['can_remind'])) {
                    return false;
                }
            } elseif ($this->tenantStatusFilter !== 'all' && $t->status !== $this->tenantStatusFilter) {
                return false;
            }

            if ($searchTerm !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $t->name,
                    $t->slug,
                    $t->email,
                    $t->phone,
                    $t->city,
                    $tenantMeta[$t->id]['phone'] ?? '',
                ]));
                if (! str_contains($haystack, $searchTerm)) {
                    return false;
                }
            }

            return true;
        })->values();

        // Analytics financiers & abonnés (comparatifs mensuels)
        $subscriptionPayments = Payment::withoutTenantScope()
            ->where('status', Payment::STATUS_SUCCESS)
            ->where('payable_type', Tenant::class)
            ->get(['id', 'tenant_id', 'amount', 'paid_at', 'created_at']);

        $totalRevenue = (int) $subscriptionPayments->sum('amount');
        $totalPaymentsCount = $subscriptionPayments->count();

        $monthsCount = in_array($this->analyticsMonths, [6, 12], true) ? $this->analyticsMonths : 6;
        $monthBuckets = [];
        // On calcule $monthsCount + 1 mois pour avoir la variation du premier mois affiché
        for ($i = $monthsCount; $i >= 0; $i--) {
            $monthStart = now()->startOfMonth()->subMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $key = $monthStart->format('Y-m');

            $monthPayments = $subscriptionPayments->filter(function ($p) use ($monthStart, $monthEnd): bool {
                $dt = $p->paid_at ?: $p->created_at;

                return $dt && $dt->between($monthStart, $monthEnd);
            });

            $monthNewTenants = $allTenants->filter(
                fn (Tenant $t) => $t->created_at && $t->created_at->between($monthStart, $monthEnd)
            );

            $monthTrialSignups = $monthNewTenants->filter(
                fn (Tenant $t) => $t->status === Tenant::STATUS_TRIALING || $t->trial_ends_at !== null
            )->count();

            $monthBuckets[] = [
                'key' => $key,
                'label' => ucfirst($monthStart->locale('fr')->translatedFormat('F Y')),
                'short_label' => ucfirst($monthStart->locale('fr')->translatedFormat('M y')),
                'revenue' => (int) $monthPayments->sum('amount'),
                'transactions_count' => $monthPayments->count(),
                'paid_subscribers' => $monthPayments->pluck('tenant_id')->filter()->unique()->count(),
                'new_tenants' => $monthNewTenants->count(),
                'trial_signups' => $monthTrialSignups,
            ];
        }

        $maxMonthRevenue = max(1, (int) collect($monthBuckets)->slice(1)->max('revenue'));
        $monthlyComparison = [];
        for ($idx = 1; $idx < count($monthBuckets); $idx++) {
            $curr = $monthBuckets[$idx];
            $prev = $monthBuckets[$idx - 1];
            $monthlyComparison[] = [
                'key' => $curr['key'],
                'label' => $curr['label'],
                'short_label' => $curr['short_label'],
                'revenue' => $curr['revenue'],
                'transactions_count' => $curr['transactions_count'],
                'paid_subscribers' => $curr['paid_subscribers'],
                'new_tenants' => $curr['new_tenants'],
                'trial_signups' => $curr['trial_signups'],
                'revenue_trend' => $this->computeTrend($curr['revenue'], $prev['revenue']),
                'paid_trend' => $this->computeTrend($curr['paid_subscribers'], $prev['paid_subscribers']),
                'new_tenants_trend' => $this->computeTrend($curr['new_tenants'], $prev['new_tenants']),
                'trial_trend' => $this->computeTrend($curr['trial_signups'], $prev['trial_signups']),
                'bar_percent' => $curr['revenue'] > 0 ? max(6, (int) round(($curr['revenue'] / $maxMonthRevenue) * 100)) : 0,
            ];
        }

        $currentBucket = end($monthlyComparison) ?: [
            'revenue' => 0, 'paid_subscribers' => 0, 'new_tenants' => 0, 'trial_signups' => 0, 'transactions_count' => 0,
            'revenue_trend' => $this->computeTrend(0, 0),
            'paid_trend' => $this->computeTrend(0, 0),
            'new_tenants_trend' => $this->computeTrend(0, 0),
            'trial_trend' => $this->computeTrend(0, 0),
        ];
        $previousBucket = count($monthlyComparison) >= 2 ? $monthlyComparison[count($monthlyComparison) - 2] : [
            'revenue' => 0, 'paid_subscribers' => 0, 'new_tenants' => 0, 'trial_signups' => 0, 'transactions_count' => 0,
        ];

        $analytics = [
            'total_revenue' => $totalRevenue,
            'total_payments_count' => $totalPaymentsCount,
            'current_month_revenue' => $currentBucket['revenue'],
            'previous_month_revenue' => $previousBucket['revenue'],
            'revenue_trend' => $currentBucket['revenue_trend'],
            'current_month_paid' => $currentBucket['paid_subscribers'],
            'previous_month_paid' => $previousBucket['paid_subscribers'],
            'paid_trend' => $currentBucket['paid_trend'],
            'current_month_new_tenants' => $currentBucket['new_tenants'],
            'previous_month_new_tenants' => $previousBucket['new_tenants'],
            'new_tenants_trend' => $currentBucket['new_tenants_trend'],
            'current_month_trials' => $currentBucket['trial_signups'],
            'previous_month_trials' => $previousBucket['trial_signups'],
            'trial_trend' => $currentBucket['trial_trend'],
            'conversion_rate' => $totalTenants > 0 ? round(($activeTenants / $totalTenants) * 100, 1) : 0.0,
            'monthly_comparison' => array_reverse($monthlyComparison),
            'monthly_chart' => $monthlyComparison,
        ];

        $adminMessages = PlatformMessage::with('tenant')
            ->where(function ($q) {
                $q->where('sender_type', PlatformMessage::TYPE_ADMIN)
                    ->orWhere('recipient_type', PlatformMessage::TYPE_ADMIN);
            })
            ->latest('id')
            ->take(25)
            ->get();

        $unreadAdminMessages = PlatformMessage::where('recipient_type', PlatformMessage::TYPE_ADMIN)
            ->where('is_read', false)
            ->count();

        $aiStats = [
            'totalDiagnostics' => AiDiagnosticMessage::withoutTenantScope()
                ->where('role', AiDiagnosticMessage::ROLE_USER)
                ->count(),
            'todayDiagnostics' => AiDiagnosticMessage::withoutTenantScope()
                ->where('role', AiDiagnosticMessage::ROLE_USER)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
            'totalTokens' => (int) AiDiagnosticMessage::withoutTenantScope()
                ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
                ->sum('tokens_used'),
            'resolvedCount' => AiDiagnosticMessage::withoutTenantScope()
                ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
                ->where('is_resolved', true)
                ->count(),
            'kbReuseCount' => (int) AiDiagnosticMessage::withoutTenantScope()
                ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
                ->sum('reuse_count'),
            'deletedConversationsCount' => AiDiagnosticConversation::withoutTenantScope()
                ->onlyTrashed()
                ->count(),
            'topTenants' => AiDiagnosticMessage::withoutTenantScope()
                ->selectRaw('tenant_id, COUNT(*) as diagnostics_count')
                ->where('role', AiDiagnosticMessage::ROLE_USER)
                ->groupBy('tenant_id')
                ->orderByDesc('diagnostics_count')
                ->take(5)
                ->get()
                ->map(function ($row) {
                    $t = Tenant::find($row->tenant_id);

                    return [
                        'name' => $t?->name ?? "Garage #{$row->tenant_id}",
                        'count' => (int) $row->diagnostics_count,
                    ];
                })
                ->all(),
        ];

        $aiConversations = collect();
        $selectedAiConversation = null;

        if ($this->activeSection === 'ai_diagnostics') {
            $search = trim($this->aiSearch);
            $aiConversations = AiDiagnosticConversation::withoutTenantScope()
                ->withTrashed()
                ->with(['tenant:id,name,slug', 'user:id,name', 'vehicle:id,license_plate,brand,model'])
                ->withCount('messages')
                ->when($this->aiFilter === 'deleted', fn ($q) => $q->onlyTrashed())
                ->when($this->aiFilter === 'resolved', fn ($q) => $q->whereHas('messages', fn ($mq) => $mq->withoutTenantScope()->where('is_resolved', true)))
                ->when($this->aiFilter === 'unresolved', fn ($q) => $q->whereHas('messages', fn ($mq) => $mq->withoutTenantScope()->where('is_resolved', false)))
                ->when($this->aiFilter === 'off_topic', fn ($q) => $q->whereHas('messages', fn ($mq) => $mq->withoutTenantScope()->where('is_off_topic', true)))
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($sub) use ($search) {
                        $sub->where('title', 'like', "%{$search}%")
                            ->orWhereHas('tenant', fn ($tq) => $tq->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('messages', fn ($mq) => $mq->withoutTenantScope()->where('content', 'like', "%{$search}%"));
                    });
                })
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->take(60)
                ->get();

            $targetConvId = $this->selectedAiConversationId ?: $aiConversations->first()?->id;
            if ($targetConvId) {
                $selectedAiConversation = AiDiagnosticConversation::withoutTenantScope()
                    ->withTrashed()
                    ->with([
                        'tenant:id,name,slug,email',
                        'user:id,name,email',
                        'vehicle:id,license_plate,brand,model,year',
                        'messages' => fn ($mq) => $mq->withoutTenantScope()->with('user:id,name')->orderBy('id'),
                    ])
                    ->find($targetConvId);
            }
        }

        return view('livewire.admin.platform-dashboard', [
            'aiStats' => $aiStats,
            'aiConversations' => $aiConversations,
            'selectedAiConversation' => $selectedAiConversation,
            'analytics' => $analytics,
            'tenantCounts' => $tenantCounts,
            'tenantMeta' => $tenantMeta,
            'filteredTenants' => $filteredTenants,
            'kpis' => [
                'activeTenants' => $activeTenants,
                'trialTenants' => $trialTenants,
                'pastDueTenants' => $pastDueTenants,
                'expiredTenants' => $expiredTenants,
                'suspendedTenants' => $suspendedTenants,
                'totalTenants' => $totalTenants,
                'expiringSoonCount' => $expiringSoonCount,
                'mrr' => $activeTenants * $this->subscriptionPrice,
            ],
            'tenants' => $allTenants,
            'blockedIps' => BlockedIp::latest()->take(25)->get(),
            'recentPayments' => Payment::withoutTenantScope()->with('tenant:id,name,slug,type')->latest()->take(15)->get(),
            'auditLogs' => AuditLog::latest('id')->take(20)->get(),
            'testimonials' => Testimonial::latest()->get(),
            'partners' => Partner::orderBy('sort_order')->orderByDesc('id')->get(),
            'countries' => Country::orderBy('name')->get(),
            'mpCategories' => Category::whereNull('tenant_id')->with('subCategories')->orderBy('name')->get(),
            'mpBanners' => MarketplaceBanner::latest('id')->get(),
            'admins' => Admin::orderBy('id')->get(),
            'adminRoles' => Admin::ROLES,
            'affiliateLinks' => AffiliateLink::with(['tenant:id,name,email,city,country', 'user:id,name,email'])
                ->withCount('referrals')
                ->latest('id')
                ->get(),
            'selectedAffiliateLink' => $this->selectedAffiliateLinkId
                ? AffiliateLink::with(['tenant:id,name', 'referrals.referredTenant:id,name,slug,email,phone,city,country,status,created_at', 'referrals.referredUser:id,name,email,phone'])
                    ->find($this->selectedAffiliateLinkId)
                : null,
            'adminMessages' => $adminMessages,
            'unreadAdminMessages' => $unreadAdminMessages,
        ]);
    }
}
