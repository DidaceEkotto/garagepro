<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('name', 150)->index();
            $table->string('phone', 50)->index();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('type', 30)->default('particulier'); // particulier, entreprise, flotte
            $table->string('company_name', 150)->nullable();
            $table->string('tax_id', 80)->nullable(); // NIU / RCCM
            $table->unsignedBigInteger('credit_limit')->default(0); // plafond crédit en FCFA
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
