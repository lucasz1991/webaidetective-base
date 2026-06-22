<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_scan_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')->nullable()->constrained('tracked_people', indexName: 'ig_scan_runs_person_fk')->nullOnDelete();
            $table->foreignId('instagram_profile_id')->nullable()->constrained('instagram_profiles', indexName: 'ig_scan_runs_profile_fk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(indexName: 'ig_scan_runs_user_fk')->nullOnDelete();
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

            $table->index(['status', 'next_retry_at'], 'ig_scan_runs_status_retry_idx');
            $table->index(['scan_context_key', 'status'], 'ig_scan_runs_context_status_idx');
            $table->index(['tracked_person_id', 'status'], 'ig_scan_runs_person_status_idx');
            $table->index(['instagram_profile_id', 'status'], 'ig_scan_runs_profile_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_scan_runs');
    }
};
