<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instagram_story_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_profile_id')->constrained('instagram_profiles', indexName: 'ig_story_scans_profile_fk')->cascadeOnDelete();
            $table->foreignId('tracked_person_id')->nullable()->constrained('tracked_people', indexName: 'ig_story_scans_person_fk')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('tracked_person_instagram_snapshots', indexName: 'ig_story_scans_snapshot_fk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(indexName: 'ig_story_scans_user_fk')->nullOnDelete();
            $table->string('instagram_username', 64)->nullable();
            $table->string('scan_type', 20);
            $table->string('status_level', 30)->default('unknown');
            $table->text('status_message')->nullable();
            $table->boolean('attempted')->default(false);
            $table->boolean('available')->default(false);
            $table->boolean('complete')->default(false);
            $table->boolean('gracefully_stopped')->default(false);
            $table->unsignedInteger('observed_count')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['instagram_profile_id', 'scan_type', 'scanned_at'], 'ig_story_scans_profile_type_seen_idx');
            $table->index(['tracked_person_id', 'scan_type', 'scanned_at'], 'ig_story_scans_person_type_seen_idx');
        });

        Schema::create('instagram_story_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_story_scan_id')->constrained('instagram_story_scans', indexName: 'ig_story_items_scan_fk')->cascadeOnDelete();
            $table->foreignId('instagram_profile_id')->constrained('instagram_profiles', indexName: 'ig_story_items_profile_fk')->cascadeOnDelete();
            $table->string('item_key', 191);
            $table->string('source_type', 20);
            $table->string('highlight_id', 100)->nullable();
            $table->string('highlight_title', 191)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('media_type', 20)->default('image');
            $table->text('story_url')->nullable();
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
            $table->text('text')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('download_status', 30)->default('pending');
            $table->text('download_error')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamps();

            $table->unique(['instagram_story_scan_id', 'item_key'], 'ig_story_items_scan_key_unique');
            $table->index(['instagram_profile_id', 'source_type', 'position'], 'ig_story_items_profile_type_position_idx');
            $table->index(['highlight_id', 'position'], 'ig_story_items_highlight_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_story_items');
        Schema::dropIfExists('instagram_story_scans');
    }
};
