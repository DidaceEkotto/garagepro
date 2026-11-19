<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('reception_id')->nullable()->constrained('vehicle_receptions')->nullOnDelete();
            $table->foreignId('assigned_mechanic_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number', 40);
            $table->string('status', 30)->default('en_attente')->index();
            $table->string('priority', 20)->default('normale');
            $table->timestamp('diagnostic_started_at')->nullable();
            $table->timestamp('diagnostic_ended_at')->nullable();
            $table->json('diagnostic_checklist')->nullable();
            $table->json('fault_codes')->nullable();
            $table->text('diagnostic_notes')->nullable();
            $table->unsignedBigInteger('subtotal_ht')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('total_ttc')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'agency_id', 'status']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('repair_order_id')->nullable()->constrained('repair_orders')->nullOnDelete();
            $table->foreignId('parent_quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->string('quote_number', 40);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 30)->default('brouillon')->index(); // brouillon, envoye, accepte_partiel, accepte, refuse, expire
            $table->date('valid_until')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(19.25);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('subtotal_ht')->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('total_ttc')->default(0);
            $table->unsignedBigInteger('deposit_required')->default(0);
            $table->string('client_signature_path', 255)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'quote_number', 'version']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->string('type', 30); // main_oeuvre, piece, consommable, sous_traitance
            $table->string('part_condition', 20)->nullable(); // neuve, occasion, adaptable
            $table->string('urgency', 20)->default('urgent'); // urgent, recommande, optionnel
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price'); // FCFA entier
            $table->unsignedBigInteger('total_price'); // FCFA entier
            $table->boolean('is_accepted')->default(true);
            $table->timestamps();
        });

        Schema::create('repair_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('repair_order_id')->constrained('repair_orders')->cascadeOnDelete();
            $table->foreignId('assigned_mechanic_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('bay_name', 60)->nullable();
            $table->string('subcontractor_name', 120)->nullable();
            $table->string('subcontractor_type', 40)->nullable(); // tolerie, peinture, electricite, rectification
            $table->string('title', 200);
            $table->string('priority', 20)->default('normale');
            $table->unsignedInteger('estimated_minutes')->default(60);
            $table->unsignedInteger('actual_minutes')->default(0);
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->string('status', 30)->default('a_faire'); // a_faire, en_cours, en_pause, termine
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_tasks');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('repair_orders');
    }
};
