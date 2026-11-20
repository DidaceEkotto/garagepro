<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'type')) {
                $table->string('type', 30)->default('garage')->after('slug')->index();
            }
        });

        Schema::create('technical_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pv_number', 60)->unique();
            $table->string('public_token', 64)->unique();
            $table->string('inspection_line', 60)->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('verdict', 30)->default('favorable')->index(); // favorable, contre_visite, immobilisation
            $table->json('defects');
            $table->timestamp('inspected_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->date('counter_visit_deadline')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('policy_number', 80)->index();
            $table->string('insurer_name', 150)->nullable();
            $table->string('coverage_type', 30)->default('tiers'); // tiers, tiers_plus, tous_risques
            $table->unsignedBigInteger('premium_amount')->default(0);
            $table->unsignedBigInteger('deductible_amount')->default(0);
            $table->date('starts_at');
            $table->date('expires_at')->index();
            $table->string('status', 30)->default('active')->index(); // active, expiree, resiliee
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('insurance_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('garage_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('insurance_policy_id')->nullable()->constrained('insurance_policies')->nullOnDelete();
            $table->foreignId('repair_order_id')->nullable()->constrained('repair_orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('claim_number', 80)->index();
            $table->date('incident_date');
            $table->text('description');
            $table->string('expert_name', 150)->nullable();
            $table->unsignedBigInteger('estimated_amount')->default(0);
            $table->unsignedBigInteger('approved_amount')->default(0);
            $table->unsignedTinyInteger('insurance_share_percent')->default(80);
            $table->unsignedBigInteger('client_deductible_amount')->default(0);
            $table->string('status', 40)->default('declare')->index(); // declare, expertise, accord_prise_en_charge, en_reparation, cloture, refuse
            $table->json('photos')->nullable();
            $table->text('expert_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claims');
        Schema::dropIfExists('insurance_policies');
        Schema::dropIfExists('technical_inspections');

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
