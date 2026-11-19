<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paramètres globaux de la plateforme configurables par le Super-Admin.
 */
class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    public static function setValue(string $key, mixed $value): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value !== null ? (string) $value : null]
        );
    }

    public static function subscriptionPrice(): int
    {
        return (int) static::getValue(
            'subscription_price',
            config('garagepro.default_subscription_price', 25000)
        );
    }

    public static function trialDays(): int
    {
        return (int) static::getValue(
            'trial_days',
            config('garagepro.trial_days', 7)
        );
    }

    public static function gracePeriodDays(): int
    {
        return (int) static::getValue(
            'grace_period_days',
            config('garagepro.grace_period_days', 5)
        );
    }

    public static function structureEmail(): string
    {
        return (string) static::getValue(
            'structure_email',
            config('garagepro.default_structure_email', 'jeannotgates@gmail.com')
        );
    }

    public static function affiliateCommission(): int
    {
        return (int) static::getValue(
            'affiliate_commission',
            AffiliateLink::DEFAULT_COMMISSION
        );
    }

    public static function affiliateReferralDiscount(): int
    {
        return max(0, (int) static::getValue('affiliate_referral_discount', 5000));
    }

    public static function affiliateCommissionTrigger(): string
    {
        $value = (string) static::getValue('affiliate_commission_trigger', 'first_payment');

        return in_array($value, ['signup', 'first_payment'], true) ? $value : 'first_payment';
    }

    public static function isMaintenanceMode(): bool
    {
        return static::getValue('maintenance_mode', '0') === '1';
    }

    public static function maintenanceMessage(): string
    {
        return (string) static::getValue(
            'maintenance_message',
            'Notre plateforme est actuellement en maintenance programmée pour améliorer nos services. Nous revenons très vite !'
        );
    }

    public static function termsOfUse(): string
    {
        return (string) static::getValue(
            'terms_of_use',
            '<h2>1. Objet</h2><p>Les présentes Conditions Générales d\'Utilisation régissent l\'accès et l\'utilisation de la plateforme GaragePro et de son Marketplace.</p><h2>2. Compte Professionnel</h2><p>Chaque garage est responsable de la confidentialité de ses identifiants et de l\'exactitude des informations publiées.</p>'
        );
    }

    public static function privacyPolicy(): string
    {
        return (string) static::getValue(
            'privacy_policy',
            '<h2>1. Collecte des données</h2><p>GaragePro collecte uniquement les données nécessaires à la gestion de votre atelier, à la facturation et au traitement des commandes Marketplace.</p><h2>2. Sécurité &amp; Confidentialité</h2><p>Vos données sont isolées par garage et protégées par chiffrement et journalisation d\'audit.</p>'
        );
    }

    public static function siteSettings(): array
    {
        return [
            'name' => (function (): string {
                $v = (string) static::getValue('site_name', '');
                if ($v !== '' && $v !== 'Laravel') {
                    return $v;
                }
                $appName = (string) config('app.name', 'MboaCar');
                return ($appName !== '' && $appName !== 'Laravel') ? $appName : 'MboaCar';
            })(),
            'email' => static::structureEmail(),
            'secondary_email' => (string) static::getValue('site_secondary_email', ''),
            'phone' => (string) static::getValue('site_phone', ''),
            'whatsapp' => (string) static::getValue('site_whatsapp', ''),
            'po_box' => (string) static::getValue('site_po_box', ''),
            'facebook' => (string) static::getValue('social_facebook', ''),
            'linkedin' => (string) static::getValue('social_linkedin', ''),
            'twitter' => (string) static::getValue('social_twitter', ''),
            'description' => (string) static::getValue('site_description', 'Plateforme SaaS de gestion de garages automobiles et Marketplace de pièces détachées en Afrique.'),
            'keywords' => (string) static::getValue('site_keywords', 'garage, automobile, pièces détachées, marketplace, Afrique'),
            'logo_url' => ($v = static::getValue('site_logo')) ? asset('storage/'.$v) : asset('assets/images/logo.png'),
            'favicon_url' => ($v = static::getValue('site_favicon')) ? asset('storage/'.$v) : null,
            'seo_image_url' => ($v = static::getValue('seo_image')) ? asset('storage/'.$v) : null,
        ];
    }
}
