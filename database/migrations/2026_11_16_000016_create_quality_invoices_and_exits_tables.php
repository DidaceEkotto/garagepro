<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('repair_order_id')->constrained('repair_orders')->cascadeOnDelete();
            $table->foreignId('checked_by')->constrained('users')->cascadeOnDelete();
            $table->json('checklist')->nullable();
            $table->boolean('road_test_done')->default(false);
            $table->unsignedInteger('road_test_mileage')->nullable();
            $table->boolean('passed');
            $table->text('notes')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('repair_order_id')->nullable()->constrained('repair_orders')->nullOnDelete();
            $table->foreignId('parent_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('invoice_number', 40);
            $table->string('type', 20)->default('facture'); // facture, proforma, avoir
            $table->string('status', 30)->default('emise')->index(); // emise, partiellement_payee, payee, annulee
            $table->boolean('is_deferred_monthly')->default(false);
            $table->decimal('vat_rate', 5, 2)->default(19.25);
            $table->unsignedBigInteger('subtotal_ht')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('deposit_deducted')->default(0);
            $table->unsignedBigInteger('total_ttc')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->timestamp('issued_at');
            $table->date('due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'agency_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total_price');
            $table->timestamps();
        });

        Schema::create('vehicle_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('repair_order_id')->constrained('repair_orders')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('released_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('override_reason', 255)->nullable();
            $table->unsignedInteger('mileage_out');
            $table->boolean('belongings_returned')->default(true);
            $table->boolean('old_parts_returned')->default(false);
            $table->boolean('work_explained')->default(true);
            $table->string('client_signature_path', 255)->nullable();
            $table->unsignedSmallInteger('warranty_months')->default(3);
            $table->text('warranty_notes')->nullable();
            $table->timestamp('exited_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'repair_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_exits');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quality_checks');
    }
};
