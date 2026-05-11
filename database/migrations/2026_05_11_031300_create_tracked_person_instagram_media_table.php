<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_instagram_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_instagram_snapshot_id')
                ->constrained(table: 'tracked_person_instagram_snapshots', indexName: 'tpim_snapshot_fk')
                ->cascadeOnDelete();
            $table->foreignId('tracked_person_id')
                ->constrained(table: 'tracked_people', indexName: 'tpim_person_fk')
                ->cascadeOnDelete();
            $table->string('media_type', 30)->default('image');
            $table->boolean('is_profile_image')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('source_url');
            $table->string('storage_path')->nullable();
            $table->timestamps();

            $table->index(['tracked_person_id', 'is_profile_image'], 'tpim_person_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_person_instagram_media');
    }
};
