<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderMessage extends Model
{
    public const SENDER_BUYER = 'buyer';
    public const SENDER_SELLER = 'seller';

    protected $fillable = [
        'marketplace_order_item_id',
        'sender_type',
        'sender_name',
        'body',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrderItem::class, 'marketplace_order_item_id');
    }
}
