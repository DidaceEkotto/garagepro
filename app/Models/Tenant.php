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

    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'status',
        'trial_ends_at',
        'subscription_ends_at',
        'grace_ends_at',
        'vat_rate',
        'hourly_rate',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'vat_rate' => 'decimal:2',
            'hourly_rate' => 'integer',
            'settings' => 'array',
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
