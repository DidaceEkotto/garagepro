<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Représente une adresse IP bloquée ou placée en liste blanche.
 */
class BlockedIp extends Model
{
    protected $fillable = [
        'ip_address',
        'reason',
        'is_whitelisted',
        'permanent',
        'blocked_until',
        'attempts_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_whitelisted' => 'boolean',
            'permanent' => 'boolean',
            'blocked_until' => 'datetime',
            'attempts_count' => 'integer',
        ];
    }

    public function scopeWhitelisted(Builder $query): Builder
    {
        return $query->where('is_whitelisted', true);
    }

    public function scopeActiveBlocked(Builder $query): Builder
    {
        return $query->where('is_whitelisted', false)
            ->where(function (Builder $q) {
                $q->where('permanent', true)
                    ->orWhere('blocked_until', '>', now());
            });
    }

    public static function isWhitelisted(string $ip): bool
    {
        return static::whitelisted()->where('ip_address', $ip)->exists();
    }

    public static function isCurrentlyBlocked(string $ip): bool
    {
        if (static::isWhitelisted($ip)) {
            return false;
        }

        return static::activeBlocked()->where('ip_address', $ip)->exists();
    }

    /**
     * Bloque automatiquement ou manuellement une IP pour une durée donnée.
     */
    public static function blockIp(
        string $ip,
        string $reason,
        ?int $hours = null,
        bool $permanent = false,
        string $createdBy = 'system',
        ?int $minutes = null
    ): self {
        $existing = static::where('ip_address', $ip)
            ->where('is_whitelisted', false)
            ->first();

        $durationMinutes = $minutes ?? ($hours !== null ? $hours * 60 : (int) config('garagepro.security.auto_block_minutes', 20));
        $blockedUntil = $permanent ? null : now()->addMinutes($durationMinutes);

        if ($existing) {
            $existing->update([
                'reason' => $reason,
                'permanent' => $existing->permanent || $permanent,
                'blocked_until' => ($existing->permanent || $permanent) ? null : $blockedUntil,
                'attempts_count' => $existing->attempts_count + 1,
                'created_by' => $createdBy,
            ]);

            return $existing;
        }

        return static::create([
            'ip_address' => $ip,
            'reason' => $reason,
            'is_whitelisted' => false,
            'permanent' => $permanent,
            'blocked_until' => $blockedUntil,
            'attempts_count' => 1,
            'created_by' => $createdBy,
        ]);
    }
}
