<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mails', function (Blueprint $table) {
            if (! Schema::hasColumn('mails', 'type')) {
                $table->string('type')->default('message')->after('id');
            }

            if (! Schema::hasColumn('mails', 'from_user_id')) {
                $table->foreignId('from_user_id')
                    ->nullable()
                    ->after('type')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mails', function (Blueprint $table) {
            if (Schema::hasColumn('mails', 'from_user_id')) {
                $table->dropForeign(['from_user_id']);
                $table->dropColumn('from_user_id');
            }

            if (Schema::hasColumn('mails', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
