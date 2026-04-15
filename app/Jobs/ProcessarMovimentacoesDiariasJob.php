<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\SabemiService;
use App\Models\Beneficiario;
use App\Models\Contrato;
use App\Models\SabemiEndosso;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Job: Processo 3 - Processamento Diário de Movimentações
 * 
 * Responsável por:
 * 1. Identificar o endosso aberto.
 * 2. Coletar todas as movimentações pendentes (I, E, A).
 * 3. Enviar para a Sabemi.
 * 4. Atualizar o status de integração na tabela `beneficiarios`.
 * 
 * Execução: Agendado diariamente, às 23:00.
 */
class ProcessarMovimentacoesDiariasJob implements ShouldQueue
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

    protected $isDryRun 	= false;

    public function __construct(bool $isDryRun = false)
    {
        $this->isDryRun 	= $isDryRun;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("ProcessarMovimentacoesDiariasJob iniciado", [
            'isDryRun' => $this->isDryRun
        ]);

        try {
            $sabemiService = app(SabemiService::class);

            // 1. Encontrar o endosso aberto
            $endosso = SabemiEndosso::where('status_endosso', 'ABERTO')->first();

            if (!$endosso) {
                Log::error("ProcessarMovimentacoesDiariasJob - Nenhum endosso aberto encontrado");
                return;
            }

            Log::info("ProcessarMovimentacoesDiariasJob - Endosso aberto encontrado", [
                'numero_endosso' => $endosso->numero_endosso
            ]);

            // 2. Coletar todas as movimentações pendentes
            $movimentacoes = $this->coletarMovimentacoesPendentes($sabemiService);

            if ($movimentacoes->isEmpty()) {
                Log::warning("ProcessarMovimentacoesDiariasJob - Nenhuma movimentação pendente encontrada");
                return;
            }

            Log::info("ProcessarMovimentacoesDiariasJob - Total de movimentações a enviar", [
                'count' => $movimentacoes->count()
            ]);

            if ($this->isDryRun) {
                Log::warning("ProcessarMovimentacoesDiariasJob - DRY-RUN: Movimentações seriam enviadas", [
                    'numero_endosso' => $endosso->numero_endosso,
                    'count' => $movimentacoes->count()
                ]);
                return;
            }

            // 3. Enviar para a Sabemi (em lotes, se necessário)
            $payloads = $movimentacoes->map(function ($item) {
                return $item['payload'];
            })->toArray();

            $envioResult = $sabemiService->enviarMovimentacao($endosso->numero_endosso, $payloads);

            // 4. Atualizar o status de integração
            $this->atualizarStatusIntegracao($movimentacoes, $envioResult, $endosso);

            Log::info("ProcessarMovimentacoesDiariasJob concluído com sucesso");
        } catch (Throwable $exception) {
            Log::error("ProcessarMovimentacoesDiariasJob - Erro durante execução", [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            throw $exception;
        }
    }

    protected function coletarMovimentacoesPendentes(SabemiService $sabemiService)
    {
        $movimentacoes = collect();
        $hoje = Carbon::now();

        // ============================================================
        // A. INCLUSÕES (I)
        // ============================================================
        // Critério: Contratos Sabemi Ativos e com 1ª parcela paga
        $inclusoes = Beneficiario::where('status_envio_sabemi', 'PENDENTE')
            ->whereHas('contrato', function ($query) {
                $query->sabemi()->ativoSabemi();
            })
            ->get();

        foreach ($inclusoes as $beneficiario) {
            // Usa a data de adesão ou hoje caso nula
            $dataInicio 				= $beneficiario->contrato->data_adesao ? $beneficiario->contrato->data_adesao->format('Y-m-d') : $hoje->format('Y-m-d');
            $payload 					= $sabemiService->prepararPayloadBeneficiario($beneficiario, 'I', $dataInicio);
            $movimentacoes->push(['beneficiario' => $beneficiario, 'tipo' => 'I', 'payload' => $payload]);
        }

        Log::info("ProcessarMovimentacoesDiariasJob - Inclusões coletadas", [
            'count' => $inclusoes->count()
        ]);

        // ============================================================
        // B. EXCLUSÕES (E)
        // ============================================================
        // Critério 1: Contratos Cancelados (closed, canceled, etc)
        $exclusoesAtivas = Beneficiario::where('status_envio_sabemi', 'ENVIADO') // Já foi incluído
            ->whereHas('contrato', function ($query) {
                $query->sabemi()->canceladoSabemi();
            })
            ->get();

        // Critério 2: Contratos Inadimplentes (elegivel_exclusao = TRUE)
        $exclusoesInadimplencia = Beneficiario::where('status_envio_sabemi', 'ENVIADO')
            ->whereHas('contrato', function ($query) {
                $query->sabemi()->where('elegivel_exclusao', true);
            })
            ->get();

        $exclusoes = $exclusoesAtivas->merge($exclusoesInadimplencia)->unique('id');

        foreach ($exclusoes as $beneficiario) {
            $payload = $sabemiService->prepararPayloadBeneficiario($beneficiario, 'E');
            $movimentacoes->push(['beneficiario' => $beneficiario, 'tipo' => 'E', 'payload' => $payload]);
        }

        Log::info("ProcessarMovimentacoesDiariasJob - Exclusões coletadas", [
            'count' => $exclusoes->count()
        ]);

        // ============================================================
        // C. ALTERAÇÕES (A) - Troca de Plano e Reinclusão
        // ============================================================

        // Critério 1: Troca de Plano (data_troca_plano no contrato)
        $alteracoesPlano = Beneficiario::where('status_envio_sabemi', 'ENVIADO')
            ->whereHas('contrato', function ($query) use ($hoje) {
                $query->sabemi() // Garante que continua sendo Sabemi
                    ->whereNotNull('data_troca_plano')
                    ->whereDate('data_troca_plano', '<=', $hoje);
            })
            ->get();

        foreach ($alteracoesPlano as $beneficiario) {
            $payload = $sabemiService->prepararPayloadBeneficiario($beneficiario, 'A');
            $movimentacoes->push(['beneficiario' => $beneficiario, 'tipo' => 'A', 'payload' => $payload]);
        }

        Log::info("ProcessarMovimentacoesDiariasJob - Alterações de plano coletadas", [
            'count' => $alteracoesPlano->count()
        ]);

        // Critério 2: Reinclusão (elegivel_inclusao = TRUE)
        $reinclusoes = Beneficiario::where('status_envio_sabemi', 'PENDENTE') // Foi excluído e está PENDENTE
            ->whereHas('contrato', function ($query) {
                $query->sabemi()->where('elegivel_inclusao', true);
            })
            ->get();

        foreach ($reinclusoes as $beneficiario) {
            // Reinclusão é tratada como Inclusão (I) com retroatividade
            $dataRegularizacao = $beneficiario->contrato->data_regularizacao_inadimplencia->format('Y-m-d');
            $payload = $sabemiService->prepararPayloadBeneficiario($beneficiario, 'I', $dataRegularizacao);
            $movimentacoes->push(['beneficiario' => $beneficiario, 'tipo' => 'I', 'payload' => $payload]);
        }

        Log::info("ProcessarMovimentacoesDiariasJob - Reinclusões coletadas", [
            'count' => $reinclusoes->count()
        ]);

        return $movimentacoes->unique(function ($item) {
            return $item['beneficiario']->id . $item['tipo'];
        });
    }

    protected function atualizarStatusIntegracao($movimentacoes, $envioResult, $endosso)
    {
        if (!$envioResult['sucesso']) {
            Log::error("ProcessarMovimentacoesDiariasJob - Falha no envio da movimentação", [
                'mensagem' => $envioResult['mensagem']
            ]);
            // Logar erro geral no endosso
            $endosso->total_erro += $movimentacoes->count();
            $endosso->save();
            return;
        }

        // Simulação de processamento de resposta (em um cenário real, a Sabemi retorna um array de resultados)
        Log::info("ProcessarMovimentacoesDiariasJob - Atualizando status de integração");

        foreach ($movimentacoes as $item) {
            $beneficiario = $item['beneficiario'];
            $tipo = $item['tipo'];

            // Simulação de sucesso individual
            $sucessoIndividual = true;
            $idPrimeRetornado = $envioResult['detalhes']['CodigoContratoSabemi'] ?? null;

            if ($sucessoIndividual) {
                $beneficiario->status_envio_sabemi = 'ENVIADO';
                $beneficiario->data_envio_sabemi = Carbon::now();
                $beneficiario->erro_envio_sabemi = null;

                if ($tipo === 'I') {
                    $beneficiario->codigo_endosso_inclusao = $endosso->numero_endosso;
                    if ($idPrimeRetornado) {
                        $beneficiario->codigo_contrato_sabemi = $idPrimeRetornado;
                    }
                    $endosso->total_inclusoes++;
                } elseif ($tipo === 'E') {
                    $beneficiario->codigo_endosso_exclusao = $endosso->numero_endosso;
                    // O ID_Prime é mantido para rastreabilidade
                    $endosso->total_exclusoes++;
                } elseif ($tipo === 'A') {
                    // Alteração não muda o ID_Prime
                    $endosso->total_alteracoes++;
                }

                $endosso->total_sucesso++;
                $beneficiario->save();
            } else {
                // Lógica de erro individual
                $beneficiario->status_envio_sabemi = 'ERRO';
                $beneficiario->erro_envio_sabemi = 'Erro simulado na validação de dados.';
                $endosso->total_erro++;
                $beneficiario->save();
            }
        }

        $endosso->save();
    }

    public function failed(Throwable $exception)
    {
        Log::error("ProcessarMovimentacoesDiariasJob falhou", [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
