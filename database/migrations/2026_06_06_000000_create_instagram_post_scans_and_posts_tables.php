<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instagram_post_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_profile_id')->constrained('instagram_profiles', indexName: 'ig_post_scans_profile_fk')->cascadeOnDelete();
            $table->foreignId('tracked_person_id')->nullable()->constrained('tracked_people', indexName: 'ig_post_scans_person_fk')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('tracked_person_instagram_snapshots', indexName: 'ig_post_scans_snapshot_fk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(indexName: 'ig_post_scans_user_fk')->nullOnDelete();
            $table->string('status_level', 30)->default('unknown');
            $table->text('status_message')->nullable();
            $table->boolean('attempted')->default(false);
            $table->boolean('available')->default(false);
            $table->boolean('complete')->default(false);
            $table->boolean('rate_limited')->default(false);
            $table->boolean('gracefully_stopped')->default(false);
            $table->unsignedInteger('observed_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['instagram_profile_id', 'scanned_at'], 'ig_post_scans_profile_seen_idx');
            $table->index(['tracked_person_id', 'scanned_at'], 'ig_post_scans_person_seen_idx');
        });

        Schema::create('instagram_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_profile_id')->constrained('instagram_profiles', indexName: 'ig_posts_profile_fk')->cascadeOnDelete();
            $table->foreignId('first_seen_scan_id')->nullable()->constrained('instagram_post_scans', indexName: 'ig_posts_first_scan_fk')->nullOnDelete();
            $table->foreignId('last_seen_scan_id')->nullable()->constrained('instagram_post_scans', indexName: 'ig_posts_last_scan_fk')->nullOnDelete();
            $table->string('shortcode')->unique();
            $table->string('media_type', 30)->default('post');
            $table->text('post_url');
            $table->text('thumbnail_url')->nullable();
            $table->text('caption')->nullable();
            $table->unsignedBigInteger('likes_count')->nullable();
            $table->unsignedBigInteger('comments_count')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->json('raw_post')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['instagram_profile_id', 'published_at'], 'ig_posts_profile_published_idx');
            $table->index(['instagram_profile_id', 'last_scanned_at'], 'ig_posts_profile_scanned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_posts');
        Schema::dropIfExists('instagram_post_scans');
    }
};
