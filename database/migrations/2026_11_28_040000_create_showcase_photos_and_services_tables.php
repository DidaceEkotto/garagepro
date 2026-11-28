<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'showcase_photos')) {
                $table->json('showcase_photos')->nullable()->after('settings');
            }
        });

        if (! Schema::hasTable('tenant_services')) {
            Schema::create('tenant_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('category', 80)->default('entretien');
                $table->text('description')->nullable();
                $table->unsignedInteger('price')->default(0);
                $table->unsignedSmallInteger('duration_minutes')->default(60);
                $table->string('image_path', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['tenant_id', 'is_active']);
            });
        }

        Schema::table('roadside_sos_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('roadside_sos_requests', 'driver_first_name')) {
                $table->string('driver_first_name', 120)->nullable()->after('driver_name');
            }
            if (! Schema::hasColumn('roadside_sos_requests', 'driver_dial_code')) {
                $table->string('driver_dial_code', 16)->nullable()->after('driver_first_name');
            }
            if (! Schema::hasColumn('roadside_sos_requests', 'vehicle_brand')) {
                $table->string('vehicle_brand', 80)->nullable()->after('vehicle_info');
            }
            if (! Schema::hasColumn('roadside_sos_requests', 'vehicle_model')) {
                $table->string('vehicle_model', 80)->nullable()->after('vehicle_brand');
            }
            if (! Schema::hasColumn('roadside_sos_requests', 'vehicle_plate')) {
                $table->string('vehicle_plate', 40)->nullable()->after('vehicle_model');
            }
            if (! Schema::hasColumn('roadside_sos_requests', 'photos')) {
                $table->json('photos')->nullable()->after('photo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_services');

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'showcase_photos')) {
                $table->dropColumn('showcase_photos');
            }
        });

        Schema::table('roadside_sos_requests', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('roadside_sos_requests', 'driver_first_name') ? 'driver_first_name' : null,
                Schema::hasColumn('roadside_sos_requests', 'driver_dial_code') ? 'driver_dial_code' : null,
                Schema::hasColumn('roadside_sos_requests', 'vehicle_brand') ? 'vehicle_brand' : null,
                Schema::hasColumn('roadside_sos_requests', 'vehicle_model') ? 'vehicle_model' : null,
                Schema::hasColumn('roadside_sos_requests', 'vehicle_plate') ? 'vehicle_plate' : null,
                Schema::hasColumn('roadside_sos_requests', 'photos') ? 'photos' : null,
            ]);
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
