<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vehicles', 'public_token')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->string('public_token', 64)->nullable()->unique()->after('notes');
            });
        }

        if (! Schema::hasColumn('repair_orders', 'tracking_token')) {
            Schema::table('repair_orders', function (Blueprint $table) {
                $table->string('tracking_token', 64)->nullable()->unique()->after('order_number');
            });
        }

        if (! Schema::hasColumn('marketplace_products', 'vehicle_brand')) {
            Schema::table('marketplace_products', function (Blueprint $table) {
                $table->string('vehicle_brand', 80)->nullable()->after('vehicle_compatibility');
                $table->string('vehicle_model', 80)->nullable()->after('vehicle_brand');
                $table->unsignedSmallInteger('vehicle_year_from')->nullable()->after('vehicle_model');
                $table->unsignedSmallInteger('vehicle_year_to')->nullable()->after('vehicle_year_from');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vehicles', 'public_token')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('public_token');
            });
        }

        if (Schema::hasColumn('repair_orders', 'tracking_token')) {
            Schema::table('repair_orders', function (Blueprint $table) {
                $table->dropColumn('tracking_token');
            });
        }

        if (Schema::hasColumn('marketplace_products', 'vehicle_brand')) {
            Schema::table('marketplace_products', function (Blueprint $table) {
                $table->dropColumn(['vehicle_brand', 'vehicle_model', 'vehicle_year_from', 'vehicle_year_to']);
            });
        }
    }
};
