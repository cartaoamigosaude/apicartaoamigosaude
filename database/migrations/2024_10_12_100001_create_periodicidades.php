<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('periodicidades', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->boolean('ativo')->default(false);
            $table->timestamps();

            $table->unique('nome');
			$table->index('ativo');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('periodicidades');
    }
};
