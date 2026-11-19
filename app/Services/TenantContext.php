<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Tenant;

/**
 * Contexte multi-tenant de la requête courante (singleton par requête).
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Agency $agency = null;

    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // Synchronise le team_id de Spatie Permission avec le tenant actif.
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant?->id);
        }
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function setAgency(?Agency $agency): void
    {
        $this->agency = $agency;
    }

    public function getAgency(): ?Agency
    {
        return $this->agency;
    }

    public function agencyId(): ?int
    {
        return $this->agency?->id;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->agency = null;

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(null);
        }
    }
}
