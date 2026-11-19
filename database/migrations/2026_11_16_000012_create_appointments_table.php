<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('assigned_mechanic_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('bay_name', 60)->nullable(); // Pont / Baie
            $table->string('channel', 30)->default('telephone'); // telephone, whatsapp, en_ligne, sans_rdv
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('status', 30)->default('en_attente')->index(); // en_attente, confirme, en_file, recu, reporte, annule
            $table->string('reason', 30)->default('entretien'); // panne, entretien, controle, accident, autre
            $table->text('notes')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
