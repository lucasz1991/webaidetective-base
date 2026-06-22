<?php

namespace App\Jobs;

use App\Services\TrackedPeople\InstagramScanRunManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ResumeInstagramScanRunJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const LOCK_SECONDS = 21600;

    public int $timeout = 0;

    public int $tries = 1;

    public int $uniqueFor = self::LOCK_SECONDS;

    public function __construct(
        public readonly int $scanRunId,
    ) {}

    public function handle(InstagramScanRunManager $manager): void
    {
        $manager->resume($this->scanRunId);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter(self::LOCK_SECONDS)
                ->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->scanRunId;
    }
}
