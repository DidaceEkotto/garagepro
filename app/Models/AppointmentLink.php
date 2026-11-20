<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien sécurisé à usage unique (valable 24h) permettant à un client de demander un rendez-vous.
 */
class AppointmentLink extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'created_by',
        'token',
        'label',
        'status',
        'expires_at',
        'submitted_at',
        'confirmed_at',
        'customer_id',
        'vehicle_id',
        'appointment_id',
        'submitted_payload',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'submitted_payload' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired();
    }
}
