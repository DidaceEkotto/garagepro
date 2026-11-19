<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Journal d'audit immuable des actions critiques de la plateforme et des tenants.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enregistre un événement d'audit en garantissant l'absence de secrets.
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?Model $actor = null,
        ?int $tenantId = null,
        array $metadata = [],
        ?Request $request = null
    ): self {
        $req = $request ?? request();

        return static::create([
            'tenant_id' => $tenantId,
            'actor_type' => $actor ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $req?->ip(),
            'user_agent' => $req?->userAgent() ? mb_substr($req->userAgent(), 0, 500) : null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
