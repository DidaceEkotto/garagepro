<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_diagnostic_conversations', function (Blueprint $table) {
            $table->foreignId('repair_order_id')
                ->nullable()
                ->after('vehicle_id')
                ->constrained('repair_orders')
                ->nullOnDelete();
        });

        Schema::table('ai_diagnostic_messages', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('content');
            $table->string('audio_path', 255)->nullable()->after('attachments');
            $table->string('audio_mime', 80)->nullable()->after('audio_path');
            $table->unsignedSmallInteger('audio_duration')->nullable()->after('audio_mime');
            $table->text('client_summary')->nullable()->after('tokens_used');
            $table->timestamp('client_whatsapp_sent_at')->nullable()->after('client_summary');
        });
    }

    public function down(): void
    {
        Schema::table('ai_diagnostic_messages', function (Blueprint $table) {
            $table->dropColumn([
                'attachments',
                'audio_path',
                'audio_mime',
                'audio_duration',
                'client_summary',
                'client_whatsapp_sent_at',
            ]);
        });

        Schema::table('ai_diagnostic_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repair_order_id');
        });
    }
};
