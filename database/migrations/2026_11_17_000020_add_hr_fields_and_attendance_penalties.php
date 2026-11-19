<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('phone'); // M, F
            $table->string('marital_status', 30)->nullable()->after('gender'); // celibataire, marie, divorce, veuf
            $table->date('birth_date')->nullable()->after('marital_status');
            $table->string('birth_place', 120)->nullable()->after('birth_date');
            $table->string('photo_path', 255)->nullable()->after('birth_place');
        });

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->unsignedInteger('late_minutes')->default(0)->after('checked_out_at');
            $table->unsignedBigInteger('late_penalty')->default(0)->after('late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropColumn(['late_minutes', 'late_penalty']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['gender', 'marital_status', 'birth_date', 'birth_place', 'photo_path']);
        });
    }
};
