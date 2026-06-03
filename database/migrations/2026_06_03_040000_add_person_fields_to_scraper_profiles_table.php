<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Personendaten bleiben in der lokalen Factory und werden nicht in der Base gespeichert.
    }

    public function down(): void
    {
        //
    }
};
