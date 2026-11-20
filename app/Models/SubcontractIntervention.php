<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubcontractIntervention extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'repair_order_id',
        'vehicle_id',
        'customer_id',
        'type',
        'provider_name',
        'provider_phone',
        'description',
        'location_address',
        'cost_amount',
        'billed_amount',
        'status',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_amount' => 'integer',
            'billed_amount' => 'integer',
            'performed_at' => 'datetime',
        ];
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
