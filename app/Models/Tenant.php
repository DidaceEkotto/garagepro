<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Représente un garage client (société) sur la plateforme SaaS.
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_GARAGE = 'garage';
    public const TYPE_TECHNICAL_CENTER = 'centre_technique';
    public const TYPE_INSURANCE = 'assurance';
    public const TYPE_PARTS_SHOP = 'boutique_pieces';

    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'latitude',
        'longitude',
        'formatted_location',
        'status',
        'b2b_directory_visible',
        'sos_enabled',
        'sos_radius_km',
        'whatsapp_bot_enabled',
        'whatsapp_bot_secret',
        'whatsapp_bot_catalog',
        'trial_ends_at',
        'subscription_ends_at',
        'grace_ends_at',
        'vat_rate',
        'hourly_rate',
        'settings',
        'showcase_photos',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'b2b_directory_visible' => 'boolean',
            'sos_enabled' => 'boolean',
            'sos_radius_km' => 'integer',
            'whatsapp_bot_enabled' => 'boolean',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'vat_rate' => 'decimal:2',
            'hourly_rate' => 'integer',
            'settings' => 'array',
            'showcase_photos' => 'array',
        ];
    }

    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(TenantService::class);
    }

    public function clientReviews(): HasMany
    {
        return $this->hasMany(ClientReview::class);
    }

    /**
     * Retourne les URLs publiques (relatives, sans dépendre du symlink storage:link) des photos vitrine.
     *
     * @return array<int, string>
     */
    public function showcasePhotoUrls(): array
    {
        $photos = is_array($this->showcase_photos) ? $this->showcase_photos : [];

        return collect($photos)
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->values()
            ->map(fn (string $p, int $idx) => route('public.garage.showcase_image', [
                'tenant' => $this->id,
                'index' => $idx,
                'v' => substr(md5($p), 0, 8),
            ], false))
            ->all();
    }

    public function isInTrialGrace(): bool
    {
        if ($this->status !== self::STATUS_TRIALING || ! $this->trial_ends_at) {
            return false;
        }

        return now()->gt($this->trial_ends_at)
            && now()->lte($this->trial_ends_at->copy()->addDays(2));
    }

    public function isInSubscriptionGrace(): bool
    {
        if ($this->status === self::STATUS_PAST_DUE) {
            return ! $this->grace_ends_at || now()->lte($this->grace_ends_at);
        }

        if ($this->status === self::STATUS_ACTIVE && $this->subscription_ends_at) {
            $graceDays = PlatformSetting::gracePeriodDays();

            return now()->gt($this->subscription_ends_at)
                && now()->lte($this->subscription_ends_at->copy()->addDays($graceDays));
        }

        return false;
    }

    public function isReadOnly(): bool
    {
        if (in_array($this->status, [
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
            self::STATUS_SUSPENDED,
        ], true)) {
            return true;
        }

        if ($this->status === self::STATUS_TRIALING && $this->trial_ends_at) {
            return now()->gt($this->trial_ends_at->copy()->addDays(2));
        }

        if ($this->status === self::STATUS_PAST_DUE && $this->grace_ends_at) {
            return now()->gt($this->grace_ends_at);
        }

        if ($this->status === self::STATUS_ACTIVE && $this->subscription_ends_at) {
            $graceDays = PlatformSetting::gracePeriodDays();

            return now()->gt($this->subscription_ends_at->copy()->addDays($graceDays));
        }

        return false;
    }

    public function hasActiveAccess(): bool
    {
        return in_array($this->status, [
            self::STATUS_TRIALING,
            self::STATUS_ACTIVE,
            self::STATUS_PAST_DUE,
        ], true);
    }
}
