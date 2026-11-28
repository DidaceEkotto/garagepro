<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocked_ips', function (Blueprint $table) {
            $table->string('last_path', 255)->nullable()->after('reason');
            $table->string('user_agent', 255)->nullable()->after('last_path');
            $table->timestamp('last_seen_at')->nullable()->index()->after('attempts_count');
        });

        // Convertit les anciens blocages automatiques système (20 min) en simples signalements
        DB::table('blocked_ips')
            ->where('created_by', 'system')
            ->where('permanent', false)
            ->update([
                'blocked_until' => null,
                'created_by' => 'system_report',
                'last_seen_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('blocked_ips', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['last_path', 'user_agent', 'last_seen_at']);
        });
    }
};
