<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformMessage extends Model
{
    public const TYPE_ADMIN = 'admin';
    public const TYPE_TENANT = 'tenant';
    public const TYPE_CUSTOMER = 'customer';

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'sender_type',
        'sender_id',
        'sender_name',
        'sender_email',
        'recipient_type',
        'recipient_id',
        'recipient_email',
        'subject',
        'body',
        'attachments',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
