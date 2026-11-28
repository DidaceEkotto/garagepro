<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantConversation extends Model
{
    protected $fillable = [
        'tenant_one_id',
        'tenant_two_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function tenantOne(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_one_id');
    }

    public function tenantTwo(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantConversationMessage::class, 'conversation_id');
    }

    public function involvesTenant(int $tenantId): bool
    {
        return (int) $this->tenant_one_id === $tenantId || (int) $this->tenant_two_id === $tenantId;
    }

    public function otherTenantId(int $myTenantId): int
    {
        return (int) $this->tenant_one_id === $myTenantId
            ? (int) $this->tenant_two_id
            : (int) $this->tenant_one_id;
    }

    public static function findOrCreatePair(int $tenantA, int $tenantB): self
    {
        $one = min($tenantA, $tenantB);
        $two = max($tenantA, $tenantB);

        return self::firstOrCreate([
            'tenant_one_id' => $one,
            'tenant_two_id' => $two,
        ]);
    }
}
