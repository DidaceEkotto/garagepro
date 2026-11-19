<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 100)->unique();
            $table->string('email', 150);
            $table->string('phone', 50)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 10)->default('CM');
            $table->string('status', 30)->default('trialing')->index(); // trialing, active, past_due, expired, cancelled, suspended
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(19.25);
            $table->unsignedBigInteger('hourly_rate')->default(5000); // en FCFA entier
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
