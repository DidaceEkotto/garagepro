<?php

use App\Models\Admin;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('marketplace_products')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('marketplace_orders')->nullOnDelete();
            $table->string('reviewer_name', 120);
            $table->string('reviewer_email', 160);
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->text('comment')->nullable();
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('ip_hash', 64)->index();
            $table->timestamps();

            $table->unique(['product_id', 'reviewer_email']);
            $table->index(['product_id', 'ip_hash', 'created_at']);
        });

        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason', 255)->nullable();
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('balance')->default(0);
            $table->unsignedInteger('total_earned')->default(0);
            $table->timestamps();
        });

        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_link_id')->constrained('affiliate_links')->cascadeOnDelete();
            $table->foreignId('referred_tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('commission_amount')->default(0);
            $table->string('status', 20)->default('credited'); // credited | cancelled
            $table->string('ip_address', 64)->nullable();
            $table->timestamps();

            $table->index(['affiliate_link_id', 'ip_address']);
        });

        // S'assurer que tous les produits existants ont un slug unique
        Product::withoutTenantScope()->whereNull('slug')->orWhere('slug', '')->each(function (Product $p) {
            $base = Str::slug($p->title ?: 'produit');
            $p->slug = "{$base}-{$p->id}";
            $p->saveQuietly();
        });

        // Assurer l'accès Admin par défaut demandé : admin@admin.test / 000000
        Admin::updateOrCreate(
            ['email' => 'admin@admin.test'],
            [
                'name' => 'Super Admin',
                'password' => '000000',
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_referrals');
        Schema::dropIfExists('affiliate_links');
        Schema::dropIfExists('product_reviews');
    }
};
