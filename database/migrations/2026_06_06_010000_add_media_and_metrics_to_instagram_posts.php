<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('instagram_posts', function (Blueprint $table) {
            $table->string('media_pk')->nullable()->after('shortcode')->index('ig_posts_media_pk_idx');
            $table->text('thumbnail_path')->nullable()->after('thumbnail_url');
            $table->unsignedSmallInteger('media_count')->default(0)->after('thumbnail_path');
        });

        Schema::create('instagram_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_post_id')->constrained('instagram_posts', indexName: 'ig_post_media_post_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('media_type', 20);
            $table->text('source_url')->nullable();
            $table->text('preview_url')->nullable();
            $table->text('storage_path')->nullable();
            $table->text('preview_storage_path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('download_status', 30)->default('pending');
            $table->text('download_error')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['instagram_post_id', 'position'], 'ig_post_media_post_position_unique');
            $table->index(['download_status', 'updated_at'], 'ig_post_media_status_updated_idx');
            $table->index(['content_hash', 'media_type'], 'ig_post_media_hash_type_idx');
        });

        Schema::create('instagram_post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_post_id')->constrained('instagram_posts', indexName: 'ig_post_metrics_post_fk')->cascadeOnDelete();
            $table->foreignId('instagram_post_scan_id')->constrained('instagram_post_scans', indexName: 'ig_post_metrics_scan_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('likes_count')->nullable();
            $table->unsignedBigInteger('comments_count')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['instagram_post_id', 'instagram_post_scan_id'], 'ig_post_metrics_post_scan_unique');
            $table->index(['instagram_post_id', 'observed_at'], 'ig_post_metrics_post_seen_idx');
            $table->index(['instagram_post_scan_id', 'observed_at'], 'ig_post_metrics_scan_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_post_metrics');
        Schema::dropIfExists('instagram_post_media');

        Schema::table('instagram_posts', function (Blueprint $table) {
            $table->dropIndex('ig_posts_media_pk_idx');
            $table->dropColumn(['media_pk', 'thumbnail_path', 'media_count']);
        });
    }
};
