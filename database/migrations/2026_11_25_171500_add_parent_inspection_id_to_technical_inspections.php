<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_inspections', function (Blueprint $table) {
            if (! Schema::hasColumn('technical_inspections', 'parent_inspection_id')) {
                $table->foreignId('parent_inspection_id')
                    ->nullable()
                    ->after('agency_id')
                    ->constrained('technical_inspections')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('technical_inspections', function (Blueprint $table) {
            if (Schema::hasColumn('technical_inspections', 'parent_inspection_id')) {
                $table->dropConstrainedForeignId('parent_inspection_id');
            }
        });
    }
};
