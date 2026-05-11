<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('alias')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->text('notes')->nullable();
            $table->string('instagram_username')->nullable();
            $table->string('tiktok_username')->nullable();
            $table->string('facebook_username')->nullable();
            $table->string('x_username')->nullable();
            $table->string('youtube_username')->nullable();
            $table->string('snapchat_username')->nullable();
            $table->string('profile_image_path')->nullable();
            $table->string('instagram_profile_image_path')->nullable();
            $table->unsignedBigInteger('instagram_followers_count')->nullable();
            $table->unsignedBigInteger('instagram_following_count')->nullable();
            $table->unsignedBigInteger('instagram_posts_count')->nullable();
            $table->string('last_instagram_status_level', 20)->nullable();
            $table->text('last_instagram_status_message')->nullable();
            $table->timestamp('last_instagram_analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_name', 'first_name']);
            $table->index(['user_id', 'instagram_username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_people');
    }
};
