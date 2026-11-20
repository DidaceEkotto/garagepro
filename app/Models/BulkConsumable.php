<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkConsumable extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'name',
        'unit',
        'container_capacity',
        'current_quantity',
        'alert_threshold',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'container_capacity' => 'float',
            'current_quantity' => 'float',
            'alert_threshold' => 'float',
            'unit_cost' => 'integer',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BulkConsumableLog::class)->latest('id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
