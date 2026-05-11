<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_person_known_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_person_id')->constrained('tracked_people')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('value');
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tracked_person_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_person_known_facts');
    }
};
