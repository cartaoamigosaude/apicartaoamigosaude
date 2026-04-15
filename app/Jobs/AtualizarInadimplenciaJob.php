<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Models\Contrato;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Job: Processo 2 - Atualização Diária de Inadimplência
 * 
 * Responsável por:
 * 1. Atualizar o contador `dias_inadimplente`.
 * 2. Marcar contratos para exclusão (`elegivel_exclusao`).
 * 3. Marcar contratos para reinclusão (`elegivel_inclusao`).
 * 
 * Execução: Agendado diariamente, às 06:00.
 */
class AtualizarInadimplenciaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $tries 			= 1;
    public $timeout 		= 86400;
    public $failOnTimeout 	= false;

    public function __construct()
    {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("AtualizarInadimplenciaJob iniciado");

        try {
            $hoje 												= Carbon::now();
            $limiteExclusao 									= 10; // Regra: 10 dias de inadimplência

            DB::transaction(function () use ($hoje, $limiteExclusao) {
                // 1. Processar Contratos Inadimplentes
                $inadimplentes = Contrato::sabemi() // Filtra apenas contratos Sabemi
                    ->where('situacao_pagto', 'I')
                    ->whereIn('status', ['active', 'waitingPayment']) // Contratos ativos ou aguardando pagamento
                    ->get();

                Log::info("AtualizarInadimplenciaJob - Contratos inadimplentes encontrados", [
                    'count' => $inadimplentes->count()
                ]);

                foreach ($inadimplentes as $contrato) {
                    // Se for a primeira vez que está inadimplente, registra a data
                    if (is_null($contrato->data_inicio_inadimplencia)) {
                        $contrato->data_inicio_inadimplencia 	= $hoje;
                        $contrato->dias_inadimplente 			= 1;
                    } else {
                        // Calcula os dias de inadimplência
						$dataInicio 							= Carbon::parse($contrato->data_inicio_inadimplencia);
						$contrato->dias_inadimplente 			= $dataInicio->diffInDays($hoje);
                       // $contrato->dias_inadimplente 			= $contrato->data_inicio_inadimplencia->diffInDays($hoje);
                    }

                    // Marca para exclusão se atingiu o limite
                    if ($contrato->dias_inadimplente >= $limiteExclusao) {
                        $contrato->elegivel_exclusao 			= true;
                        $contrato->motivo_cancelamento 			= 'INADIMPLENCIA_10_DIAS';
                    }

                    // Limpa a flag de reinclusão se voltou a ser inadimplente
                    $contrato->elegivel_inclusao 				= false;
                    $contrato->data_regularizacao_inadimplencia = null;

                    $contrato->save();
                }

                // 2. Processar Contratos Regularizados (Voltaram a ser Adimplentes)
                $regularizados = Contrato::sabemi() // Filtra apenas contratos Sabemi
                    ->where('situacao_pagto', 'A')
                    ->where('dias_inadimplente', '>', 0) // Estavam inadimplentes
                    ->get();

                Log::info("AtualizarInadimplenciaJob - Contratos regularizados encontrados", [
                    'count' => $regularizados->count()
                ]);

                foreach ($regularizados as $contrato) {
                    // Se o contrato estava marcado para exclusão, ele agora é elegível para reinclusão
                    if ($contrato->elegivel_exclusao) {
                        $contrato->elegivel_inclusao 			= true;
                    }

                    // Limpa os campos de inadimplência
                    $contrato->dias_inadimplente 				= 0;
                    $contrato->data_regularizacao_inadimplencia = $hoje;
                    $contrato->data_inicio_inadimplencia 		= null;
                    $contrato->elegivel_exclusao 				= false; // Não precisa mais ser excluído
                    $contrato->save();
                }
            });

            Log::info("AtualizarInadimplenciaJob concluído com sucesso");
        } catch (Throwable $exception) {
            Log::error("AtualizarInadimplenciaJob - Erro durante execução", [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception)
    {
        Log::error("AtualizarInadimplenciaJob falhou", [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
