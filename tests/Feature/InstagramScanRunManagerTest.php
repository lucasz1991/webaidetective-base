<?php

namespace Tests\Feature;

use App\Jobs\ResumeInstagramScanRunJob;
use App\Models\InstagramScanRun;
use App\Services\TrackedPeople\InstagramProfileScanService;
use App\Services\TrackedPeople\InstagramScanRunManager;
use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use App\Services\TrackedPeople\TrackedPersonInstagramPostScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramProfileListScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramPublicProfileScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use App\Services\TrackedPeople\TrackedPersonInstagramSuggestionScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramWorkflowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class InstagramScanRunManagerTest extends TestCase
{
    private string $originalDatabaseDefault;

    private ?string $originalSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabaseDefault = (string) config('database.default');
        $this->originalSqliteDatabase = config('database.connections.sqlite.database');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);

        DB::purge($this->originalDatabaseDefault);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createInstagramScanRunsTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('instagram_scan_runs');
        DB::disconnect('sqlite');

        config([
            'database.default' => $this->originalDatabaseDefault,
            'database.connections.sqlite.database' => $this->originalSqliteDatabase,
        ]);

        parent::tearDown();
    }

    public function test_due_retries_are_reserved_before_dispatching_resume_jobs(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);

        $run = InstagramScanRun::create([
            'scan_type' => 'mini',
            'label' => 'Instagram-Mini-Scan',
            'status' => InstagramScanRun::STATUS_RETRY_SCHEDULED,
            'attempt' => 1,
            'started_at' => now('UTC')->subMinutes(10),
            'finished_at' => now('UTC')->subMinutes(5),
            'last_heartbeat_at' => now('UTC')->subMinutes(5),
            'next_retry_at' => now('UTC')->subMinute(),
            'node_processes' => [],
            'resume_payload' => [],
        ]);

        $manager = $this->manager();

        $this->assertSame(1, $manager->dispatchDueRetries());
        Queue::assertPushed(ResumeInstagramScanRunJob::class, 1);

        $run->refresh();
        $this->assertSame(InstagramScanRun::STATUS_QUEUED, $run->status);
        $this->assertNull($run->finished_at);
        $this->assertNull($run->next_retry_at);
        $this->assertNotNull($run->last_heartbeat_at);

        $this->assertSame(0, $manager->dispatchDueRetries());
        Queue::assertPushed(ResumeInstagramScanRunJob::class, 1);
    }

    private function manager(): InstagramScanRunManager
    {
        return new InstagramScanRunManager(
            Mockery::mock(TrackedPersonInstagramScanCoordinator::class),
            Mockery::mock(TrackedPersonInstagramWorkflowService::class),
            Mockery::mock(TrackedPersonInstagramAnalysisService::class),
            Mockery::mock(TrackedPersonInstagramPublicProfileScanService::class),
            Mockery::mock(TrackedPersonInstagramSuggestionScanService::class),
            Mockery::mock(TrackedPersonInstagramProfileListScanService::class),
            Mockery::mock(TrackedPersonInstagramPostScanService::class),
            Mockery::mock(InstagramProfileScanService::class),
        );
    }

    private function createInstagramScanRunsTable(): void
    {
        Schema::create('instagram_scan_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tracked_person_id')->nullable();
            $table->unsignedBigInteger('instagram_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->bigInteger('scan_context_id')->nullable();
            $table->string('scan_context_key', 120)->nullable();
            $table->unsignedInteger('generation')->nullable();
            $table->string('scan_type', 80);
            $table->string('label', 160);
            $table->string('target_username', 80)->nullable();
            $table->string('status', 40)->default('running');
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_process_output_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('node_processes')->nullable();
            $table->json('resume_payload')->nullable();
            $table->timestamps();
        });
    }
}
