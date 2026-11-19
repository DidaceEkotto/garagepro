<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase_orders.manage') || $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchase_orders.manage');
    }

    public function update(User $user, PurchaseOrder $po): bool
    {
        return (int) $user->tenant_id === (int) $po->tenant_id
            && $user->can('purchase_orders.manage');
    }
}
