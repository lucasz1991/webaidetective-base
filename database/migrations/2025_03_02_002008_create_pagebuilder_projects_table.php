<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('pagebuilder_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Eindeutiger Name für das Projekt
            $table->json('data'); // Hier wird das Projekt als JSON gespeichert
            $table->longText('html')->nullable();
            $table->longText('cleaned_html')->nullable();
            $table->longText('js')->nullable();
            $table->longText('css')->nullable();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->onDelete('set null'); // Letzter Bearbeiter
            $table->json('page')->nullable(); // JSON-Feld für mehrere Positionen
            $table->json('position')->nullable(); // JSON-Feld für mehrere Positionen
            $table->string('lang', 10)->nullable(); // Sprache (Standard: Deutsch)
            $table->boolean('lock')->default(false); // Falls das Modul gesperrt ist
            $table->timestamp('published_from')->nullable(); // Veröffentlichungsstart
            $table->timestamp('published_until')->nullable(); // Veröffentlichungsende
            $table->integer('order_id')->default(0); // Sortierreihenfolge
            $table->integer('status')->default(0); // Status des Moduls (0 = Entwurf, 1 = Veröffentlicht, 2 = Archiviert, 3 = FixedPAGE)
            $table->string('type')->default('module'); // Typ des Moduls (page, module, mail)
            $table->timestamps();
            $table->softDeletes(); // SoftDeletes aktivieren
        });
    }

    public function down()
    {
        Schema::dropIfExists('pagebuilder_projects');
    }
};