<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('web_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title')->unique();
            $table->string('slug')->unique();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('robots_meta')->nullable()->default('index, follow');
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->text('custom_css')->nullable();
            $table->text('custom_js')->nullable();
            $table->json('custom_meta')->nullable();
            $table->text('icon')->nullable();
            $table->string('header_image')->nullable();
            $table->boolean('is_fixed')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_from')->nullable();
            $table->timestamp('published_until')->nullable();
            $table->foreignId('last_editor')->nullable()->constrained('users')->nullOnDelete();
            $table->string('language', 5)->default(config('app.locale')); // Sprache der Seite
            $table->foreignId('pagebuilder_project')->nullable()->constrained('pagebuilder_projects')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('web_pages');
    }
};
