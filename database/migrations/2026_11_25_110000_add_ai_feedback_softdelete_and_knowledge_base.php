<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_diagnostic_conversations', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });

        Schema::table('ai_diagnostic_messages', function (Blueprint $table) {
            $table->boolean('is_resolved')->nullable()->after('client_whatsapp_sent_at');
            $table->string('feedback_note', 500)->nullable()->after('is_resolved');
            $table->timestamp('feedback_at')->nullable()->after('feedback_note');
            $table->boolean('is_approved_for_knowledge')->default(true)->after('feedback_at');
            $table->unsignedBigInteger('matched_from_message_id')->nullable()->after('is_approved_for_knowledge');
            $table->unsignedInteger('reuse_count')->default(0)->after('matched_from_message_id');

            $table->index(['role', 'is_resolved', 'is_approved_for_knowledge'], 'ai_msg_kb_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_diagnostic_messages', function (Blueprint $table) {
            $table->dropIndex('ai_msg_kb_lookup_idx');
            $table->dropColumn([
                'is_resolved',
                'feedback_note',
                'feedback_at',
                'is_approved_for_knowledge',
                'matched_from_message_id',
                'reuse_count',
            ]);
        });

        Schema::table('ai_diagnostic_conversations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
