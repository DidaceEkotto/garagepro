<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2bNetworkRequest extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'city',
        'category',
        'title',
        'description',
        'is_urgent',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_urgent' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
