<?php

use App\Models\Testimonial;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Testimonial::seedDefaults();
    }

    public function down(): void
    {
        //
    }
};
