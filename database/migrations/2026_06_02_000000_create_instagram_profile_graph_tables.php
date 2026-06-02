<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instagram_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('display_name')->nullable();
            $table->string('full_name')->nullable();
            $table->text('biography')->nullable();
            $table->text('profile_url')->nullable();
            $table->text('profile_image_url')->nullable();
            $table->string('profile_image_path')->nullable();
            $table->string('profile_image_hash')->nullable();
            $table->boolean('is_private')->nullable();
            $table->string('profile_visibility', 30)->default('unknown');
            $table->unsignedBigInteger('followers_count')->nullable();
            $table->unsignedBigInteger('following_count')->nullable();
            $table->unsignedBigInteger('posts_count')->nullable();
            $table->string('last_status_level', 30)->nullable();
            $table->text('last_status_message')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->json('raw_profile')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['profile_visibility', 'last_scanned_at'], 'ig_profiles_visibility_scanned_idx');
        });

        Schema::table('tracked_people', function (Blueprint $table) {
            $table->foreignId('current_instagram_profile_id')
                ->nullable()
                ->after('instagram_username')
                ->constrained('instagram_profiles', indexName: 'tp_current_ig_profile_fk')
                ->nullOnDelete();
        });

        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->foreignId('instagram_profile_id')
                ->nullable()
                ->after('tracked_person_id')
                ->constrained('instagram_profiles', indexName: 'tpis_ig_profile_fk')
                ->nullOnDelete();
        });

        Schema::table('tracked_person_public_profiles', function (Blueprint $table) {
            $table->foreignId('instagram_profile_id')
                ->nullable()
                ->after('user_id')
                ->constrained('instagram_profiles', indexName: 'tppp_ig_profile_fk')
                ->nullOnDelete();
        });

        Schema::table('tracked_person_instagram_inferred_connections', function (Blueprint $table) {
            $table->foreignId('source_public_instagram_profile_id')
                ->nullable()
                ->after('source_public_username')
                ->constrained('instagram_profiles', indexName: 'tpiic_source_ig_profile_fk')
                ->nullOnDelete();
            $table->foreignId('candidate_instagram_profile_id')
                ->nullable()
                ->after('candidate_username')
                ->constrained('instagram_profiles', indexName: 'tpiic_candidate_ig_profile_fk')
                ->nullOnDelete();
        });

        Schema::create('tracked_person_instagram_profile_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')->constrained('tracked_people')->cascadeOnDelete();
            $table->foreignId('instagram_profile_id')->constrained('instagram_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('relation_type', 50)->default('observed');
            $table->boolean('is_current')->default(true);
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tracked_person_id', 'is_current'], 'tp_ig_links_person_current_idx');
            $table->index(['instagram_profile_id', 'relation_type'], 'tp_ig_links_profile_type_idx');
        });

        Schema::create('instagram_profile_list_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_profile_id')->constrained('instagram_profiles')->cascadeOnDelete();
            $table->foreignId('tracked_person_id')->nullable()->constrained('tracked_people')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('tracked_person_instagram_snapshots')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('list_type', 20);
            $table->string('scan_mode', 50)->nullable();
            $table->string('status_level', 30)->default('unknown');
            $table->text('status_message')->nullable();
            $table->boolean('attempted')->default(false);
            $table->boolean('available')->default(false);
            $table->boolean('complete')->default(false);
            $table->boolean('rate_limited')->default(false);
            $table->boolean('gracefully_stopped')->default(false);
            $table->unsignedInteger('expected_count')->nullable();
            $table->unsignedInteger('observed_count')->default(0);
            $table->unsignedInteger('active_count')->default(0);
            $table->unsignedInteger('known_count')->default(0);
            $table->unsignedInteger('added_count')->default(0);
            $table->unsignedInteger('removed_count')->default(0);
            $table->boolean('search_attempted')->default(false);
            $table->unsignedInteger('search_rounds')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['instagram_profile_id', 'list_type', 'scanned_at'], 'ig_list_scans_profile_type_seen_idx');
            $table->index(['tracked_person_id', 'scanned_at'], 'ig_list_scans_person_seen_idx');
        });

        Schema::create('instagram_profile_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_instagram_profile_id')->constrained('instagram_profiles')->cascadeOnDelete();
            $table->foreignId('related_instagram_profile_id')->constrained('instagram_profiles')->cascadeOnDelete();
            $table->foreignId('first_seen_scan_id')->nullable()->constrained('instagram_profile_list_scans')->nullOnDelete();
            $table->foreignId('last_seen_scan_id')->nullable()->constrained('instagram_profile_list_scans')->nullOnDelete();
            $table->foreignId('removed_scan_id')->nullable()->constrained('instagram_profile_list_scans')->nullOnDelete();
            $table->string('list_type', 20);
            $table->string('status', 30)->default('active');
            $table->string('display_name_snapshot')->nullable();
            $table->text('profile_url_snapshot')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['source_instagram_profile_id', 'related_instagram_profile_id', 'list_type'],
                'ig_profile_relationship_unique',
            );
            $table->index(['related_instagram_profile_id', 'list_type', 'deleted_at'], 'ig_profile_relationship_related_idx');
        });

        Schema::create('instagram_profile_list_scan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_scan_id')->constrained('instagram_profile_list_scans')->cascadeOnDelete();
            $table->foreignId('relationship_id')->nullable()->constrained('instagram_profile_relationships')->nullOnDelete();
            $table->foreignId('source_instagram_profile_id')->constrained('instagram_profiles')->cascadeOnDelete();
            $table->foreignId('related_instagram_profile_id')->constrained('instagram_profiles')->cascadeOnDelete();
            $table->string('list_type', 20);
            $table->string('item_status', 30)->default('observed');
            $table->string('username_snapshot');
            $table->string('display_name_snapshot')->nullable();
            $table->text('profile_url_snapshot')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['list_scan_id', 'item_status'], 'ig_list_scan_items_scan_status_idx');
            $table->index(['source_instagram_profile_id', 'list_type'], 'ig_list_scan_items_source_type_idx');
            $table->index(['related_instagram_profile_id', 'list_type'], 'ig_list_scan_items_related_type_idx');
        });

        $this->backfillExistingInstagramProfiles();
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_profile_list_scan_items');
        Schema::dropIfExists('instagram_profile_relationships');
        Schema::dropIfExists('instagram_profile_list_scans');
        Schema::dropIfExists('tracked_person_instagram_profile_links');

        Schema::table('tracked_person_instagram_inferred_connections', function (Blueprint $table) {
            $table->dropForeign('tpiic_source_ig_profile_fk');
            $table->dropForeign('tpiic_candidate_ig_profile_fk');
            $table->dropColumn(['source_public_instagram_profile_id', 'candidate_instagram_profile_id']);
        });

        Schema::table('tracked_person_public_profiles', function (Blueprint $table) {
            $table->dropForeign('tppp_ig_profile_fk');
            $table->dropColumn('instagram_profile_id');
        });

        Schema::table('tracked_person_instagram_snapshots', function (Blueprint $table) {
            $table->dropForeign('tpis_ig_profile_fk');
            $table->dropColumn('instagram_profile_id');
        });

        Schema::table('tracked_people', function (Blueprint $table) {
            $table->dropForeign('tp_current_ig_profile_fk');
            $table->dropColumn('current_instagram_profile_id');
        });

        Schema::dropIfExists('instagram_profiles');
    }

    private function backfillExistingInstagramProfiles(): void
    {
        $now = now();
        $normalizeUsername = static function (mixed $value): ?string {
            if (! is_scalar($value)) {
                return null;
            }

            $username = Str::lower(trim((string) $value));
            $username = ltrim($username, '@');
            $username = preg_replace('/^https?:\/\/(www\.)?instagram\.com\//i', '', $username) ?? $username;
            $username = trim($username, "/ \t\n\r\0\x0B");

            return $username !== '' ? $username : null;
        };

        $ensureProfile = static function (mixed $rawUsername, array $attributes = []) use ($normalizeUsername, $now): ?int {
            $username = $normalizeUsername($rawUsername);

            if ($username === null) {
                return null;
            }

            $existing = DB::table('instagram_profiles')->where('username', $username)->first();
            $payload = array_filter([
                'display_name' => $attributes['display_name'] ?? null,
                'full_name' => $attributes['full_name'] ?? null,
                'profile_image_url' => $attributes['profile_image_url'] ?? null,
                'profile_image_path' => $attributes['profile_image_path'] ?? null,
                'profile_image_hash' => $attributes['profile_image_hash'] ?? null,
                'followers_count' => $attributes['followers_count'] ?? null,
                'following_count' => $attributes['following_count'] ?? null,
                'posts_count' => $attributes['posts_count'] ?? null,
                'last_status_level' => $attributes['last_status_level'] ?? null,
                'last_status_message' => $attributes['last_status_message'] ?? null,
                'last_scanned_at' => $attributes['last_scanned_at'] ?? null,
                'updated_at' => $now,
                'deleted_at' => null,
            ], static fn ($value): bool => $value !== null);

            if ($existing) {
                if ($payload !== []) {
                    DB::table('instagram_profiles')->where('id', $existing->id)->update($payload);
                }

                return (int) $existing->id;
            }

            return (int) DB::table('instagram_profiles')->insertGetId([
                'username' => $username,
                'profile_url' => 'https://www.instagram.com/'.$username.'/',
                'created_at' => $now,
                'updated_at' => $now,
                ...$payload,
            ]);
        };

        DB::table('tracked_people')
            ->whereNotNull('instagram_username')
            ->orderBy('id')
            ->get()
            ->each(function ($person) use ($ensureProfile, $now): void {
                $profileId = $ensureProfile($person->instagram_username, [
                    'display_name' => trim(($person->first_name ?? '').' '.($person->last_name ?? '')) ?: ($person->alias ?? null),
                    'profile_image_path' => $person->instagram_profile_image_path ?: $person->profile_image_path,
                    'profile_image_hash' => $person->instagram_profile_image_hash ?: $person->profile_image_hash,
                    'followers_count' => $person->instagram_followers_count,
                    'following_count' => $person->instagram_following_count,
                    'posts_count' => $person->instagram_posts_count,
                    'last_status_level' => $person->last_instagram_status_level,
                    'last_status_message' => $person->last_instagram_status_message,
                    'last_scanned_at' => $person->last_instagram_analyzed_at,
                ]);

                if (! $profileId) {
                    return;
                }

                DB::table('tracked_people')->where('id', $person->id)->update([
                    'current_instagram_profile_id' => $profileId,
                    'updated_at' => $now,
                ]);

                DB::table('tracked_person_instagram_profile_links')->insert([
                    'tracked_person_id' => $person->id,
                    'instagram_profile_id' => $profileId,
                    'user_id' => $person->user_id,
                    'relation_type' => 'observed',
                    'is_current' => true,
                    'linked_at' => $person->created_at ?: $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        DB::table('tracked_person_instagram_snapshots')
            ->whereNotNull('instagram_username')
            ->orderBy('id')
            ->get()
            ->each(function ($snapshot) use ($ensureProfile, $now): void {
                $profileId = $ensureProfile($snapshot->instagram_username, [
                    'full_name' => $snapshot->full_name,
                    'profile_image_url' => $snapshot->profile_image_url,
                    'profile_image_path' => $snapshot->profile_image_path,
                    'profile_image_hash' => $snapshot->profile_image_hash,
                    'followers_count' => $snapshot->followers_count,
                    'following_count' => $snapshot->following_count,
                    'posts_count' => $snapshot->posts_count,
                    'last_status_level' => $snapshot->status_level,
                    'last_status_message' => $snapshot->status_message,
                    'last_scanned_at' => $snapshot->analyzed_at,
                ]);

                if ($profileId) {
                    DB::table('tracked_person_instagram_snapshots')->where('id', $snapshot->id)->update([
                        'instagram_profile_id' => $profileId,
                        'updated_at' => $now,
                    ]);
                }
            });

        DB::table('tracked_person_public_profiles')
            ->where('platform', 'instagram')
            ->whereNotNull('username')
            ->orderBy('id')
            ->get()
            ->each(function ($profile) use ($ensureProfile, $now): void {
                $profileId = $ensureProfile($profile->username, [
                    'display_name' => $profile->display_name,
                ]);

                if ($profileId) {
                    DB::table('tracked_person_public_profiles')->where('id', $profile->id)->update([
                        'instagram_profile_id' => $profileId,
                        'updated_at' => $now,
                    ]);
                }
            });

        DB::table('tracked_person_instagram_inferred_connections')
            ->orderBy('id')
            ->get()
            ->each(function ($connection) use ($ensureProfile, $now): void {
                $sourceProfileId = $ensureProfile($connection->source_public_username);
                $candidateProfileId = $ensureProfile($connection->candidate_username, [
                    'display_name' => $connection->candidate_display_name,
                ]);

                DB::table('tracked_person_instagram_inferred_connections')->where('id', $connection->id)->update([
                    'source_public_instagram_profile_id' => $sourceProfileId,
                    'candidate_instagram_profile_id' => $candidateProfileId,
                    'updated_at' => $now,
                ]);
            });
    }
};
