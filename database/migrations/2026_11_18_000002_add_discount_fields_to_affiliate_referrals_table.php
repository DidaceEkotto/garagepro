<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_referrals', function (Blueprint $table) {
            $table->unsignedInteger('discount_amount')->default(0)->after('commission_amount');
            $table->timestamp('discount_used_at')->nullable()->after('status');
            $table->timestamp('credited_at')->nullable()->after('discount_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_referrals', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'discount_used_at', 'credited_at']);
        });
    }
};
