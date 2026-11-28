<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceOrder extends Model
{
    protected $fillable = [
        'order_number',
        'tracking_token',
        'buyer_name',
        'buyer_email',
        'country_code',
        'dial_code',
        'buyer_whatsapp',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'notes',
        'total_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'delivery_latitude' => 'float',
            'delivery_longitude' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MarketplaceOrder $order) {
            if (empty($order->tracking_token)) {
                $order->tracking_token = Str::random(36);
            }
        });
    }

    public function ensureTrackingToken(): string
    {
        if (empty($this->tracking_token)) {
            $this->update(['tracking_token' => Str::random(36)]);
        }

        return $this->tracking_token;
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class);
    }
}
