<?php

namespace Database\Seeders;

use App\Services\TenantRoleService;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(TenantRoleService $roleService): void
    {
        $roleService->ensurePermissionsExist();
    }
}
