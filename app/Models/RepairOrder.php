<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ordre de réparation (OR) avec suivi de diagnostic, tâches et totaux en FCFA.
 */
class RepairOrder extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_PENDING = 'en_attente';
    public const STATUS_DIAGNOSTIC = 'diagnostic';
    public const STATUS_QUOTE_SENT = 'devis_envoye';
    public const STATUS_IN_PROGRESS = 'en_cours';
    public const STATUS_PAUSED = 'en_pause';
    public const STATUS_WAITING_PARTS = 'attente_piece';
    public const STATUS_QUALITY_CHECK = 'controle_qualite';
    public const STATUS_COMPLETED = 'termine';
    public const STATUS_DELIVERED = 'livre';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'customer_id',
        'vehicle_id',
        'reception_id',
        'assigned_mechanic_id',
        'order_number',
        'tracking_token',
        'status',
        'priority',
        'diagnostic_started_at',
        'diagnostic_ended_at',
        'diagnostic_checklist',
        'fault_codes',
        'diagnostic_notes',
        'subtotal_ht',
        'discount_amount',
        'vat_amount',
        'total_ttc',
    ];

    protected static function booted(): void
    {
        static::creating(function (RepairOrder $order) {
            if (empty($order->tracking_token)) {
                $order->tracking_token = Str::random(24);
            }
        });
    }

    public function ensureTrackingToken(): string
    {
        if (empty($this->tracking_token)) {
            $this->update(['tracking_token' => Str::random(24)]);
        }

        return $this->tracking_token;
    }

    protected function casts(): array
    {
        return [
            'diagnostic_started_at' => 'datetime',
            'diagnostic_ended_at' => 'datetime',
            'diagnostic_checklist' => 'array',
            'fault_codes' => 'array',
            'subtotal_ht' => 'integer',
            'discount_amount' => 'integer',
            'vat_amount' => 'integer',
            'total_ttc' => 'integer',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(VehicleReception::class, 'reception_id');
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_mechanic_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RepairTask::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
