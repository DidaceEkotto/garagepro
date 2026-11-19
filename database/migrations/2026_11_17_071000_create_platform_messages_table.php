<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('platform_messages')->nullOnDelete();
            $table->string('sender_type', 20); // admin | tenant | customer
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('sender_name', 150);
            $table->string('sender_email', 150)->nullable();
            $table->string('recipient_type', 20); // admin | tenant | customer
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_email', 150)->nullable();
            $table->string('subject', 200);
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'recipient_type', 'is_read']);
            $table->index(['recipient_type', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_messages');
    }
};
