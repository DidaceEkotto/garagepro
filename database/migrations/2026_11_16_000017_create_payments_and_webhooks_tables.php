<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->morphs('payable');
            $table->string('payment_method', 30)->default('saspay_checkout');
            $table->unsignedBigInteger('amount'); // FCFA entier
            $table->string('currency', 10)->default('XAF');
            $table->string('status', 30)->default('pending')->index(); // pending, success, failed, cancelled, refunded
            $table->string('provider_payment_id', 120)->nullable()->index();
            $table->uuid('idempotency_key')->unique();
            $table->string('reference_code', 30)->unique();
            $table->text('checkout_url')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('saspay');
            $table->string('event_id', 120)->unique();
            $table->string('event_type', 80);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_register_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('closed_by')->constrained('users')->cascadeOnDelete();
            $table->date('closure_date');
            $table->unsignedBigInteger('cash_total')->default(0);
            $table->unsignedBigInteger('mobile_money_total')->default(0);
            $table->unsignedBigInteger('card_bank_total')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'agency_id', 'closure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_closures');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
    }
};
