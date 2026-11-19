<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\RepairOrder;

/**
 * Génère les numéros séquentiels sans trou par tenant et par année.
 */
class NumberSequenceService
{
    public function nextRepairOrderNumber(int $tenantId): string
    {
        $year = now()->year;
        $prefix = "OR-{$year}-";

        $last = RepairOrder::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('order_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('order_number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function nextQuoteNumber(int $tenantId): string
    {
        $year = now()->year;
        $prefix = "DEV-{$year}-";

        $last = Quote::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('quote_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('quote_number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function nextInvoiceNumber(int $tenantId, string $type = Invoice::TYPE_INVOICE): string
    {
        $year = now()->year;
        $code = match ($type) {
            Invoice::TYPE_PROFORMA => 'PRO',
            Invoice::TYPE_CREDIT_NOTE => 'AV',
            default => 'FAC',
        };
        $prefix = "{$code}-{$year}-";

        $last = Invoice::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('invoice_number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
