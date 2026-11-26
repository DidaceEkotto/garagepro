<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'customers',
        'vehicles',
        'technical_inspections',
        'insurance_policies',
        'insurance_claims',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'client_uuid')) {
                    $table->string('client_uuid', 64)->nullable()->after('tenant_id');
                    $table->unique(['tenant_id', 'client_uuid'], "{$tableName}_tenant_client_uuid_unique");
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'client_uuid')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique("{$tableName}_tenant_client_uuid_unique");
                $table->dropColumn('client_uuid');
            });
        }
    }
};
