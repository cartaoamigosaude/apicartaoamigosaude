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
        Schema::table('users', function (Blueprint $table) {
            // Adiciona a coluna 'perfil', texto de 5 caracteres, com valor inicial 'user'
          // $table->string('perfil', 5)->default('user')->after('password');
            // Adiciona a coluna 'escopos', tipo JSON, com valor inicial vazio
            $table->json('escopos')->after('password');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            // Remove as colunas adicionadas
           // $table->dropColumn('perfil');
            $table->dropColumn('escopos');
        });
    }
};