<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')
                ->constrained(table: 'tracked_people', indexName: 'tpis_person_fk')
                ->cascadeOnDelete();
            $table->string('instagram_username');
            $table->string('full_name')->nullable();
            $table->text('biography')->nullable();
            $table->unsignedBigInteger('posts_count')->nullable();
            $table->unsignedBigInteger('followers_count')->nullable();
            $table->unsignedBigInteger('following_count')->nullable();
            $table->text('profile_image_url')->nullable();
            $table->string('profile_image_path')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->string('html_path')->nullable();
            $table->string('status_level', 20)->default('error');
            $table->text('status_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('analyzed_at');
            $table->timestamps();

            $table->index(['tracked_person_id', 'analyzed_at'], 'tpis_person_analyzed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_person_instagram_snapshots');
    }
};
