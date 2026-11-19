<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderItem extends Model
{
    protected $fillable = [
        'marketplace_order_id',
        'marketplace_product_id',
        'tenant_id',
        'product_title',
        'unit_price',
        'quantity',
        'subtotal',
        'seller_status',
        'is_seen_by_seller',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'subtotal' => 'integer',
            'is_seen_by_seller' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'marketplace_product_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
