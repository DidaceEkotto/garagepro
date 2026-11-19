<?php

namespace App\Policies;

use App\Models\Part;
use App\Models\User;

class PartPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.manage');
    }

    public function update(User $user, Part $part): bool
    {
        return (int) $user->tenant_id === (int) $part->tenant_id
            && $user->can('stock.manage');
    }

    public function transfer(User $user, Part $part): bool
    {
        return (int) $user->tenant_id === (int) $part->tenant_id
            && $user->can('stock.transfer');
    }
}
