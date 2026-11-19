<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('avatar_path');
            $table->string('job_title', 120)->nullable()->after('gender');
            $table->text('bio')->nullable()->after('job_title');
            $table->string('phone', 50)->nullable()->after('bio');
            $table->date('birth_date')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['gender', 'job_title', 'bio', 'phone', 'birth_date']);
        });
    }
};
