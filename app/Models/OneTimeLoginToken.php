<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Jeton de connexion automatique à usage unique et courte durée
 * utilisé lors de la redirection vers le sous-domaine du tenant après inscription.
 */
class OneTimeLoginToken extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
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

    /**
     * Génère un jeton brut (retourné une seule fois) et stocke son empreinte SHA-256.
     */
    public static function issueFor(Tenant $tenant, User $user, int $ttlMinutes = 5): string
    {
        $plainToken = Str::random(64);

        static::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $plainToken;
    }

    /**
     * Consomme un jeton valide pour le tenant donné (usage unique).
     */
    public static function consume(string $plainToken, int $tenantId): ?self
    {
        $hash = hash('sha256', $plainToken);

        $record = static::where('token_hash', $hash)
            ->where('tenant_id', $tenantId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            return null;
        }

        $record->update(['used_at' => now()]);

        return $record;
    }
}
