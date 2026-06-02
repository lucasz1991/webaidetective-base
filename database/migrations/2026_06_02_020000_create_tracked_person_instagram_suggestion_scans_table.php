<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_instagram_suggestion_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')->constrained('tracked_people', indexName: 'tpisg_person_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(indexName: 'tpisg_user_fk')->nullOnDelete();
            $table->string('target_username');
            $table->string('status_level', 50)->default('unknown');
            $table->text('status_message')->nullable();
            $table->unsignedInteger('suggestions_observed_count')->default(0);
            $table->unsignedInteger('suggestions_checked_count')->default(0);
            $table->unsignedInteger('suggestion_matches_count')->default(0);
            $table->boolean('gracefully_stopped')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['tracked_person_id', 'analyzed_at'], 'tpisg_person_analyzed_idx');
            $table->index(['target_username', 'analyzed_at'], 'tpisg_target_analyzed_idx');
        });

        Schema::table('tracked_person_instagram_inferred_connections', function (Blueprint $table) {
            $table->dropForeign('tpiic_public_profile_fk');
            $table->dropForeign('tpiic_scan_fk');
        });

        $this->makeForeignKeyNullable('tracked_person_instagram_inferred_connections', 'public_profile_id');
        $this->makeForeignKeyNullable('tracked_person_instagram_inferred_connections', 'scan_id');

        Schema::table('tracked_person_instagram_inferred_connections', function (Blueprint $table) {
            $table->foreign('public_profile_id', 'tpiic_public_profile_fk')
                ->references('id')
                ->on('tracked_person_public_profiles')
                ->nullOnDelete();
            $table->foreign('scan_id', 'tpiic_scan_fk')
                ->references('id')
                ->on('tracked_person_instagram_public_profile_scans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tracked_person_instagram_inferred_connections', function (Blueprint $table) {
            $table->dropForeign('tpiic_public_profile_fk');
            $table->dropForeign('tpiic_scan_fk');
        });

        DB::table('tracked_person_instagram_inferred_connections')
            ->whereNull('public_profile_id')
            ->orWhereNull('scan_id')
            ->delete();

        $this->makeForeignKeyRequired('tracked_person_instagram_inferred_connections', 'public_profile_id');
        $this->makeForeignKeyRequired('tracked_person_instagram_inferred_connections', 'scan_id');

        Schema::table('tracked_person_instagram_inferred_connections', function (Blueprint $table) {
            $table->foreign('public_profile_id', 'tpiic_public_profile_fk')
                ->references('id')
                ->on('tracked_person_public_profiles')
                ->cascadeOnDelete();
            $table->foreign('scan_id', 'tpiic_scan_fk')
                ->references('id')
                ->on('tracked_person_instagram_public_profile_scans')
                ->cascadeOnDelete();
        });

        Schema::dropIfExists('tracked_person_instagram_suggestion_scans');
    }

    private function makeForeignKeyNullable(string $table, string $column): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NULL");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column): void {
            $table->unsignedBigInteger($column)->nullable()->change();
        });
    }

    private function makeForeignKeyRequired(string $table, string $column): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NOT NULL");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column): void {
            $table->unsignedBigInteger($column)->nullable(false)->change();
        });
    }
};
