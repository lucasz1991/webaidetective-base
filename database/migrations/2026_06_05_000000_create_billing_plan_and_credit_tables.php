<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('max_profiles');
            $table->unsignedInteger('max_users');
            $table->unsignedBigInteger('monthly_credits');
            $table->unsignedInteger('max_history_days');
            $table->unsignedInteger('scan_frequency_minutes');
            $table->unsignedInteger('priority_level')->default(0);
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('available_credits')->default(0);
            $table->unsignedBigInteger('reserved_credits')->default(0);
            $table->unsignedBigInteger('used_credits')->default(0);
            $table->unsignedBigInteger('bonus_credits')->default(0);
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('type');
            $table->string('description')->nullable();
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        $this->seedDefaultPlans();
        $this->seedDefaultCreditSettings();
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->where('type', 'billing')
                ->whereIn('key', ['credit_costs', 'credit_packages'])
                ->delete();
        }

        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_wallets');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }

    private function seedDefaultPlans(): void
    {
        $now = now();

        DB::table('plans')->insert([
            [
                'name' => 'Basic',
                'max_profiles' => 10,
                'max_users' => 1,
                'monthly_credits' => 5000,
                'max_history_days' => 30,
                'scan_frequency_minutes' => 60,
                'priority_level' => 10,
                'features' => json_encode([
                    '10 ueberwachte Profile',
                    '5.000 Credits pro Monat',
                    '30 Tage Historie',
                    'Basis-Benachrichtigungen',
                    'Profil-, Bio- und Beitragsaenderungen',
                    '1 Benutzer',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Pro',
                'max_profiles' => 100,
                'max_users' => 3,
                'monthly_credits' => 50000,
                'max_history_days' => 180,
                'scan_frequency_minutes' => 15,
                'priority_level' => 50,
                'features' => json_encode([
                    '100 ueberwachte Profile',
                    '50.000 Credits pro Monat',
                    '180 Tage Historie',
                    'Erweiterte Analysen',
                    'Netzwerkanalysen',
                    'Exportfunktionen',
                    '3 Benutzer',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Platin',
                'max_profiles' => 1000,
                'max_users' => 10,
                'monthly_credits' => 500000,
                'max_history_days' => 365,
                'scan_frequency_minutes' => 5,
                'priority_level' => 100,
                'features' => json_encode([
                    '1.000 ueberwachte Profile',
                    '500.000 Credits pro Monat',
                    '365 Tage Historie',
                    'Alle Analysefunktionen',
                    'Teamverwaltung',
                    'API-Zugriff',
                    'Priorisierte Worker',
                    'Unbegrenzte Exporte',
                    '10 Benutzer',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function seedDefaultCreditSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();

        DB::table('settings')->insert([
            [
                'type' => 'billing',
                'key' => 'credit_costs',
                'value' => json_encode([
                    'scan_base_credit' => 1,
                    'scan_credit_per_minute' => 2,
                    'scan_minimum_credits' => 1,
                    'scan_max_billable_minutes' => 30,
                    'profile_scan' => 1,
                    'profile_image_scan' => 1,
                    'post_scan' => 3,
                    'new_posts_archive' => 5,
                    'media_download_per_file' => 5,
                    'ai_analysis_multiplier' => 1000,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'type' => 'billing',
                'key' => 'credit_packages',
                'value' => json_encode([
                    ['name' => 'Small', 'credits' => 10000],
                    ['name' => 'Medium', 'credits' => 50000],
                    ['name' => 'Large', 'credits' => 250000],
                    ['name' => 'Ultra', 'credits' => 1000000],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
};
