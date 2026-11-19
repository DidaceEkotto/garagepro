<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courtesy_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('license_plate', 30);
            $table->string('status', 30)->default('disponible'); // disponible, prete, maintenance
            $table->unsignedInteger('current_mileage')->default(0);
            $table->unsignedBigInteger('deposit_amount')->default(0); // caution FCFA
            $table->timestamps();

            $table->unique(['tenant_id', 'license_plate']);
        });

        Schema::create('vehicle_receptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('courtesy_vehicle_id')->nullable()->constrained('courtesy_vehicles')->nullOnDelete();
            $table->unsignedBigInteger('courtesy_deposit_amount')->default(0);
            $table->timestamp('courtesy_returned_at')->nullable();
            $table->string('visit_reason', 30)->default('entretien'); // panne, entretien, controle, accident
            $table->unsignedInteger('mileage_in');
            $table->unsignedTinyInteger('fuel_level')->default(50); // 0 à 100 %
            $table->json('dashboard_warnings')->nullable();
            $table->text('belongings_left')->nullable();
            $table->json('damage_markers')->nullable(); // Coordonnées & types de rayures/bosses
            $table->string('client_signature_path', 255)->nullable();
            $table->json('photos')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('estimated_exit_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'agency_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_receptions');
        Schema::dropIfExists('courtesy_vehicles');
    }
};
