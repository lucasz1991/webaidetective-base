<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_public_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')->constrained('tracked_people')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 50);
            $table->string('username');
            $table->string('display_name')->nullable();
            $table->string('relationship_type', 50)->default('public_connection');
            $table->text('profile_url')->nullable();
            $table->boolean('is_public')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tracked_person_id', 'platform'], 'tppp_person_platform_idx');
            $table->unique(['tracked_person_id', 'platform', 'username'], 'tppp_person_platform_user_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_person_public_profiles');
    }
};
