<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clients.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return (int) $user->tenant_id === (int) $customer->tenant_id
            && $user->can('clients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('clients.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return (int) $user->tenant_id === (int) $customer->tenant_id
            && $user->can('clients.manage');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return (int) $user->tenant_id === (int) $customer->tenant_id
            && $user->can('clients.manage');
    }
}
