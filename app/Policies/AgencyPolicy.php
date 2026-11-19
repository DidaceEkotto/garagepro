<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

class AgencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('agencies.view');
    }

    public function view(User $user, Agency $agency): bool
    {
        if ((int) $user->tenant_id !== (int) $agency->tenant_id) {
            return false;
        }

        return $user->can('agencies.view') && $user->hasAccessToAgency($agency->id);
    }

    public function create(User $user): bool
    {
        return $user->can('agencies.manage');
    }

    public function update(User $user, Agency $agency): bool
    {
        return (int) $user->tenant_id === (int) $agency->tenant_id
            && $user->can('agencies.manage');
    }

    public function delete(User $user, Agency $agency): bool
    {
        return (int) $user->tenant_id === (int) $agency->tenant_id
            && ! $agency->is_main
            && $user->can('agencies.manage');
    }
}
