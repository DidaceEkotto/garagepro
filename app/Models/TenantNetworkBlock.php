<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantNetworkBlock extends Model
{
    protected $fillable = [
        'blocker_tenant_id',
        'blocked_tenant_id',
    ];

    public static function isBlockedBetween(int $tenantA, int $tenantB): bool
    {
        return self::query()
            ->where(function ($q) use ($tenantA, $tenantB) {
                $q->where('blocker_tenant_id', $tenantA)->where('blocked_tenant_id', $tenantB);
            })
            ->orWhere(function ($q) use ($tenantA, $tenantB) {
                $q->where('blocker_tenant_id', $tenantB)->where('blocked_tenant_id', $tenantA);
            })
            ->exists();
    }
}
