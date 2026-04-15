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
        Schema::create('planos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->decimal('preco', 11, 2)->default(0.00);
            $table->foreignId('periodicidade_id')->constrained('periodicidades');
            $table->integer('parcelas')->default(0);
            $table->boolean('ativo')->default(false);
            $table->timestamps();

            // Chaves únicas e índices
            $table->unique('nome');
            $table->index('periodicidade_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('planos');
    }
};
