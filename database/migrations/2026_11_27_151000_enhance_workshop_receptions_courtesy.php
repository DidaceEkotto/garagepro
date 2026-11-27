<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('repair_tasks', 'timer_started_at')) {
                $table->timestamp('timer_started_at')->nullable()->after('actual_minutes');
            }
            if (! Schema::hasColumn('repair_tasks', 'commission_amount')) {
                $table->unsignedInteger('commission_amount')->default(0)->after('timer_started_at');
            }
        });

        Schema::table('vehicle_receptions', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicle_receptions', 'client_signature_path')) {
                $table->string('client_signature_path', 255)->nullable()->after('photos');
            }
            if (! Schema::hasColumn('vehicle_receptions', 'courtesy_departure_mileage')) {
                $table->unsignedInteger('courtesy_departure_mileage')->nullable()->after('courtesy_deposit_amount');
            }
            if (! Schema::hasColumn('vehicle_receptions', 'courtesy_departure_fuel')) {
                $table->unsignedTinyInteger('courtesy_departure_fuel')->nullable()->after('courtesy_departure_mileage');
            }
            if (! Schema::hasColumn('vehicle_receptions', 'courtesy_return_mileage')) {
                $table->unsignedInteger('courtesy_return_mileage')->nullable()->after('courtesy_departure_fuel');
            }
            if (! Schema::hasColumn('vehicle_receptions', 'courtesy_return_fuel')) {
                $table->unsignedTinyInteger('courtesy_return_fuel')->nullable()->after('courtesy_return_mileage');
            }
            if (! Schema::hasColumn('vehicle_receptions', 'courtesy_return_notes')) {
                $table->text('courtesy_return_notes')->nullable()->after('courtesy_return_fuel');
            }
            if (! Schema::hasColumn('vehicle_receptions', 'courtesy_returned_at')) {
                $table->timestamp('courtesy_returned_at')->nullable()->after('courtesy_return_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('repair_tasks', function (Blueprint $table) {
            foreach (['commission_amount', 'timer_started_at'] as $col) {
                if (Schema::hasColumn('repair_tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('vehicle_receptions', function (Blueprint $table) {
            foreach ([
                'courtesy_returned_at', 'courtesy_return_notes', 'courtesy_return_fuel',
                'courtesy_return_mileage', 'courtesy_departure_fuel', 'courtesy_departure_mileage',
                'client_signature_path',
            ] as $col) {
                if (Schema::hasColumn('vehicle_receptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
