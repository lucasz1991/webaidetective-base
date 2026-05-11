<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            $table->boolean('monitoring_enabled')->default(false)->after('notify_snapchat_changes');
            $table->string('profile_image_hash', 64)->nullable()->after('profile_image_path');
            $table->string('instagram_profile_image_hash', 64)->nullable()->after('instagram_profile_image_path');
        });

        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->string('profile_image_hash', 64)->nullable()->after('profile_image_path');
        });

        Schema::table('tracked_person_instagram_media', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_person_instagram_media', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });

        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->dropColumn('profile_image_hash');
        });

        Schema::table('tracked_people', function (Blueprint $table) {
            $table->dropColumn([
                'monitoring_enabled',
                'profile_image_hash',
                'instagram_profile_image_hash',
            ]);
        });
    }
};
