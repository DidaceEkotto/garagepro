<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantConversationMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_tenant_id',
        'sender_user_id',
        'sender_name',
        'body',
        'shared_context',
        'attachments',
        'is_urgent',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'shared_context' => 'array',
            'attachments' => 'array',
            'is_urgent' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TenantConversation::class, 'conversation_id');
    }

    public function senderTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'sender_tenant_id');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
