<?php

namespace App\Console\Commands;

use App\Jobs\MonitorTrackedPersonInstagram;
use App\Models\TrackedPerson;
use Illuminate\Console\Command;

class RunTrackedPeopleMonitoring extends Command
{
    protected $signature = 'tracked-people:run-monitoring';

    protected $description = 'Plant automatische Instagram-Analysen fuer Personen mit aktivierter Dauerbeobachtung ein.';

    public function handle(): int
    {
        $query = TrackedPerson::query()
            ->where('monitoring_enabled', true)
            ->whereNotNull('instagram_username');

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
