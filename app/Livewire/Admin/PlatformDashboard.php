<?php

namespace App\Livewire\Admin;

use App\Models\Admin;
use App\Models\AffiliateLink;
use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\Category;
use App\Models\Country;
use App\Models\MarketplaceBanner;
use App\Models\OneTimeLoginToken;
use App\Models\Payment;
use App\Models\PlatformMessage;
use App\Models\PlatformSetting;
use App\Models\SubCategory;
use App\Models\Tenant;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Auth;
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
    public int $trialDays = 7;
    public int $gracePeriodDays = 5;
    public string $structureEmail = 'jeannotgates@gmail.com';
    public int $affiliateCommission = 2500;
    public int $affiliateReferralDiscount = 5000;
    public string $affiliateCommissionTrigger = 'first_payment';
    public string $siteName = 'GaragePro';
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

    public string $testiAuthor = '';
    public string $testiRole = 'Gérant';
    public string $testiGarage = '';
    public string $testiCity = '';
    public string $testiQuote = '';
    public int $testiRating = 5;

    public function setSection(string $section): void
    {
        $admin = Auth::guard('admin')->user();
        if ($admin && ! $admin->canAccessSection($section)) {
            abort(403, 'Votre rôle administrateur ne vous autorise pas à accéder à cette section.');
        }

        $allowed = [
            'overview', 'tenants', 'messages', 'affiliation',
            'categories', 'banners', 'testimonials', 'countries',
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
        $this->trialDays = max(1, min(30, PlatformSetting::trialDays()));
        $this->gracePeriodDays = PlatformSetting::gracePeriodDays();
        $this->structureEmail = PlatformSetting::structureEmail();
        $this->affiliateCommission = PlatformSetting::affiliateCommission();
        $this->affiliateReferralDiscount = PlatformSetting::affiliateReferralDiscount();
        $this->affiliateCommissionTrigger = PlatformSetting::affiliateCommissionTrigger();
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
        $this->validate([
            'subscriptionPrice' => ['required', 'integer', 'min:1000'],
            'trialDays' => ['required', 'integer', 'min:1', 'max:30'],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:30'],
            'structureEmail' => ['required', 'email', 'max:150'],
            'affiliateCommission' => ['required', 'integer', 'min:0', 'max:500000'],
            'affiliateReferralDiscount' => ['required', 'integer', 'min:0', 'max:500000'],
            'affiliateCommissionTrigger' => ['required', 'in:first_payment,signup'],
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
        PlatformSetting::setValue('trial_days', $this->trialDays);
        PlatformSetting::setValue('grace_period_days', $this->gracePeriodDays);
        PlatformSetting::setValue('structure_email', strtolower(trim($this->structureEmail)));
        PlatformSetting::setValue('affiliate_commission', $this->affiliateCommission);
        PlatformSetting::setValue('affiliate_referral_discount', $this->affiliateReferralDiscount);
        PlatformSetting::setValue('affiliate_commission_trigger', $this->affiliateCommissionTrigger);
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
        $tenant = Tenant::findOrFail($tenantId);
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        AuditLog::record('admin.tenant_suspended', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id);
        $this->feedbackMessage = "Tenant {$tenant->name} suspendu.";
    }

    public function reactivateTenant(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);

        AuditLog::record('admin.tenant_reactivated', subject: $tenant, actor: Auth::guard('admin')->user(), tenantId: $tenant->id);
        $this->feedbackMessage = "Tenant {$tenant->name} réactivé.";
    }

    public function extendTrial(int $tenantId, int $days = 7): void
    {
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
        $tenant = Tenant::with('users')->findOrFail($tenantId);
        $gerant = $tenant->users()->first();
        if (! $gerant) {
            $this->feedbackMessage = 'Aucun utilisateur trouvé pour ce tenant.';

            return;
        }

        $token = OneTimeLoginToken::issueFor($tenant, $gerant, ttlMinutes: 2);
        $baseDomain = config('garagepro.tenant_base_domain', 'garagepro.app');
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

    public function blockIpManual(): void
    {
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
        $record = BlockedIp::findOrFail($id);
        $record->delete();
        AuditLog::record('admin.ip_unblocked', actor: Auth::guard('admin')->user(), metadata: ['ip' => $record->ip_address]);
        $this->feedbackMessage = "IP {$record->ip_address} débloquée.";
    }

    public function addTestimonial(): void
    {
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
        $t = Testimonial::findOrFail($id);
        $t->update(['is_published' => ! $t->is_published]);
    }

    public function deleteTestimonial(int $id): void
    {
        Testimonial::findOrFail($id)->delete();
        $this->feedbackMessage = 'Témoignage supprimé.';
    }

    public function addCountry(): void
    {
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
        $country = Country::findOrFail($id);
        $country->update(['is_active' => ! $country->is_active]);
    }

    public function sendAdminMessage(): void
    {
        $this->validate([
            'adminMsgTenantId' => ['required', 'integer', 'exists:tenants,id'],
            'adminMsgSubject' => ['required', 'string', 'min:2', 'max:200'],
            'adminMsgBody' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        $admin = Auth::guard('admin')->user();

        PlatformMessage::create([
            'tenant_id' => $this->adminMsgTenantId,
            'sender_type' => PlatformMessage::TYPE_ADMIN,
            'sender_id' => $admin?->id,
            'sender_name' => 'Administration GaragePro',
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
        $this->replyingMsgId = $messageId;
        $this->adminReplyBody = '';
        $msg = PlatformMessage::find($messageId);
        if ($msg && $msg->recipient_type === PlatformMessage::TYPE_ADMIN && ! $msg->is_read) {
            $msg->update(['is_read' => true, 'read_at' => now()]);
        }
    }

    public function submitAdminReply(): void
    {
        if (! $this->replyingMsgId) {
            return;
        }

        $this->validate([
            'adminReplyBody' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        $original = PlatformMessage::findOrFail($this->replyingMsgId);
        $admin = Auth::guard('admin')->user();
        $subject = str_starts_with($original->subject, 'Re:') ? $original->subject : 'Re: '.$original->subject;

        PlatformMessage::create([
            'tenant_id' => $original->tenant_id,
            'parent_id' => $original->id,
            'sender_type' => PlatformMessage::TYPE_ADMIN,
            'sender_id' => $admin?->id,
            'sender_name' => 'Administration GaragePro',
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
        Category::findOrFail($id)->delete();
        $this->feedbackMessage = 'Catégorie supprimée.';
    }

    public function saveBanner(): void
    {
        $this->validate([
            'bannerTitle' => ['required', 'string', 'min:2', 'max:180'],
            'bannerSubtitle' => ['nullable', 'string', 'max:255'],
            'bannerPlacement' => ['required', 'in:top,row_1,row_2'],
            'bannerGradientFrom' => ['required', 'string', 'max:30'],
            'bannerGradientTo' => ['required', 'string', 'max:30'],
            'bannerButtonText' => ['nullable', 'string', 'max:80'],
            'bannerButtonUrl' => ['nullable', 'string', 'max:500'],
            'bannerImage' => ['nullable', 'image', 'max:5120'],
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
        $b = MarketplaceBanner::findOrFail($id);
        $b->update(['is_active' => ! $b->is_active]);
    }

    public function deleteBanner(int $id): void
    {
        MarketplaceBanner::findOrFail($id)->delete();
        $this->feedbackMessage = 'Bannière supprimée.';
    }

    public function toggleAffiliateBlock(int $linkId): void
    {
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
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('terms'), 403);

        PlatformSetting::setValue('terms_of_use', $this->termsContent);
        AuditLog::record('admin.terms_updated', actor: $actor);
        $this->feedbackMessage = 'Conditions d\'utilisation enregistrées.';
    }

    public function savePrivacy(): void
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->canAccessSection('privacy'), 403);

        PlatformSetting::setValue('privacy_policy', $this->privacyContent);
        AuditLog::record('admin.privacy_updated', actor: $actor);
        $this->feedbackMessage = 'Politique de confidentialité enregistrée.';
    }

    public function render()
    {
        $activeTenants = Tenant::where('status', Tenant::STATUS_ACTIVE)->count();
        $trialTenants = Tenant::where('status', Tenant::STATUS_TRIALING)->count();
        $pastDueTenants = Tenant::where('status', Tenant::STATUS_PAST_DUE)->count();

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

        return view('livewire.admin.platform-dashboard', [
            'kpis' => [
                'activeTenants' => $activeTenants,
                'trialTenants' => $trialTenants,
                'pastDueTenants' => $pastDueTenants,
                'mrr' => $activeTenants * $this->subscriptionPrice,
            ],
            'tenants' => Tenant::latest()->take(25)->get(),
            'blockedIps' => BlockedIp::latest()->take(25)->get(),
            'recentPayments' => Payment::withoutTenantScope()->latest()->take(15)->get(),
            'auditLogs' => AuditLog::latest('id')->take(20)->get(),
            'testimonials' => Testimonial::latest()->get(),
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
