<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('scraper_profiles')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('scraper_profiles', 'scrape_blocked_at')) {
                $table->timestamp('scrape_blocked_at')->nullable()->after('cookies_synced_at');
            }

            if (! Schema::hasColumn('scraper_profiles', 'scrape_blocked_until')) {
                $table->timestamp('scrape_blocked_until')->nullable()->after('scrape_blocked_at');
            }

            if (! Schema::hasColumn('scraper_profiles', 'scrape_blocked_reason')) {
                $table->string('scrape_blocked_reason')->nullable()->after('scrape_blocked_until');
            }
        });

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            $table->index(['platform', 'is_active', 'scrape_blocked_until'], 'scraper_profiles_scrape_block_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scraper_profiles')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            $table->dropIndex('scraper_profiles_scrape_block_idx');

            foreach (['scrape_blocked_reason', 'scrape_blocked_until', 'scrape_blocked_at'] as $column) {
                if (Schema::hasColumn('scraper_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
