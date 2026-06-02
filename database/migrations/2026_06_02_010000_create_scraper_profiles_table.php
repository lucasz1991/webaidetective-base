<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('scraper_profiles')) {
            return;
        }

        Schema::create('scraper_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 50)->default('instagram');
            $table->string('profile_key');
            $table->string('profile_label');
            $table->string('browser_profile_path')->nullable();
            $table->string('cookie_file_path')->nullable();
            $table->boolean('persistent_profile_enabled')->default(true);
            $table->boolean('headless_enabled')->default(true);
            $table->boolean('auto_login_enabled')->default(false);
            $table->string('login_username')->nullable();
            $table->longText('login_password_encrypted')->nullable();
            $table->longText('login_password_base_encrypted')->nullable();
            $table->unsignedInteger('navigation_timeout_seconds')->default(120);
            $table->unsignedInteger('post_login_wait_ms')->default(2500);
            $table->unsignedInteger('typing_delay_ms')->default(35);
            $table->unsignedInteger('relationship_list_process_timeout_seconds')->default(14400);
            $table->unsignedInteger('relationship_list_max_scroll_rounds')->default(100000);
            $table->unsignedInteger('follower_list_max_items')->default(0);
            $table->unsignedInteger('following_list_max_items')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->longText('cookie_payload')->nullable();
            $table->string('cookie_payload_hash', 64)->nullable();
            $table->unsignedInteger('cookie_count')->default(0);
            $table->boolean('session_cookie_present')->default(false);
            $table->timestamp('cookies_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['platform', 'profile_key'], 'scraper_profiles_platform_key_unq');
            $table->index(['platform', 'is_active', 'is_primary'], 'scraper_profiles_platform_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_profiles');
    }
};
