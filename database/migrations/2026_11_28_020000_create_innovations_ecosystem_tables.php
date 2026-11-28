<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('country');
            }
            if (! Schema::hasColumn('tenants', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('tenants', 'formatted_location')) {
                $table->string('formatted_location', 255)->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('tenants', 'sos_enabled')) {
                $table->boolean('sos_enabled')->default(true)->after('b2b_directory_visible');
            }
            if (! Schema::hasColumn('tenants', 'sos_radius_km')) {
                $table->unsignedSmallInteger('sos_radius_km')->default(35)->after('sos_enabled');
            }
            if (! Schema::hasColumn('tenants', 'whatsapp_bot_enabled')) {
                $table->boolean('whatsapp_bot_enabled')->default(false)->after('sos_radius_km');
            }
            if (! Schema::hasColumn('tenants', 'whatsapp_bot_secret')) {
                $table->string('whatsapp_bot_secret', 64)->nullable()->after('whatsapp_bot_enabled');
            }
            if (! Schema::hasColumn('tenants', 'whatsapp_bot_catalog')) {
                $table->text('whatsapp_bot_catalog')->nullable()->after('whatsapp_bot_secret');
            }
        });

        if (! Schema::hasTable('roadside_sos_requests')) {
            Schema::create('roadside_sos_requests', function (Blueprint $table) {
                $table->id();
                $table->string('token', 48)->unique();
                $table->string('driver_name', 120);
                $table->string('driver_phone', 40);
                $table->string('vehicle_info', 160)->nullable();
                $table->string('breakdown_type', 40)->default('moteur'); // batterie, crevaison, surchauffe, moteur, accident, cle, remorquage
                $table->text('description')->nullable();
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->string('location_label', 255)->nullable();
                $table->string('photo_path', 255)->nullable();
                $table->string('status', 25)->default('open'); // open, accepted, on_the_way, resolved, cancelled
                $table->foreignId('accepted_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->unsignedInteger('accepted_price')->nullable();
                $table->unsignedSmallInteger('accepted_eta_minutes')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('roadside_sos_offers')) {
            Schema::create('roadside_sos_offers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sos_request_id')->constrained('roadside_sos_requests')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('price');
                $table->unsignedSmallInteger('eta_minutes');
                $table->decimal('distance_km', 6, 1)->nullable();
                $table->string('message', 500)->nullable();
                $table->string('status', 20)->default('pending'); // pending, accepted, rejected
                $table->timestamps();

                $table->unique(['sos_request_id', 'tenant_id'], 'sos_offer_unique');
            });
        }

        if (! Schema::hasTable('whatsapp_bot_logs')) {
            Schema::create('whatsapp_bot_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('sender_phone', 40);
                $table->text('incoming_message');
                $table->text('bot_reply');
                $table->string('rdv_link_url', 255)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_logs');
        Schema::dropIfExists('roadside_sos_offers');
        Schema::dropIfExists('roadside_sos_requests');

        Schema::table('tenants', function (Blueprint $table) {
            foreach ([
                'whatsapp_bot_catalog', 'whatsapp_bot_secret', 'whatsapp_bot_enabled',
                'sos_radius_km', 'sos_enabled', 'formatted_location', 'longitude', 'latitude',
            ] as $col) {
                if (Schema::hasColumn('tenants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
