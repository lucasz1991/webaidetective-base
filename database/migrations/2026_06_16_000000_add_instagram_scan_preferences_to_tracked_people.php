<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            $table->json('instagram_scan_preferences')
                ->nullable()
                ->after('monitoring_interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            $table->dropColumn('instagram_scan_preferences');
        });
    }
};
