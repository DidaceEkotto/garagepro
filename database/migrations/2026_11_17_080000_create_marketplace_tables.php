<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('icon', 60)->default('bi-gear-wide-connected');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('marketplace_sub_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('marketplace_categories')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'category_id', 'slug'], 'mp_subcat_unique');
        });

        Schema::create('marketplace_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('marketplace_categories')->cascadeOnDelete();
            $table->foreignId('sub_category_id')->nullable()->constrained('marketplace_sub_categories')->nullOnDelete();
            $table->string('title', 180);
            $table->string('slug', 200);
            $table->string('sku', 80)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('vehicle_compatibility', 200)->nullable();
            $table->string('condition', 30)->default('neuf'); // neuf | occasion | adaptable
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('stock_quantity')->default(1);
            $table->string('country_code', 2)->default('CM');
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'country_code']);
            $table->index(['category_id', 'sub_category_id']);
        });

        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->string('buyer_name', 150);
            $table->string('buyer_email', 150);
            $table->string('country_code', 2);
            $table->string('dial_code', 10);
            $table->string('buyer_whatsapp', 40);
            $table->string('delivery_address', 255);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('total_amount');
            $table->string('status', 30)->default('nouvelle');
            $table->timestamps();
        });

        Schema::create('marketplace_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('marketplace_product_id')->constrained('marketplace_products')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('product_title', 180);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal');
            $table->string('seller_status', 30)->default('nouvelle');
            $table->boolean('is_seen_by_seller')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_seen_by_seller']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_items');
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('marketplace_products');
        Schema::dropIfExists('marketplace_sub_categories');
        Schema::dropIfExists('marketplace_categories');
    }
};
