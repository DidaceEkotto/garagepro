<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'agency_id',
        'sku',
        'name',
        'category',
        'condition',
        'location',
        'quantity_on_hand',
        'quantity_reserved',
        'min_threshold',
        'purchase_price',
        'selling_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'integer',
            'quantity_reserved' => 'integer',
            'min_threshold' => 'integer',
            'purchase_price' => 'integer',
            'selling_price' => 'integer',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function availableQuantity(): int
    {
        return max(0, $this->quantity_on_hand - $this->quantity_reserved);
    }

    public function isLowStock(): bool
    {
        return $this->availableQuantity() <= $this->min_threshold;
    }
}
