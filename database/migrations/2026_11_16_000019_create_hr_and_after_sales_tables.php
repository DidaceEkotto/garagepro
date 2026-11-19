<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name', 150);
            $table->string('role_title', 80);
            $table->string('phone', 50)->nullable();
            $table->unsignedBigInteger('base_salary')->default(0); // FCFA
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('hired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->unsignedBigInteger('commission_earned')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('after_sales_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('repair_order_id')->nullable()->constrained('repair_orders')->nullOnDelete();
            $table->string('type', 30); // reclamation, garantie, satisfaction
            $table->string('status', 30)->default('ouvert'); // ouvert, en_cours, resolu, ferme
            $table->unsignedTinyInteger('rating')->nullable(); // 1 à 5
            $table->text('description')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('card_number', 40);
            $table->unsignedInteger('points')->default(0);
            $table->string('tier', 20)->default('standard'); // standard, argent, or
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
        Schema::dropIfExists('after_sales_cases');
        Schema::dropIfExists('employee_attendances');
        Schema::dropIfExists('employees');
    }
};
