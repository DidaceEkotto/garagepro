<?php

use App\Models\Country;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name', 120);
            $table->string('dial_code', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Country::seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
