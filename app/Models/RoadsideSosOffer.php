<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoadsideSosOffer extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'sos_request_id',
        'tenant_id',
        'user_id',
        'price',
        'eta_minutes',
        'distance_km',
        'message',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'eta_minutes' => 'integer',
            'distance_km' => 'float',
        ];
    }

    public function sosRequest(): BelongsTo
    {
        return $this->belongsTo(RoadsideSosRequest::class, 'sos_request_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
