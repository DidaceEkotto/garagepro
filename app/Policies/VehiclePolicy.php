<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('vehicles.view');
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return (int) $user->tenant_id === (int) $vehicle->tenant_id
            && $user->can('vehicles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('vehicles.manage');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return (int) $user->tenant_id === (int) $vehicle->tenant_id
            && $user->can('vehicles.manage');
    }
}
