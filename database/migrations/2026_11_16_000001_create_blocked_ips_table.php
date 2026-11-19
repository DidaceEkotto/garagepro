<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->index();
            $table->string('reason', 255);
            $table->boolean('is_whitelisted')->default(false)->index();
            $table->boolean('permanent')->default(false);
            $table->timestamp('blocked_until')->nullable()->index();
            $table->unsignedInteger('attempts_count')->default(1);
            $table->string('created_by', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
