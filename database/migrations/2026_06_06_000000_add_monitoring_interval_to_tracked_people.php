<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            $table->unsignedInteger('monitoring_interval_minutes')
                ->default(60)
                ->after('monitoring_enabled');
            $table->index(
                ['monitoring_enabled', 'last_instagram_analyzed_at'],
                'tracked_people_monitoring_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            $table->dropIndex('tracked_people_monitoring_due_idx');
            $table->dropColumn('monitoring_interval_minutes');
        });
    }
};
