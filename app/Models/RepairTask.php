<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tâche d'atelier planifiée sur un mécanicien, un pont ou un sous-traitant.
 */
class RepairTask extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'repair_order_id',
        'assigned_mechanic_id',
        'bay_name',
        'subcontractor_name',
        'subcontractor_type',
        'title',
        'priority',
        'estimated_minutes',
        'actual_minutes',
        'timer_started_at',
        'commission_amount',
        'scheduled_start_at',
        'scheduled_end_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'estimated_minutes' => 'integer',
            'actual_minutes' => 'integer',
            'timer_started_at' => 'datetime',
            'commission_amount' => 'integer',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
        ];
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_mechanic_id');
    }
}
