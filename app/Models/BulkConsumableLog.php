<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkConsumableLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'bulk_consumable_id',
        'repair_order_id',
        'user_id',
        'type',
        'quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
        ];
    }

    public function consumable(): BelongsTo
    {
        return $this->belongsTo(BulkConsumable::class, 'bulk_consumable_id');
    }

    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
