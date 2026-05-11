<?php

namespace App\Console\Commands;

use App\Jobs\MonitorTrackedPersonInstagram;
use App\Models\TrackedPerson;
use Illuminate\Console\Command;

class RunTrackedPeopleMonitoring extends Command
{
    protected $signature = 'tracked-people:run-monitoring {--force : Ignoriert die Mindestwartezeit seit der letzten Analyse} {--older-than=30 : Analysiert nur Profile, deren letzte Analyse aelter als X Minuten ist}';

    protected $description = 'Plant automatische Instagram-Analysen fuer Personen mit aktivierter Dauerbeobachtung ein.';

    public function handle(): int
    {
        $olderThanMinutes = max((int) $this->option('older-than'), 0);

        $query = TrackedPerson::query()
            ->where('monitoring_enabled', true)
            ->whereNotNull('instagram_username');

        if (! $this->option('force') && $olderThanMinutes > 0) {
            $query->where(function ($builder) use ($olderThanMinutes) {
                $builder
                    ->whereNull('last_instagram_analyzed_at')
                    ->orWhere('last_instagram_analyzed_at', '<=', now()->subMinutes($olderThanMinutes));
            });
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($trackedPeople) use (&$count) {
            foreach ($trackedPeople as $trackedPerson) {
                MonitorTrackedPersonInstagram::dispatch($trackedPerson->id);
                $count++;
            }
        });

        $this->info($count.' Beobachtungs-Job(s) wurden in die Queue gestellt.');

        return self::SUCCESS;
    }
}
