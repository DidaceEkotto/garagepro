<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_customers', function (Blueprint $table) {
            $table->string('otp_code', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('global_customers', function (Blueprint $table) {
            $table->string('otp_code', 12)->nullable()->change();
        });
    }
};
