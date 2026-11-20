<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'technical_inspection_expires_at')) {
                $table->date('technical_inspection_expires_at')->nullable()->after('next_service_mileage');
            }
            if (! Schema::hasColumn('vehicles', 'insurance_expires_at')) {
                $table->date('insurance_expires_at')->nullable()->after('technical_inspection_expires_at');
            }
            if (! Schema::hasColumn('vehicles', 'maintenance_reminder_sent_at')) {
                $table->timestamp('maintenance_reminder_sent_at')->nullable()->after('insurance_expires_at');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'fleet_manager_name')) {
                $table->string('fleet_manager_name', 150)->nullable()->after('credit_limit');
            }
            if (! Schema::hasColumn('customers', 'fleet_notes')) {
                $table->text('fleet_notes')->nullable()->after('fleet_manager_name');
            }
        });

        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('repair_order_id')->nullable()->constrained('repair_orders')->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('checklist');
            $table->json('photos')->nullable();
            $table->text('summary_notes')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subcontract_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('repair_order_id')->nullable()->constrained('repair_orders')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('type', 30)->default('sous_traitance')->index(); // sous_traitance, depannage, remorquage
            $table->string('provider_name', 150);
            $table->string('provider_phone', 50)->nullable();
            $table->text('description');
            $table->string('location_address', 255)->nullable();
            $table->unsignedBigInteger('cost_amount')->default(0);
            $table->unsignedBigInteger('billed_amount')->default(0);
            $table->string('status', 30)->default('planifie')->index(); // planifie, en_cours, termine, facture
            $table->timestamp('performed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workshop_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('name', 150);
            $table->string('code', 60)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('status', 30)->default('disponible')->index(); // disponible, emprunte, maintenance
            $table->foreignId('borrowed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('borrowed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_consumables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('name', 150);
            $table->string('unit', 20)->default('L'); // L, kg, m
            $table->decimal('container_capacity', 10, 2)->default(200);
            $table->decimal('current_quantity', 10, 2)->default(0);
            $table->decimal('alert_threshold', 10, 2)->default(20);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->timestamps();
        });

        Schema::create('bulk_consumable_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bulk_consumable_id')->constrained('bulk_consumables')->cascadeOnDelete();
            $table->foreignId('repair_order_id')->nullable()->constrained('repair_orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20); // entree, sortie
            $table->decimal('quantity', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_consumable_logs');
        Schema::dropIfExists('bulk_consumables');
        Schema::dropIfExists('workshop_tools');
        Schema::dropIfExists('subcontract_interventions');
        Schema::dropIfExists('vehicle_inspections');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['fleet_manager_name', 'fleet_notes']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['technical_inspection_expires_at', 'insurance_expires_at', 'maintenance_reminder_sent_at']);
        });
    }
};
