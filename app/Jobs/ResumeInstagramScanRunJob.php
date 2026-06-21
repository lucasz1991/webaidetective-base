<?php

namespace App\Jobs;

use App\Services\TrackedPeople\InstagramScanRunManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResumeInstagramScanRunJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $scanRunId,
    ) {}

    public function handle(InstagramScanRunManager $manager): void
    {
        $manager->resume($this->scanRunId);
    }

    public function uniqueId(): string
    {
        return (string) $this->scanRunId;
    }
}
