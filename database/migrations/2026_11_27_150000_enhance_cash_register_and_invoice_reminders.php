<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_closures', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_register_closures', 'status')) {
                $table->string('status', 20)->default('closed')->after('closure_date');
            }
            if (! Schema::hasColumn('cash_register_closures', 'opened_by')) {
                $table->foreignId('opened_by')->nullable()->after('agency_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('cash_register_closures', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('cash_register_closures', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('opened_at');
            }
            if (! Schema::hasColumn('cash_register_closures', 'opening_float')) {
                $table->unsignedBigInteger('opening_float')->default(0)->after('closed_at');
            }
            if (! Schema::hasColumn('cash_register_closures', 'virement_total')) {
                $table->unsignedBigInteger('virement_total')->default(0)->after('card_bank_total');
            }
            if (! Schema::hasColumn('cash_register_closures', 'expenses_cash_total')) {
                $table->unsignedBigInteger('expenses_cash_total')->default(0)->after('virement_total');
            }
            if (! Schema::hasColumn('cash_register_closures', 'expected_cash')) {
                $table->unsignedBigInteger('expected_cash')->default(0)->after('expenses_cash_total');
            }
            if (! Schema::hasColumn('cash_register_closures', 'counted_cash')) {
                $table->unsignedBigInteger('counted_cash')->nullable()->after('expected_cash');
            }
            if (! Schema::hasColumn('cash_register_closures', 'cash_discrepancy')) {
                $table->bigInteger('cash_discrepancy')->default(0)->after('counted_cash');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'overdue_reminder_stage')) {
                $table->unsignedTinyInteger('overdue_reminder_stage')->default(0)->after('due_at');
            }
            if (! Schema::hasColumn('invoices', 'last_reminder_sent_at')) {
                $table->timestamp('last_reminder_sent_at')->nullable()->after('overdue_reminder_stage');
            }
            if (! Schema::hasColumn('invoices', 'installment_plan')) {
                $table->json('installment_plan')->nullable()->after('last_reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_closures', function (Blueprint $table) {
            foreach ([
                'cash_discrepancy', 'counted_cash', 'expected_cash',
                'expenses_cash_total', 'virement_total', 'opening_float',
                'closed_at', 'opened_at', 'status',
            ] as $col) {
                if (Schema::hasColumn('cash_register_closures', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('cash_register_closures', 'opened_by')) {
                $table->dropConstrainedForeignId('opened_by');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['installment_plan', 'last_reminder_sent_at', 'overdue_reminder_stage'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
