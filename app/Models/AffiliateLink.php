<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliateLink extends Model
{
    public const DEFAULT_COMMISSION = 2500; // FCFA par inscription valide

    protected $fillable = [
        'tenant_id',
        'user_id',
        'code',
        'is_blocked',
        'blocked_reason',
        'clicks_count',
        'balance',
        'total_earned',
    ];

    protected $casts = [
        'is_blocked' => 'boolean',
        'clicks_count' => 'integer',
        'balance' => 'integer',
        'total_earned' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }
}
