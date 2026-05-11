<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            $table->boolean('notify_social_changes')->default(false)->after('snapchat_username');
            $table->boolean('notify_instagram_changes')->default(true)->after('notify_social_changes');
            $table->boolean('notify_tiktok_changes')->default(true)->after('notify_instagram_changes');
            $table->boolean('notify_facebook_changes')->default(true)->after('notify_tiktok_changes');
            $table->boolean('notify_x_changes')->default(true)->after('notify_facebook_changes');
            $table->boolean('notify_youtube_changes')->default(true)->after('notify_x_changes');
            $table->boolean('notify_snapchat_changes')->default(true)->after('notify_youtube_changes');
        });

        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->boolean('has_changes')->default(false)->after('status_message');
            $table->json('detected_changes')->nullable()->after('has_changes');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'has_changes',
                'detected_changes',
            ]);
        });

        Schema::table('tracked_people', function (Blueprint $table) {
            $table->dropColumn([
                'notify_social_changes',
                'notify_instagram_changes',
                'notify_tiktok_changes',
                'notify_facebook_changes',
                'notify_x_changes',
                'notify_youtube_changes',
                'notify_snapchat_changes',
            ]);
        });
    }
};
