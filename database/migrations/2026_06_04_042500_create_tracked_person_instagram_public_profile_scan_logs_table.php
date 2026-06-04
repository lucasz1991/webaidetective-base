<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_instagram_public_profile_scan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->index('tpippsl_scan_fk_idx');
            $table->foreignId('tracked_person_id')->index('tpippsl_person_fk_idx');
            $table->foreignId('public_profile_id')->index('tpippsl_profile_fk_idx');
            $table->foreignId('user_id')->index('tpippsl_user_fk_idx');
            $table->string('event_type', 50);
            $table->string('status_level', 50)->nullable();
            $table->string('stage', 100)->nullable();
            $table->text('message')->nullable();
            $table->longText('detail')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->nullable();
            $table->timestamps();

            $table->index(['scan_id', 'logged_at'], 'tpippsl_scan_logged_idx');
            $table->index(['tracked_person_id', 'logged_at'], 'tpippsl_person_logged_idx');

            $table->foreign('scan_id', 'tpippsl_scan_fk')
                ->references('id')
                ->on('tracked_person_instagram_public_profile_scans')
                ->cascadeOnDelete();
            $table->foreign('tracked_person_id', 'tpippsl_person_fk')
                ->references('id')
                ->on('tracked_people')
                ->cascadeOnDelete();
            $table->foreign('public_profile_id', 'tpippsl_profile_fk')
                ->references('id')
                ->on('tracked_person_public_profiles')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'tpippsl_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_person_instagram_public_profile_scan_logs');
    }
};
