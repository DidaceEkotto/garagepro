<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'auto_reminded_at')) {
                $table->timestamp('auto_reminded_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (Schema::hasColumn('quotes', 'auto_reminded_at')) {
                $table->dropColumn('auto_reminded_at');
            }
        });
    }
};
