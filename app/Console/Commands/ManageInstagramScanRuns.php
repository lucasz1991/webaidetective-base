<?php

namespace App\Console\Commands;

use App\Services\TrackedPeople\InstagramScanRunManager;
use Illuminate\Console\Command;

class ManageInstagramScanRuns extends Command
{
    protected $signature = 'instagram:scan-manager
        {--once : Nur einen Watchdog-Durchlauf ausfuehren}
        {--sleep=30 : Sekunden zwischen den Durchlaeufen im Dauerbetrieb}
        {--stale-after=300 : Sekunden ohne lebendige PID oder Heartbeat bis zum Retry}
        {--limit=50 : Maximale Anzahl verwalteter Runs pro Durchlauf}';

    protected $description = 'Ueberwacht Instagram-Scan-Runs, erkennt tote Node-PIDs und startet faellige Wiederaufnahmen.';

    public function handle(InstagramScanRunManager $manager): int
    {
        $sleepSeconds = max(5, (int) $this->option('sleep'));
        $staleAfterSeconds = max(60, (int) $this->option('stale-after'));
        $limit = max(1, (int) $this->option('limit'));

        do {
            $summary = $manager->manageOnce($staleAfterSeconds, $limit);

            if (($summary['scheduled'] ?? 0) > 0 || ($summary['dispatched'] ?? 0) > 0 || $this->option('once')) {
                $this->info(
                    'Instagram-Scan-Manager: '
                    .($summary['scheduled'] ?? 0).' Retry(s) geplant, '
                    .($summary['dispatched'] ?? 0).' faellige Resume-Job(s) gestartet.'
                );
            }

            if ($this->option('once')) {
                break;
            }

            sleep($sleepSeconds);
        } while (true);

        return self::SUCCESS;
    }
}
