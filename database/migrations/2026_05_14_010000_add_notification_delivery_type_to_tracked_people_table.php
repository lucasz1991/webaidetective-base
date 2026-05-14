<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            if (! Schema::hasColumn('tracked_people', 'notification_delivery_type')) {
                $table->string('notification_delivery_type', 20)
                    ->default('both')
                    ->after('snapchat_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracked_people', function (Blueprint $table) {
            if (Schema::hasColumn('tracked_people', 'notification_delivery_type')) {
                $table->dropColumn('notification_delivery_type');
            }
        });
    }
};
