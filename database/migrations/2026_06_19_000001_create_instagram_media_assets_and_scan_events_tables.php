<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instagram_media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('instagram_username', 64);
            $table->string('media_role', 40);
            $table->string('media_type', 20)->default('image');
            $table->text('source_url')->nullable();
            $table->char('source_url_hash', 64)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->text('storage_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['instagram_username', 'media_role', 'source_url_hash'], 'ig_media_assets_username_role_url_unique');
            $table->index(['instagram_username', 'media_role', 'content_hash'], 'ig_media_assets_username_role_hash_idx');
            $table->index(['content_hash', 'media_type'], 'ig_media_assets_hash_type_idx');
        });

        Schema::create('instagram_scan_events', function (Blueprint $table) {
            $table->id();
            $table->string('scan_type', 60);
            $table->unsignedBigInteger('scan_id')->nullable();
            $table->string('instagram_username', 64)->nullable();
            $table->foreignId('tracked_person_id')->nullable()->constrained('tracked_people', indexName: 'ig_scan_events_person_fk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(indexName: 'ig_scan_events_user_fk')->nullOnDelete();
            $table->string('phase', 80)->nullable();
            $table->string('stage', 120)->nullable();
            $table->string('status_level', 30)->nullable();
            $table->unsignedTinyInteger('percent')->nullable();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['scan_type', 'scan_id', 'occurred_at'], 'ig_scan_events_type_scan_time_idx');
            $table->index(['instagram_username', 'occurred_at'], 'ig_scan_events_username_time_idx');
            $table->index(['tracked_person_id', 'occurred_at'], 'ig_scan_events_person_time_idx');
        });

        if (Schema::hasTable('instagram_post_scans') && ! Schema::hasColumn('instagram_post_scans', 'instagram_username')) {
            Schema::table('instagram_post_scans', function (Blueprint $table) {
                $table->string('instagram_username', 64)->nullable()->after('user_id')->index('ig_post_scans_username_idx');
            });
        }

        if (Schema::hasTable('instagram_profile_list_scans') && ! Schema::hasColumn('instagram_profile_list_scans', 'instagram_username')) {
            Schema::table('instagram_profile_list_scans', function (Blueprint $table) {
                $table->string('instagram_username', 64)->nullable()->after('user_id')->index('ig_list_scans_username_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instagram_profile_list_scans') && Schema::hasColumn('instagram_profile_list_scans', 'instagram_username')) {
            Schema::table('instagram_profile_list_scans', function (Blueprint $table) {
                $table->dropIndex('ig_list_scans_username_idx');
                $table->dropColumn('instagram_username');
            });
        }

        if (Schema::hasTable('instagram_post_scans') && Schema::hasColumn('instagram_post_scans', 'instagram_username')) {
            Schema::table('instagram_post_scans', function (Blueprint $table) {
                $table->dropIndex('ig_post_scans_username_idx');
                $table->dropColumn('instagram_username');
            });
        }

        Schema::dropIfExists('instagram_scan_events');
        Schema::dropIfExists('instagram_media_assets');
    }
};
