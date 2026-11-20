<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('gender', 20)->nullable()->after('first_name');
            $table->string('id_number', 100)->nullable()->after('gender');
            $table->string('phone_dial_code', 15)->nullable()->after('id_number');
            $table->string('whatsapp_phone', 50)->nullable()->after('phone');
            $table->string('photo_path', 255)->nullable()->after('whatsapp_phone');
            $table->text('description')->nullable()->after('notes');
            $table->string('profession', 120)->nullable()->after('description');
            $table->date('date_of_birth')->nullable()->after('profession');
            $table->string('emergency_contact', 150)->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'gender',
                'id_number',
                'phone_dial_code',
                'whatsapp_phone',
                'photo_path',
                'description',
                'profession',
                'date_of_birth',
                'emergency_contact',
            ]);
        });
    }
};
