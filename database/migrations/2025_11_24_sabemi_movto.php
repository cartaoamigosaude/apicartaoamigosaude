<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration Final: Implementa o modelo de dados ajustado para controle Sabemi.
 * 
 * - Criação das tabelas de log: `sabemi_endossos` e `sabemi_movimentacoes`.
 * 
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        
        // ============================================================
        // 3. CRIAR TABELA DE LOG DE MOVIMENTAÇÕES SABEMI
        // ============================================================
        Schema::create('sabemi_movimentacoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('beneficiario_id'); // Chave para o beneficiário
            $table->string('codigo_endosso', 50); // Número do endosso Sabemi
            $table->char('tipo_movimentacao', 1); // I = Inclusão, E = Exclusão, A = Alteração
            $table->string('status_envio', 20)->default('PENDENTE'); // PENDENTE, ENVIADO, ERRO
            $table->text('payload_enviado')->nullable(); // JSON do payload enviado
            $table->text('resposta_sabemi')->nullable(); // JSON da resposta da Sabemi
            $table->text('erro')->nullable(); // Mensagem de erro (se houver)
            $table->timestamp('data_envio')->nullable();
            $table->timestamps();
            
            // Índices
            $table->index('beneficiario_id', 'idx_sabemi_mov_beneficiario');
            $table->index('codigo_endosso', 'idx_sabemi_mov_endosso');
            $table->index('tipo_movimentacao', 'idx_sabemi_mov_tipo');
            $table->index('status_envio', 'idx_sabemi_mov_status');
            
            // Foreign key
           // $table->foreign('beneficiario_id')->references('id')->on('beneficiarios')->onDelete('cascade');
            
            $table->comment('Log de todas as movimentações enviadas para Sabemi');
        });
        
        // ============================================================
        // 4. CRIAR TABELA DE ENDOSSOS
        // ============================================================
        Schema::create('sabemi_endossos', function (Blueprint $table) {
            $table->id();
            $table->string('numero_endosso', 50)->unique(); // Número do endosso retornado pela Sabemi
            $table->string('codigo_apolice', 50); // Código da apólice (ex: 01.82.004138)
            $table->string('codigo_grupo', 50); // Código do grupo
            $table->string('status_endosso', 20)->default('ABERTO'); // ABERTO, PROCESSANDO, FECHADO, ERRO
            $table->timestamp('data_abertura')->nullable();
            $table->timestamp('data_fechamento')->nullable();
            $table->integer('total_inclusoes')->default(0);
            $table->integer('total_exclusoes')->default(0);
            $table->integer('total_alteracoes')->default(0);
            $table->integer('total_sucesso')->default(0);
            $table->integer('total_erro')->default(0);
            $table->text('erro_abertura')->nullable(); // Erro ao abrir endosso
            $table->text('erro_fechamento')->nullable(); // Erro ao fechar endosso
            $table->timestamps();
            
            // Índices
            $table->index('numero_endosso', 'idx_sabemi_endosso_numero');
            $table->index('status_endosso', 'idx_sabemi_endosso_status');
            
            $table->comment('Registro de endossos abertos e fechados na Sabemi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sabemi_endossos');
        Schema::dropIfExists('sabemi_movimentacoes');
    }
};
