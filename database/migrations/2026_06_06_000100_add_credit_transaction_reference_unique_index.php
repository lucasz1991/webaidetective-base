<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'type', 'reference_type', 'reference_id'],
                'credit_transactions_scan_reference_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropUnique('credit_transactions_scan_reference_unique');
        });
    }
};
