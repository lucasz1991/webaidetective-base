<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('person');
    }

    public function down(): void
    {
        // Legacy table intentionally not recreated.
    }
};
