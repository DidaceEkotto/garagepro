<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateReferral extends Model
{
    protected $fillable = [
        'affiliate_link_id',
        'referred_tenant_id',
        'referred_user_id',
        'commission_amount',
        'discount_amount',
        'status',
        'discount_used_at',
        'credited_at',
        'ip_address',
    ];

    protected $casts = [
        'commission_amount' => 'integer',
        'discount_amount' => 'integer',
        'discount_used_at' => 'datetime',
        'credited_at' => 'datetime',
    ];

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }

    public function referredTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'referred_tenant_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public static function availableDiscountForTenant(Tenant $tenant): int
    {
        $referral = static::where('referred_tenant_id', $tenant->id)
            ->whereNull('discount_used_at')
            ->first();

        if (! $referral || $referral->discount_amount <= 0) {
            return 0;
        }

        $hasPaidBefore = Payment::withoutTenantScope()
            ->where('payable_type', Tenant::class)
            ->where('payable_id', $tenant->id)
            ->where('status', Payment::STATUS_SUCCESS)
            ->exists();

        return $hasPaidBefore ? 0 : (int) $referral->discount_amount;
    }

    public static function settleOnFirstSubscriptionPayment(Tenant $tenant): void
    {
        $referral = static::where('referred_tenant_id', $tenant->id)
            ->lockForUpdate()
            ->first();

        if (! $referral) {
            return;
        }

        if ($referral->discount_used_at === null && $referral->discount_amount > 0) {
            $referral->discount_used_at = now();
        }

        if ($referral->status === 'pending' && $referral->commission_amount > 0) {
            $affLink = AffiliateLink::where('id', $referral->affiliate_link_id)
                ->lockForUpdate()
                ->first();

            if ($affLink && ! $affLink->is_blocked) {
                $affLink->increment('balance', $referral->commission_amount);
                $affLink->increment('total_earned', $referral->commission_amount);
                $referral->status = 'credited';
                $referral->credited_at = now();
            }
        }

        $referral->save();
    }
}
