<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_customers', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('whatsapp_phone');
        });
    }

    public function down(): void
    {
        Schema::table('global_customers', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
