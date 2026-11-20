<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('first_name', 100)->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('id_number', 100)->nullable()->index();
            $table->string('phone_dial_code', 15)->nullable();
            $table->string('phone', 50)->nullable()->index();
            $table->string('whatsapp_phone', 50)->nullable()->index();
            $table->string('email', 150)->nullable();
            $table->string('profession', 120)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('emergency_contact', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('otp_code', 12)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'global_customer_id')) {
                $table->foreignId('global_customer_id')
                    ->nullable()
                    ->after('tenant_id')
                    ->constrained('global_customers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'global_customer_id')) {
                $table->dropConstrainedForeignId('global_customer_id');
            }
        });

        Schema::dropIfExists('global_customers');
    }
};
