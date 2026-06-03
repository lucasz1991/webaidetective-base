<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scraper_profiles')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            foreach ($this->personColumns() as $column) {
                if (Schema::hasColumn('scraper_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scraper_profiles')) {
            return;
        }

        Schema::table('scraper_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('scraper_profiles', 'person_first_name')) {
                $table->string('person_first_name')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_last_name')) {
                $table->string('person_last_name')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_alias')) {
                $table->string('person_alias')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_date_of_birth')) {
                $table->date('person_date_of_birth')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_gender')) {
                $table->string('person_gender')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_email')) {
                $table->string('person_email')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_phone')) {
                $table->string('person_phone')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_address_line1')) {
                $table->string('person_address_line1')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_address_line2')) {
                $table->string('person_address_line2')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_postal_code')) {
                $table->string('person_postal_code')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_state')) {
                $table->string('person_state')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_country')) {
                $table->string('person_country')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_city')) {
                $table->string('person_city')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_timezone')) {
                $table->string('person_timezone')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'person_notes')) {
                $table->text('person_notes')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'avatar_path')) {
                $table->string('avatar_path')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'identity_profile')) {
                $table->json('identity_profile')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'bot_profile')) {
                $table->json('bot_profile')->nullable();
            }

            if (! Schema::hasColumn('scraper_profiles', 'bot_status')) {
                $table->string('bot_status')->default('manual');
            }
        });
    }

    private function personColumns(): array
    {
        return [
            'bot_status',
            'bot_profile',
            'identity_profile',
            'avatar_path',
            'person_notes',
            'person_timezone',
            'person_city',
            'person_country',
            'person_state',
            'person_postal_code',
            'person_address_line2',
            'person_address_line1',
            'person_phone',
            'person_email',
            'person_gender',
            'person_date_of_birth',
            'person_alias',
            'person_last_name',
            'person_first_name',
        ];
    }
};
