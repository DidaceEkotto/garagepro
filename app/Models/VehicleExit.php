<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleExit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'repair_order_id',
        'invoice_id',
        'released_by',
        'override_by',
        'override_reason',
        'mileage_out',
        'belongings_returned',
        'old_parts_returned',
        'work_explained',
        'client_signature_path',
        'warranty_months',
        'warranty_notes',
        'exited_at',
    ];

    protected function casts(): array
    {
        return [
            'mileage_out' => 'integer',
            'belongings_returned' => 'boolean',
            'old_parts_returned' => 'boolean',
            'work_explained' => 'boolean',
            'warranty_months' => 'integer',
            'exited_at' => 'datetime',
        ];
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
