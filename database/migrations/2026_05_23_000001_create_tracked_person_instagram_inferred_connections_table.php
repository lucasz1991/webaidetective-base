<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_instagram_inferred_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')->index('tpiic_person_fk_idx');
            $table->foreignId('public_profile_id')->index('tpiic_public_profile_fk_idx');
            $table->foreignId('scan_id')->index('tpiic_scan_fk_idx');
            $table->foreignId('user_id')->index('tpiic_user_fk_idx');
            $table->string('source_public_username');
            $table->string('candidate_username');
            $table->string('candidate_display_name')->nullable();
            $table->text('candidate_profile_url')->nullable();
            $table->string('relationship_type', 50);
            $table->json('source_lists')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tracked_person_id', 'public_profile_id', 'relationship_type', 'candidate_username'],
                'tpiic_person_profile_type_candidate_unq',
            );
            $table->index(['tracked_person_id', 'relationship_type', 'last_seen_at'], 'tpiic_person_type_seen_idx');

            $table->foreign('tracked_person_id', 'tpiic_person_fk')
                ->references('id')
                ->on('tracked_people')
                ->cascadeOnDelete();
            $table->foreign('public_profile_id', 'tpiic_public_profile_fk')
                ->references('id')
                ->on('tracked_person_public_profiles')
                ->cascadeOnDelete();
            $table->foreign('scan_id', 'tpiic_scan_fk')
                ->references('id')
                ->on('tracked_person_instagram_public_profile_scans')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'tpiic_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_person_instagram_inferred_connections');
    }
};
