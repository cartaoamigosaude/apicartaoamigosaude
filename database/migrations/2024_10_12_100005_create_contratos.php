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
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->char('tipo', 1)->default('F');
            $table->foreignId('cliente_id')->constrained('clientes')->onDelete('cascade');
            $table->foreignId('plano_id')->constrained('planos')->onDelete('cascade');
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->default('2999-12-31');
            $table->foreignId('vendedor_id')->constrained('vendedores');
            $table->decimal('valor', 11, 2)->default(0.00);
            $table->foreignId('situacao_id')->constrained('situacoes');
            $table->timestamps();

            // Chaves únicas e índices
            $table->unique(['cliente_id', 'plano_id']);
            $table->index('vigencia_inicio');
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
        Schema::dropIfExists('contratos');
    }
};
