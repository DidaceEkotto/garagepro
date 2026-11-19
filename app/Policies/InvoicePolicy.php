<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return (int) $user->tenant_id === (int) $invoice->tenant_id
            && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return (int) $user->tenant_id === (int) $invoice->tenant_id
            && $user->can('invoices.manage');
    }
}
