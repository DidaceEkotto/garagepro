<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('license_plate', 30);
            $table->string('brand', 80);
            $table->string('model', 80);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('vin', 50)->nullable();
            $table->string('color', 40)->nullable();
            $table->string('fuel_type', 30)->default('essence'); // essence, diesel, hybride, electrique, gpl
            $table->string('transmission', 30)->default('manuelle'); // manuelle, automatique
            $table->unsignedInteger('mileage')->default(0);
            $table->date('first_registration_date')->nullable();
            $table->date('next_service_date')->nullable();
            $table->unsignedInteger('next_service_mileage')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'license_plate']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
