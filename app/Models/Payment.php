<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'payable_type',
        'payable_id',
        'payment_method',
        'amount',
        'currency',
        'status',
        'provider_payment_id',
        'idempotency_key',
        'reference_code',
        'checkout_url',
        'failure_reason',
        'paid_at',
        'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
