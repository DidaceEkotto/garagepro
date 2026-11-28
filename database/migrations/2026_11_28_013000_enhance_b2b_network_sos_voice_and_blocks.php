<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_conversation_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_conversation_messages', 'audio_path')) {
                $table->string('audio_path', 255)->nullable()->after('attachments');
            }
            if (! Schema::hasColumn('tenant_conversation_messages', 'audio_duration')) {
                $table->unsignedSmallInteger('audio_duration')->nullable()->after('audio_path');
            }
        });

        if (! Schema::hasTable('b2b_network_requests')) {
            Schema::create('b2b_network_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('city', 120)->nullable();
                $table->string('category', 30)->default('piece'); // piece, diagnostic, sous_traitance, outillage
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->boolean('is_urgent')->default(false);
                $table->string('status', 20)->default('open'); // open, resolved
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'city']);
            });
        }

        if (! Schema::hasTable('tenant_network_blocks')) {
            Schema::create('tenant_network_blocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('blocker_tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('blocked_tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['blocker_tenant_id', 'blocked_tenant_id'], 'tenant_block_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_network_blocks');
        Schema::dropIfExists('b2b_network_requests');

        Schema::table('tenant_conversation_messages', function (Blueprint $table) {
            foreach (['audio_duration', 'audio_path'] as $col) {
                if (Schema::hasColumn('tenant_conversation_messages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
