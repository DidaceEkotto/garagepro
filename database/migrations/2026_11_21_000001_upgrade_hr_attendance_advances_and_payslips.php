<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendances', 'status')) {
                $table->string('status', 30)->default('present')->after('work_date'); // present, retard, absent, conge, permission
            }
            if (! Schema::hasColumn('employee_attendances', 'overtime_minutes')) {
                $table->unsignedInteger('overtime_minutes')->default(0)->after('late_penalty');
            }
            if (! Schema::hasColumn('employee_attendances', 'overtime_bonus')) {
                $table->unsignedBigInteger('overtime_bonus')->default(0)->after('overtime_minutes');
            }
            if (! Schema::hasColumn('employee_attendances', 'notes')) {
                $table->string('notes', 255)->nullable()->after('commission_earned');
            }
        });

        Schema::create('employee_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 20)->index(); // avance, prime
            $table->unsignedBigInteger('amount');
            $table->string('label', 150);
            $table->date('effective_date');
            $table->string('period_month', 7)->index(); // YYYY-MM
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('period_month', 7)->index(); // YYYY-MM
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('primes_total')->default(0);
            $table->unsignedBigInteger('commissions_total')->default(0);
            $table->unsignedBigInteger('overtime_total')->default(0);
            $table->unsignedInteger('late_minutes_total')->default(0);
            $table->unsignedBigInteger('late_penalties_total')->default(0);
            $table->unsignedBigInteger('advances_total')->default(0);
            $table->unsignedBigInteger('net_salary')->default(0);
            $table->string('status', 20)->default('brouillon')->index(); // brouillon, valide, paye
            $table->string('payment_method', 30)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('employee_adjustments');

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropColumn(['status', 'overtime_minutes', 'overtime_bonus', 'notes']);
        });
    }
};
