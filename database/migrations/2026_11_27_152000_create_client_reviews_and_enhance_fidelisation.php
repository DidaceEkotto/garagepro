<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'next_belt_brake_date')) {
                $table->date('next_belt_brake_date')->nullable()->after('insurance_expires_at');
            }
            if (! Schema::hasColumn('vehicles', 'next_belt_brake_mileage')) {
                $table->unsignedInteger('next_belt_brake_mileage')->nullable()->after('next_belt_brake_date');
            }
            if (! Schema::hasColumn('vehicles', 'cost_alert_threshold')) {
                $table->unsignedBigInteger('cost_alert_threshold')->default(0)->after('next_belt_brake_mileage');
            }
        });

        if (! Schema::hasTable('client_reviews')) {
            Schema::create('client_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('repair_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('token', 64)->unique();
                $table->unsignedTinyInteger('rating')->nullable();
                $table->text('comment')->nullable();
                $table->boolean('published_publicly')->default(false);
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'submitted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reviews');

        Schema::table('vehicles', function (Blueprint $table) {
            foreach (['cost_alert_threshold', 'next_belt_brake_mileage', 'next_belt_brake_date'] as $col) {
                if (Schema::hasColumn('vehicles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
