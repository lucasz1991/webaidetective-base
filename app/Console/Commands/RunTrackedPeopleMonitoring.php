<?php

namespace App\Console\Commands;

use App\Jobs\MonitorTrackedPersonInstagram;
use App\Models\TrackedPerson;
use Illuminate\Console\Command;

class RunTrackedPeopleMonitoring extends Command
{
    protected $signature = 'tracked-people:run-monitoring {--force : Alle aktivierten Personen unabhaengig vom Intervall einreihen}';

    protected $description = 'Plant automatische Instagram-Analysen fuer Personen mit aktivierter Dauerbeobachtung ein.';

    public function handle(): int
    {
        $query = TrackedPerson::query()
            ->where('monitoring_enabled', true)
            ->whereNotNull('instagram_username');

        $count = 0;
        $skipped = 0;
        $force = (bool) $this->option('force');

        $query->orderBy('id')->chunkById(100, function ($trackedPeople) use (&$count, &$skipped, $force) {
            foreach ($trackedPeople as $trackedPerson) {
                $intervalMinutes = max(1, (int) ($trackedPerson->monitoring_interval_minutes ?: 60));
                $nextRunAt = $trackedPerson->last_instagram_analyzed_at?->copy()->addMinutes($intervalMinutes);

                if (! $force && $nextRunAt?->isFuture()) {
                    $skipped++;

                    continue;
                }

                MonitorTrackedPersonInstagram::dispatch($trackedPerson->id);
                $count++;
            }
        });

        $this->info($count.' Beobachtungs-Job(s) wurden in die Queue gestellt; '.$skipped.' noch nicht faellig.');

        return self::SUCCESS;
    }
}
