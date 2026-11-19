<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('default_agency_id')->nullable()->after('tenant_id')->constrained('agencies')->nullOnDelete();
            $table->string('phone', 50)->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('password');
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('is_active');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->text('two_factor_secret')->nullable()->after('locked_until');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_agency_id');
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn([
                'phone',
                'is_active',
                'failed_login_attempts',
                'locked_until',
                'two_factor_secret',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
