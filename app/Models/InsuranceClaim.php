<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InsuranceClaim extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'insurance_tenant_id',
        'garage_tenant_id',
        'insurance_policy_id',
        'repair_order_id',
        'customer_id',
        'vehicle_id',
        'claim_number',
        'incident_date',
        'description',
        'expert_name',
        'estimated_amount',
        'approved_amount',
        'insurance_share_percent',
        'client_deductible_amount',
        'status',
        'photos',
        'expert_notes',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'estimated_amount' => 'integer',
            'approved_amount' => 'integer',
            'insurance_share_percent' => 'integer',
            'client_deductible_amount' => 'integer',
            'photos' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InsuranceClaim $claim) {
            if (empty($claim->claim_number)) {
                $claim->claim_number = 'SIN-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
            }
        });
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function insuranceTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'insurance_tenant_id');
    }

    public function garageTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'garage_tenant_id');
    }
}
