<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('marketplace_orders', 'delivery_latitude')) {
                $table->decimal('delivery_latitude', 10, 7)->nullable()->after('delivery_address');
            }
            if (! Schema::hasColumn('marketplace_orders', 'delivery_longitude')) {
                $table->decimal('delivery_longitude', 10, 7)->nullable()->after('delivery_latitude');
            }
            if (! Schema::hasColumn('marketplace_orders', 'tracking_token')) {
                $table->string('tracking_token', 64)->nullable()->unique()->after('order_number');
            }
        });

        if (! Schema::hasTable('marketplace_order_messages')) {
            Schema::create('marketplace_order_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('marketplace_order_item_id')->constrained('marketplace_order_items')->cascadeOnDelete();
                $table->string('sender_type', 16); // 'buyer' | 'seller'
                $table->string('sender_name', 120);
                $table->text('body');
                $table->boolean('is_read')->default(false);
                $table->timestamps();

                $table->index(['marketplace_order_item_id', 'created_at'], 'mkoi_msg_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_messages');

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('marketplace_orders', 'delivery_latitude') ? 'delivery_latitude' : null,
                Schema::hasColumn('marketplace_orders', 'delivery_longitude') ? 'delivery_longitude' : null,
                Schema::hasColumn('marketplace_orders', 'tracking_token') ? 'tracking_token' : null,
            ]);
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
