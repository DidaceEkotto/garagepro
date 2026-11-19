<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotes.view');
    }

    public function view(User $user, Quote $quote): bool
    {
        return (int) $user->tenant_id === (int) $quote->tenant_id
            && $user->can('quotes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quotes.manage');
    }

    public function update(User $user, Quote $quote): bool
    {
        return (int) $user->tenant_id === (int) $quote->tenant_id
            && $user->can('quotes.manage');
    }
}
