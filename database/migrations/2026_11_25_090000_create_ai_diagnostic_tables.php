<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_diagnostic_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('title', 180)->default('Nouveau diagnostic');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('ai_diagnostic_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ai_diagnostic_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20); // 'user' | 'assistant'
            $table->longText('content');
            $table->string('model', 80)->nullable();
            $table->boolean('is_off_topic')->default(false);
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_diagnostic_messages');
        Schema::dropIfExists('ai_diagnostic_conversations');
    }
};
