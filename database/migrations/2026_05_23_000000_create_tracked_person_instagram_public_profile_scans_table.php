<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_instagram_public_profile_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')->index('tpipp_scans_person_fk_idx');
            $table->foreignId('public_profile_id')->index('tpipp_scans_profile_fk_idx');
            $table->foreignId('user_id')->index('tpipp_scans_user_fk_idx');
            $table->string('target_username');
            $table->string('public_username');
            $table->string('relation_type', 50)->default('unknown');
            $table->boolean('public_profile_follows_target')->default(false);
            $table->boolean('target_follows_public_profile')->default(false);
            $table->boolean('followers_checked')->default(false);
            $table->boolean('followers_available')->default(false);
            $table->boolean('followers_complete')->default(false);
            $table->unsignedInteger('followers_observed_count')->nullable();
            $table->unsignedInteger('followers_expected_count')->nullable();
            $table->json('followers_match')->nullable();
            $table->boolean('following_checked')->default(false);
            $table->boolean('following_available')->default(false);
            $table->boolean('following_complete')->default(false);
            $table->unsignedInteger('following_observed_count')->nullable();
            $table->unsignedInteger('following_expected_count')->nullable();
            $table->json('following_match')->nullable();
            $table->string('status_level', 50)->default('unknown');
            $table->text('status_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['tracked_person_id', 'analyzed_at'], 'tpipp_scans_person_analyzed_idx');
            $table->index(['public_profile_id', 'analyzed_at'], 'tpipp_scans_profile_analyzed_idx');
            $table->index(['relation_type', 'analyzed_at'], 'tpipp_scans_relation_analyzed_idx');

            $table->foreign('tracked_person_id', 'tpipp_scans_person_fk')
                ->references('id')
                ->on('tracked_people')
                ->cascadeOnDelete();
            $table->foreign('public_profile_id', 'tpipp_scans_profile_fk')
                ->references('id')
                ->on('tracked_person_public_profiles')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'tpipp_scans_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_person_instagram_public_profile_scans');
    }
};
