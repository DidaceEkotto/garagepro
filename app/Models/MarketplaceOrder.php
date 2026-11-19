<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceOrder extends Model
{
    protected $fillable = [
        'order_number',
        'buyer_name',
        'buyer_email',
        'country_code',
        'dial_code',
        'buyer_whatsapp',
        'delivery_address',
        'notes',
        'total_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class);
    }
}
