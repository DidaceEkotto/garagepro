<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_replay_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('client_uuid', 64);
            $table->string('method', 12);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status_code')->default(200);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'client_uuid'], 'offline_replay_tenant_uuid_unique');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_replay_keys');
    }
};
