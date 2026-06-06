<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('instagram_posts')
            ->whereNotNull('last_seen_scan_id')
            ->orderBy('id')
            ->chunkById(250, function ($posts): void {
                foreach ($posts as $post) {
                    DB::table('instagram_post_metrics')->updateOrInsert(
                        [
                            'instagram_post_id' => $post->id,
                            'instagram_post_scan_id' => $post->last_seen_scan_id,
                        ],
                        [
                            'likes_count' => $post->likes_count,
                            'comments_count' => $post->comments_count,
                            'observed_at' => $post->last_scanned_at ?: ($post->updated_at ?: now()),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        // Historical metric rows may include newer scans and are intentionally retained.
    }
};
