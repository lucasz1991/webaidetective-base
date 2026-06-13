<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instagram_profile_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_profile_id')->constrained(indexName: 'ig_profile_scans_profile_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(indexName: 'ig_profile_scans_user_fk')->cascadeOnDelete();
            $table->string('scan_mode', 50);
            $table->string('status_level', 50)->default('unknown');
            $table->text('status_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['instagram_profile_id', 'user_id', 'scanned_at'], 'ig_profile_scans_lookup_idx');
        });

        Schema::table('tracked_person_instagram_suggestion_scans', function (Blueprint $table) {
            $table->dropForeign('tpisg_person_fk');
        });

        $this->makeUnsignedBigIntegerNullable(
            'tracked_person_instagram_suggestion_scans',
            'tracked_person_id',
        );

        Schema::table('tracked_person_instagram_suggestion_scans', function (Blueprint $table) {
            $table->foreign('tracked_person_id', 'tpisg_person_fk')
                ->references('id')
                ->on('tracked_people')
                ->nullOnDelete();
            $table->foreignId('instagram_profile_id')
                ->nullable()
                ->after('tracked_person_id')
                ->constrained(indexName: 'tpisg_profile_fk')
                ->cascadeOnDelete();
            $table->index(['instagram_profile_id', 'user_id', 'analyzed_at'], 'tpisg_profile_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_person_instagram_suggestion_scans', function (Blueprint $table) {
            $table->dropIndex('tpisg_profile_user_idx');
            $table->dropForeign('tpisg_profile_fk');
            $table->dropColumn('instagram_profile_id');
            $table->dropForeign('tpisg_person_fk');
        });

        DB::table('tracked_person_instagram_suggestion_scans')
            ->whereNull('tracked_person_id')
            ->delete();

        $this->makeUnsignedBigIntegerRequired(
            'tracked_person_instagram_suggestion_scans',
            'tracked_person_id',
        );

        Schema::table('tracked_person_instagram_suggestion_scans', function (Blueprint $table) {
            $table->foreign('tracked_person_id', 'tpisg_person_fk')
                ->references('id')
                ->on('tracked_people')
                ->cascadeOnDelete();
        });

        Schema::dropIfExists('instagram_profile_scans');
    }

    private function makeUnsignedBigIntegerNullable(string $table, string $column): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NULL");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column): void {
            $table->unsignedBigInteger($column)->nullable()->change();
        });
    }

    private function makeUnsignedBigIntegerRequired(string $table, string $column): void
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
