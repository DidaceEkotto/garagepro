<?php

namespace App\Services;

use App\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisionne les rôles et permissions métier scopés par tenant_id.
 */
class TenantRoleService
{
    /**
     * Liste des permissions métier de GaragePro.
     */
    public const PERMISSIONS = [
        // Agences & Utilisateurs
        'agencies.view',
        'agencies.manage',
        'users.view',
        'users.manage',
        // Clients & Véhicules
        'clients.view',
        'clients.manage',
        'vehicles.view',
        'vehicles.manage',
        // Rendez-vous & Réception
        'appointments.view',
        'appointments.manage',
        'receptions.manage',
        // Diagnostic, OR & Planification
        'repair_orders.view',
        'repair_orders.view_own',
        'repair_orders.manage',
        'diagnostics.manage',
        'quotes.view',
        'quotes.manage',
        'planning.manage',
        'quality_control.manage',
        // Stock & Approvisionnement
        'stock.view',
        'stock.manage',
        'stock.transfer',
        'suppliers.manage',
        'purchase_orders.manage',
        // Facturation, Paiements & Comptabilité
        'invoices.view',
        'invoices.manage',
        'payments.manage',
        'cash_register.manage',
        'accounting.view',
        'accounting.manage',
        // Sortie & Après-vente
        'vehicle_exit.manage',
        'vehicle_exit.override',
        'after_sales.manage',
        // Rapports, RH & Paramètres
        'reports.view',
        'hr.manage',
        'settings.manage',
    ];

    /**
     * Définition des 6 rôles standards d'un garage.
     */
    public const ROLES = [
        'gerant' => self::PERMISSIONS,
        'receptionniste' => [
            'agencies.view',
            'clients.view',
            'clients.manage',
            'vehicles.view',
            'vehicles.manage',
            'appointments.view',
            'appointments.manage',
            'receptions.manage',
            'quotes.view',
            'invoices.view',
            'payments.manage',
            'vehicle_exit.manage',
            'after_sales.manage',
        ],
        'chef_atelier' => [
            'agencies.view',
            'clients.view',
            'vehicles.view',
            'appointments.view',
            'receptions.manage',
            'repair_orders.view',
            'repair_orders.manage',
            'diagnostics.manage',
            'quotes.view',
            'quotes.manage',
            'planning.manage',
            'quality_control.manage',
            'stock.view',
            'vehicle_exit.manage',
        ],
        'mecanicien' => [
            'vehicles.view',
            'repair_orders.view_own',
            'diagnostics.manage',
            'stock.view',
        ],
        'magasinier' => [
            'agencies.view',
            'repair_orders.view',
            'stock.view',
            'stock.manage',
            'stock.transfer',
            'suppliers.manage',
            'purchase_orders.manage',
        ],
        'comptable' => [
            'agencies.view',
            'clients.view',
            'quotes.view',
            'invoices.view',
            'invoices.manage',
            'payments.manage',
            'cash_register.manage',
            'accounting.view',
            'accounting.manage',
            'reports.view',
        ],
    ];

    public function ensurePermissionsExist(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function provisionForTenant(Tenant $tenant): void
    {
        $this->ensurePermissionsExist();

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($tenant->id);

        try {
            foreach (self::ROLES as $roleName => $permissions) {
                $role = Role::firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->id,
                ]);

                $role->syncPermissions($permissions);
            }
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
