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
use App\Models\SabemiEndosso;
use Carbon\Carbon;

/**
 * Job: Processo 1 - Gestão Mensal do Endosso
 * 
 * Responsável por:
 * 1. Pesquisar se há endosso aberto.
 * 2. Se não houver, abrir um novo endosso.
 * 3. Se houver, fechar o endosso do mês anterior (se for dia 1º).
 * 
 * Execução: Agendado para o dia 1º de cada mês, às 01:00.
 */
class GestaoEndossoMensalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $tries = 1;
    public $timeout = 86400;
    public $failOnTimeout = false;

    protected $codigoContratoSabemi = 123456; // Código do Contrato Mestre na Sabemi
    protected $isDryRun = false;

    public function __construct(bool $isDryRun = false)
    {
        $this->isDryRun = $isDryRun;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("GestaoEndossoMensalJob iniciado", [
            'isDryRun' => $this->isDryRun
        ]);

        try {
            $sabemiService = app(SabemiService::class);
            $hoje = Carbon::now();

            // 1. Lógica de Fechamento (Dia 1º)
            if ($hoje->day === 1) {
                $this->fecharEndossoAnterior($sabemiService);
            }

            // 2. Lógica de Abertura (Se não houver aberto)
            $this->abrirNovoEndosso($sabemiService);

            Log::info("GestaoEndossoMensalJob concluído com sucesso");
        } catch (Throwable $exception) {
            Log::error("GestaoEndossoMensalJob - Erro durante execução", [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            throw $exception;
        }
    }

    protected function fecharEndossoAnterior(SabemiService $sabemiService)
    {
        // Encontra o último endosso que está ABERTO ou PROCESSANDO
        $endosso = SabemiEndosso::whereIn('status_endosso', ['ABERTO', 'PROCESSANDO'])
            ->orderBy('data_abertura', 'desc')
            ->first();

        if (!$endosso) {
            Log::warning("GestaoEndossoMensalJob - Nenhum endosso aberto ou processando para fechar");
            return;
        }

        Log::info("GestaoEndossoMensalJob - Fechando endosso anterior", [
            'numero_endosso' => $endosso->numero_endosso
        ]);

        if ($this->isDryRun) {
            Log::warning("GestaoEndossoMensalJob - DRY-RUN: Endosso seria fechado", [
                'numero_endosso' => $endosso->numero_endosso
            ]);
            return;
        }

        $fechamentoResult = $sabemiService->fecharEndosso($endosso->numero_endosso);

        if ($fechamentoResult['sucesso']) {
            $endosso->status_endosso = 'FECHADO';
            $endosso->data_fechamento = Carbon::now();
            $endosso->save();
            Log::info("GestaoEndossoMensalJob - Endosso fechado com sucesso", [
                'numero_endosso' => $endosso->numero_endosso
            ]);
        } else {
            $endosso->status_endosso = 'ERRO';
            $endosso->erro_fechamento = $fechamentoResult['mensagem'];
            $endosso->save();
            Log::error("GestaoEndossoMensalJob - Falha ao fechar endosso", [
                'numero_endosso' => $endosso->numero_endosso,
                'mensagem' => $fechamentoResult['mensagem']
            ]);
        }
    }

    protected function abrirNovoEndosso(SabemiService $sabemiService)
    {
        // Verifica se já existe um endosso aberto
        $endossoAberto = SabemiEndosso::where('status_endosso', 'ABERTO')->first();

        if ($endossoAberto) {
            Log::warning("GestaoEndossoMensalJob - Endosso já está aberto", [
                'numero_endosso' => $endossoAberto->numero_endosso
            ]);
            return;
        }

        Log::info("GestaoEndossoMensalJob - Tentando abrir novo endosso");

        if ($this->isDryRun) {
            Log::warning("GestaoEndossoMensalJob - DRY-RUN: Novo endosso seria aberto");
            return;
        }

        $aberturaResult = $sabemiService->abrirEndosso($this->codigoContratoSabemi);

        if ($aberturaResult['sucesso']) {
            SabemiEndosso::create([
                'numero_endosso' => $aberturaResult['codigo_endosso'],
                'codigo_apolice' => $sabemiService->codigoApolice,
                'codigo_grupo' => $sabemiService->codigoGrupo,
                'status_endosso' => 'ABERTO',
                'data_abertura' => Carbon::now(),
            ]);
            Log::info("GestaoEndossoMensalJob - Novo endosso aberto e registrado com sucesso", [
                'numero_endosso' => $aberturaResult['codigo_endosso']
            ]);
        } else {
            Log::error("GestaoEndossoMensalJob - Falha ao abrir novo endosso", [
                'mensagem' => $aberturaResult['mensagem']
            ]);
        }
    }

    public function failed(Throwable $exception)
    {
        Log::error("GestaoEndossoMensalJob falhou", [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
