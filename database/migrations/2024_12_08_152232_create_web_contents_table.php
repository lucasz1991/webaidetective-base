<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('web_contents', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // Eindeutiger Schlüssel für Inhalte (z. B. 'home_welcome_text')
            $table->text('value'); // Inhalt (z. B. Text, HTML, JSON)
            $table->string('type')->default('text'); // Typ des Inhalts (z. B. 'text', 'html', 'json', 'markdown')
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_contents');
    }
};
