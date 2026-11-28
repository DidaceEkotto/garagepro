<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'b2b_directory_visible')) {
                $table->boolean('b2b_directory_visible')->default(true)->after('status');
            }
        });

        if (! Schema::hasTable('tenant_conversations')) {
            Schema::create('tenant_conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_one_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('tenant_two_id')->constrained('tenants')->cascadeOnDelete();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_one_id', 'tenant_two_id'], 'tenant_conv_pair_unique');
                $table->index('last_message_at');
            });
        }

        if (! Schema::hasTable('tenant_conversation_messages')) {
            Schema::create('tenant_conversation_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('tenant_conversations')->cascadeOnDelete();
                $table->foreignId('sender_tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sender_name', 160);
                $table->text('body');
                $table->json('shared_context')->nullable();
                $table->json('attachments')->nullable();
                $table->boolean('is_urgent')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_conversation_messages');
        Schema::dropIfExists('tenant_conversations');

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'b2b_directory_visible')) {
                $table->dropColumn('b2b_directory_visible');
            }
        });
    }
};
