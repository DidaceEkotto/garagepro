<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'public_token')) {
                $table->string('public_token', 48)->nullable()->unique()->after('quote_number');
            }
            if (! Schema::hasColumn('quotes', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('signed_at');
            }
            if (! Schema::hasColumn('quotes', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
            }
            if (! Schema::hasColumn('quotes', 'signed_ip')) {
                $table->string('signed_ip', 45)->nullable()->after('signed_at');
            }
            if (! Schema::hasColumn('quotes', 'signed_by_name')) {
                $table->string('signed_by_name', 150)->nullable()->after('signed_ip');
            }
            if (! Schema::hasColumn('quotes', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $cols = array_filter(
                ['public_token', 'rejection_reason', 'rejected_at', 'signed_ip', 'signed_by_name', 'sent_at'],
                fn ($c) => Schema::hasColumn('quotes', $c)
            );
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
