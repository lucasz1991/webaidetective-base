<?php

namespace App\Console\Commands;

use App\Models\InstagramProfile;
use App\Services\Social\InstagramProfileImageStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CacheInstagramProfileImages extends Command
{
    protected $signature = 'instagram:cache-profile-images {--limit= : Maximale Anzahl Profile}';

    protected $description = 'Speichert vorhandene Instagram-Profilbild-URLs lokal im Public Storage.';

    public function handle(InstagramProfileImageStorage $imageStorage): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $processed = 0;
        $stored = 0;
        $skipped = 0;

        InstagramProfile::withTrashed()
            ->whereNotNull('profile_image_url')
            ->orderBy('id')
            ->chunkById(100, function ($profiles) use ($imageStorage, $limit, &$processed, &$stored, &$skipped): bool {
                foreach ($profiles as $profile) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    if (
                        filled($profile->profile_image_path)
                        && Storage::disk('public')->exists($profile->profile_image_path)
                    ) {
                        $skipped++;

                        continue;
                    }

                    $path = $imageStorage->storeFromUrl($profile, $profile->profile_image_url);

                    if ($path) {
                        $stored++;
                        $this->line('Gespeichert: @'.$profile->username.' -> '.$path);
                    } else {
                        $this->warn('Nicht gespeichert: @'.$profile->username);
                    }
                }

                return true;
            });

        $this->info(sprintf(
            'Fertig. Geprueft: %d, lokal gespeichert: %d, bereits vorhanden: %d.',
            $processed,
            $stored,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
