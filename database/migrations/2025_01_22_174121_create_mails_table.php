<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMailsTable extends Migration
{
    public function up()
    {
        Schema::create('mails', function (Blueprint $table) {
            $table->id();
            $table->boolean('status')->default(false); 
            $table->json('content'); 
            $table->json('recipients'); 
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mails');
    }
}
