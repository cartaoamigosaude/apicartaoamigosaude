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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->char('tipo', 1)->default('F');
            $table->string('cpfcnpj', 20);
            $table->string('nome', 100);
            $table->string('telefone', 15);
            $table->string('email', 200);
            $table->date('data_nascimento');
            $table->char('sexo', 1)->default('M');
            $table->char('cep', 8);
            $table->string('logradouro', 100);
            $table->string('numero', 20);
            $table->string('complemento', 100)->nullable();
            $table->string('bairro', 100);
            $table->string('cidade', 100);
            $table->string('estado', 2);
            $table->boolean('ativo')->default(false);
            $table->text('observacao');
            $table->timestamps();
            $table->unique('cpfcnpj');
		    $table->index('nome');
            $table->index('tipo');
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
        Schema::dropIfExists('clientes');
    }
};

