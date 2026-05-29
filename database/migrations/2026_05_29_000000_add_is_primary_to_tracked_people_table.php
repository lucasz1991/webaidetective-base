<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('tracked_people', 'is_primary')) {
            Schema::table('tracked_people', function (Blueprint $table) {
                $table->boolean('is_primary')->default(false)->after('monitoring_enabled');
                $table->index(['user_id', 'is_primary'], 'tracked_people_user_primary_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tracked_people', 'is_primary')) {
            Schema::table('tracked_people', function (Blueprint $table) {
                $table->dropIndex('tracked_people_user_primary_idx');
                $table->dropColumn('is_primary');
            });
        }
    }
};
