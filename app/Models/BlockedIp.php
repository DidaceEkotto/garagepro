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
        'last_path',
        'user_agent',
        'is_whitelisted',
        'permanent',
        'blocked_until',
        'attempts_count',
        'last_seen_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_whitelisted' => 'boolean',
            'permanent' => 'boolean',
            'blocked_until' => 'datetime',
            'attempts_count' => 'integer',
            'last_seen_at' => 'datetime',
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

    public function scopeSuspiciousReported(Builder $query): Builder
    {
        return $query->where('is_whitelisted', false)
            ->where('permanent', false)
            ->where(function (Builder $q) {
                $q->whereNull('blocked_until')
                    ->orWhere('blocked_until', '<=', now());
            });
    }

    public static function isWhitelisted(string $ip): bool
    {
        return static::whitelisted()->where('ip_address', $ip)->exists();
    }

    public static function activeBlockFor(string $ip): ?self
    {
        if (static::isWhitelisted($ip)) {
            return null;
        }

        return static::activeBlocked()->where('ip_address', $ip)->first();
    }

    public static function isCurrentlyBlocked(string $ip): bool
    {
        return static::activeBlockFor($ip) !== null;
    }

    /**
     * Enregistre une activité suspecte dans le rapport sécurité sans bloquer l'IP automatiquement.
     */
    public static function reportSuspiciousIp(
        string $ip,
        string $reason,
        ?string $path = null,
        ?string $userAgent = null
    ): self {
        $existing = static::where('ip_address', $ip)
            ->where('is_whitelisted', false)
            ->first();

        $trimmedPath = $path !== null ? mb_substr($path, 0, 255) : null;
        $trimmedAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;

        if ($existing) {
            $existing->update([
                'reason' => mb_substr($reason, 0, 255),
                'last_path' => $trimmedPath ?? $existing->last_path,
                'user_agent' => $trimmedAgent ?? $existing->user_agent,
                'attempts_count' => $existing->attempts_count + 1,
                'last_seen_at' => now(),
            ]);

            return $existing;
        }

        return static::create([
            'ip_address' => $ip,
            'reason' => mb_substr($reason, 0, 255),
            'last_path' => $trimmedPath,
            'user_agent' => $trimmedAgent,
            'is_whitelisted' => false,
            'permanent' => false,
            'blocked_until' => null,
            'attempts_count' => 1,
            'last_seen_at' => now(),
            'created_by' => 'system_report',
        ]);
    }

    /**
     * Bloque manuellement une IP pour une durée donnée (jours, heures, minutes) ou définitivement.
     */
    public static function blockIp(
        string $ip,
        string $reason,
        ?int $hours = null,
        bool $permanent = false,
        string $createdBy = 'admin',
        ?int $minutes = null,
        ?int $days = null
    ): self {
        $existing = static::where('ip_address', $ip)
            ->where('is_whitelisted', false)
            ->first();

        $durationMinutes = $days !== null
            ? $days * 24 * 60
            : ($minutes ?? ($hours !== null ? $hours * 60 : 24 * 60));

        $blockedUntil = $permanent ? null : now()->addMinutes($durationMinutes);

        if ($existing) {
            $existing->update([
                'reason' => mb_substr($reason, 0, 255),
                'permanent' => $permanent,
                'blocked_until' => $blockedUntil,
                'attempts_count' => max(1, $existing->attempts_count),
                'last_seen_at' => $existing->last_seen_at ?? now(),
                'created_by' => $createdBy,
            ]);

            return $existing;
        }

        return static::create([
            'ip_address' => $ip,
            'reason' => mb_substr($reason, 0, 255),
            'is_whitelisted' => false,
            'permanent' => $permanent,
            'blocked_until' => $blockedUntil,
            'attempts_count' => 1,
            'last_seen_at' => now(),
            'created_by' => $createdBy,
        ]);
    }
}
