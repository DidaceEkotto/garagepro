<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Procès-Verbal de Visite Technique (Centre de Contrôle Technique).
 */
class TechnicalInspection extends Model
{
    use BelongsToTenant;

    public const VERDICT_FAVORABLE = 'favorable';
    public const VERDICT_COUNTER_VISIT = 'contre_visite';
    public const VERDICT_IMMOBILIZED = 'immobilisation';

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'vehicle_id',
        'customer_id',
        'inspector_id',
        'pv_number',
        'public_token',
        'inspection_line',
        'mileage',
        'verdict',
        'defects',
        'inspected_at',
        'expires_at',
        'counter_visit_deadline',
        'notes',
        'whatsapp_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'defects' => 'array',
            'mileage' => 'integer',
            'inspected_at' => 'datetime',
            'expires_at' => 'date',
            'counter_visit_deadline' => 'date',
            'whatsapp_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TechnicalInspection $pv) {
            if (empty($pv->public_token)) {
                $pv->public_token = Str::random(32);
            }
            if (empty($pv->pv_number)) {
                $pv->pv_number = 'PV-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
            }
            if (empty($pv->inspected_at)) {
                $pv->inspected_at = now();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
