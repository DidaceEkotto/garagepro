<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityCheck extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'repair_order_id',
        'checked_by',
        'checklist',
        'road_test_done',
        'road_test_mileage',
        'passed',
        'notes',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'road_test_done' => 'boolean',
            'road_test_mileage' => 'integer',
            'passed' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }
}
