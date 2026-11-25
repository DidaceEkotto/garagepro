<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexSafe('platform_messages', ['tenant_id', 'recipient_type', 'is_read'], 'pm_tenant_rec_read_idx');
        $this->addIndexSafe('marketplace_order_items', ['tenant_id', 'is_seen_by_seller'], 'moi_tenant_seen_idx');
        $this->addIndexSafe('appointment_links', ['tenant_id', 'status'], 'al_tenant_status_idx');
        $this->addIndexSafe('affiliate_links', ['tenant_id', 'is_blocked'], 'aff_tenant_blocked_idx');
        $this->addIndexSafe('employees', ['user_id'], 'emp_user_id_idx');
        $this->addIndexSafe('repair_orders', ['tenant_id', 'status'], 'ro_tenant_status_idx');
        $this->addIndexSafe('quotes', ['tenant_id', 'status'], 'q_tenant_status_idx');
        $this->addIndexSafe('invoices', ['tenant_id', 'status'], 'inv_tenant_status_idx');
    }

    public function down(): void
    {
        $this->dropIndexSafe('platform_messages', 'pm_tenant_rec_read_idx');
        $this->dropIndexSafe('marketplace_order_items', 'moi_tenant_seen_idx');
        $this->dropIndexSafe('appointment_links', 'al_tenant_status_idx');
        $this->dropIndexSafe('affiliate_links', 'aff_tenant_blocked_idx');
        $this->dropIndexSafe('employees', 'emp_user_id_idx');
        $this->dropIndexSafe('repair_orders', 'ro_tenant_status_idx');
        $this->dropIndexSafe('quotes', 'q_tenant_status_idx');
        $this->dropIndexSafe('invoices', 'inv_tenant_status_idx');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexSafe(string $table, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ($columns as $col) {
            if (! Schema::hasColumn($table, $col)) {
                return;
            }
        }
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName): void {
                $t->index($columns, $indexName);
            });
        } catch (\Throwable) {
            // Index déjà existant
        }
    }

    private function dropIndexSafe(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $t) use ($indexName): void {
                $t->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // ignore
        }
    }
};
