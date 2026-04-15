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
        Schema::create('beneficiarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->onDelete('cascade');
            $table->foreignId('cliente_id')->constrained('clientes')->onDelete('cascade');
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->default('2999-12-31');
            $table->char('tipo', 1)->default('B');
            $table->boolean('ativo')->default(false);
			$table->integer('parent_id')->default(0);
            $table->timestamps();

            // Chaves únicas e índices
            $table->unique(['contrato_id', 'cliente_id']);
            $table->index('parent_id');
            $table->index('ativo');
            $table->index('tipo');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('beneficiarios');
    }
};
