<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instagram_post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_post_id')->constrained('instagram_posts', indexName: 'ig_post_likes_post_fk')->cascadeOnDelete();
            $table->foreignId('instagram_profile_id')->nullable()->constrained('instagram_profiles', indexName: 'ig_post_likes_profile_fk')->nullOnDelete();
            $table->foreignId('first_seen_scan_id')->nullable()->constrained('instagram_post_scans', indexName: 'ig_post_likes_first_scan_fk')->nullOnDelete();
            $table->foreignId('last_seen_scan_id')->nullable()->constrained('instagram_post_scans', indexName: 'ig_post_likes_last_scan_fk')->nullOnDelete();
            $table->string('liker_key', 191);
            $table->string('instagram_user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->text('profile_image_url')->nullable();
            $table->boolean('is_verified')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->json('raw_like')->nullable();
            $table->timestamps();

            $table->unique(['instagram_post_id', 'liker_key'], 'ig_post_likes_post_liker_unique');
            $table->index(['instagram_post_id', 'is_active'], 'ig_post_likes_post_active_idx');
            $table->index(['username', 'is_active'], 'ig_post_likes_username_active_idx');
            $table->index(['instagram_profile_id', 'is_active'], 'ig_post_likes_profile_active_idx');
        });

        Schema::create('instagram_post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_comment_id')->nullable();
            $table->foreignId('instagram_post_id')->constrained('instagram_posts', indexName: 'ig_post_comments_post_fk')->cascadeOnDelete();
            $table->foreignId('instagram_profile_id')->nullable()->constrained('instagram_profiles', indexName: 'ig_post_comments_profile_fk')->nullOnDelete();
            $table->foreignId('first_seen_scan_id')->nullable()->constrained('instagram_post_scans', indexName: 'ig_post_comments_first_scan_fk')->nullOnDelete();
            $table->foreignId('last_seen_scan_id')->nullable()->constrained('instagram_post_scans', indexName: 'ig_post_comments_last_scan_fk')->nullOnDelete();
            $table->string('instagram_comment_id');
            $table->string('parent_instagram_comment_id')->nullable();
            $table->string('instagram_user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->text('profile_image_url')->nullable();
            $table->text('comment_text');
            $table->unsignedBigInteger('likes_count')->nullable();
            $table->boolean('is_verified')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->json('raw_comment')->nullable();
            $table->timestamps();

            $table->unique(['instagram_post_id', 'instagram_comment_id'], 'ig_post_comments_post_comment_unique');
            $table->foreign('parent_comment_id', 'ig_post_comments_parent_fk')
                ->references('id')
                ->on('instagram_post_comments')
                ->nullOnDelete();
            $table->index(['instagram_post_id', 'is_active', 'published_at'], 'ig_post_comments_post_active_time_idx');
            $table->index(['username', 'is_active'], 'ig_post_comments_username_active_idx');
            $table->index(['instagram_profile_id', 'is_active'], 'ig_post_comments_profile_active_idx');
            $table->index(['parent_comment_id', 'is_active'], 'ig_post_comments_parent_active_idx');
            $table->index(['instagram_post_id', 'parent_instagram_comment_id'], 'ig_post_comments_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_post_comments');
        Schema::dropIfExists('instagram_post_likes');
    }
};
