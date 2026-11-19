<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleReception;

class VehicleReceptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('receptions.manage') || $user->can('repair_orders.view');
    }

    public function view(User $user, VehicleReception $reception): bool
    {
        return (int) $user->tenant_id === (int) $reception->tenant_id
            && ($user->can('receptions.manage') || $user->can('repair_orders.view'));
    }

    public function create(User $user): bool
    {
        return $user->can('receptions.manage');
    }

    public function update(User $user, VehicleReception $reception): bool
    {
        return (int) $user->tenant_id === (int) $reception->tenant_id
            && $user->can('receptions.manage');
    }
}
