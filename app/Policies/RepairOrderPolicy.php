<?php

namespace App\Policies;

use App\Models\RepairOrder;
use App\Models\User;

class RepairOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('repair_orders.view') || $user->can('repair_orders.view_own');
    }

    public function view(User $user, RepairOrder $repairOrder): bool
    {
        if ((int) $user->tenant_id !== (int) $repairOrder->tenant_id) {
            return false;
        }

        if ($user->can('repair_orders.view')) {
            return true;
        }

        return $user->can('repair_orders.view_own')
            && (int) $repairOrder->assigned_mechanic_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('repair_orders.manage');
    }

    public function update(User $user, RepairOrder $repairOrder): bool
    {
        return (int) $user->tenant_id === (int) $repairOrder->tenant_id
            && ($user->can('repair_orders.manage') || $user->can('diagnostics.manage'));
    }
}
