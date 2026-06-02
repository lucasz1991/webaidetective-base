<?php

namespace App\Console\Commands;

use App\Models\TrackedPersonInstagramSnapshot;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use Illuminate\Console\Command;

class BackfillInstagramProfileGraph extends Command
{
    protected $signature = 'instagram:backfill-profile-graph
        {--tracked-person-id= : Nur Snapshots einer beobachteten Person importieren}
        {--limit= : Maximale Anzahl Snapshots verarbeiten}';

    protected $description = 'Importiert vorhandene Instagram-Snapshot-Listen in die relationalen Profil- und Beziehungstabellen.';

    public function handle(InstagramProfileRelationshipStore $store): int
    {
        $query = TrackedPersonInstagramSnapshot::query()
            ->with('trackedPerson')
            ->whereNotNull('instagram_username')
            ->whereNotNull('raw_payload')
            ->orderBy('analyzed_at')
            ->orderBy('id');

        $trackedPersonId = $this->option('tracked-person-id');

        if ($trackedPersonId !== null && $trackedPersonId !== '') {
            $query->where('tracked_person_id', (int) $trackedPersonId);
        }

        $limit = $this->option('limit');

        if ($limit !== null && $limit !== '') {
            $query->limit(max(1, (int) $limit));
        }

        $snapshots = $query->get();

        if ($snapshots->isEmpty()) {
            $this->info('Keine passenden Instagram-Snapshots gefunden.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($snapshots->count());
        $bar->start();

        $importedListScans = 0;

        foreach ($snapshots as $snapshot) {
            $importedListScans += $store->backfillSnapshotRelationshipLists($snapshot);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info($importedListScans.' Listen-Scans wurden in die neuen Beziehungstabellen importiert.');

        return self::SUCCESS;
    }
}
