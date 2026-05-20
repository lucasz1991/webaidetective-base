<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            $table->string('instagram_profile_id')->nullable()->after('instagram_username');
            $table->index(['user_id', 'instagram_profile_id'], 'tracked_people_instagram_profile_id_idx');
        });

        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->string('instagram_profile_id')->nullable()->after('instagram_username');
            $table->index(['tracked_person_id', 'instagram_profile_id'], 'tpis_profile_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->dropIndex('tpis_profile_id_idx');
            $table->dropColumn('instagram_profile_id');
        });

        Schema::table('tracked_people', function (Blueprint $table) {
            $table->dropIndex('tracked_people_instagram_profile_id_idx');
            $table->dropColumn('instagram_profile_id');
        });
    }
};
