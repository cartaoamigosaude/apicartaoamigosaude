<?php

use App\Jobs\AtualizarInadimplenciaJob;
use App\Jobs\CelCashPagamentoAvulsoJob;
use App\Jobs\CelCashPagamentoConsultaJob;
use App\Jobs\CelCashPagamentoEmpresaJob;
use App\Jobs\CelCashSyncJob;
use App\Jobs\ConexaAtivarAdimplentesJob;
use App\Jobs\ConexaDesativarInadimplentesJob;
use App\Jobs\GestaoEndossoMensalJob;
use App\Jobs\ProcessarMovimentacoesDiariasJob;
use App\Jobs\SituacaoPagtoJob;
use App\Jobs\TransacaoCellCashJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('telescope:prune --hours=48')->daily();

/*
Schedule::job(new \App\Jobs\CancelarContratoParcelaJob())
           ->timezone('America/Sao_Paulo')
           ->withoutOverlapping()
           ->hourly();
*/
Schedule::job(new CelCashPagamentoConsultaJob)
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->everyFifteenMinutes();

$celcashSyncEnabled = (bool) config('services.celcash.sync_enabled', true);
$celcashSyncAtualizar = (string) config('services.celcash.sync_atualizar', 'S');
$celcashSyncAtualizar = $celcashSyncAtualizar === 'N' ? 'N' : 'S';
$celcashSyncDaysFrequent = (int) config('services.celcash.sync_days_frequent', 0);
$celcashSyncDaysHourly = (int) config('services.celcash.sync_days_hourly', 3);

if ($celcashSyncEnabled) {
    Schedule::job(new CelCashSyncJob($celcashSyncDaysFrequent, $celcashSyncAtualizar))
        ->timezone('America/Sao_Paulo')
        ->withoutOverlapping()
        ->everyFiveMinutes();

    Schedule::job(new CelCashSyncJob($celcashSyncDaysHourly, $celcashSyncAtualizar))
        ->timezone('America/Sao_Paulo')
        ->withoutOverlapping()
        ->hourly();
}

Schedule::job(new CelCashPagamentoEmpresaJob)
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->hourly();

Schedule::job(new CelCashPagamentoAvulsoJob)
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->everyThirtyMinutes();

Schedule::job(new TransacaoCellCashJob)
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->dailyAt('07:00');

Schedule::job(new SituacaoPagtoJob)
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->hourly();

// ============================================================
// AGENDAMENTO SABEMI - FLUXO DE MOVIMENTAÇÃO
// ============================================================

// 1. Processo 1: Gestão Mensal do Endosso (Abre/Fecha Endosso)
// Executa no dia 1º de cada mês, às 01:00 da manhã.
Schedule::job(new GestaoEndossoMensalJob)
    ->monthlyOn(1, '01:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// 2. Processo 2: Atualização Diária de Inadimplência (Controle de Ciclo de Vida)
// Executa diariamente, às 06:00 da manhã.
Schedule::job(new AtualizarInadimplenciaJob)
    ->dailyAt('06:00')
         // ->everyThirtyMinutes()
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// 3. Processo 3: Processamento Diário de Movimentações (Envio para Sabemi)
// Executa diariamente, às 23:00 (Endosso Aberto o Mês Todo).
Schedule::job(new ProcessarMovimentacoesDiariasJob)
    ->dailyAt('23:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// ============================================================
// CONEXA — Sincronização Diária de Status dos Beneficiários
// ============================================================

// Ativar todos os beneficiários adimplentes na Conexa
Schedule::job(new ConexaAtivarAdimplentesJob)
    ->dailyAt('07:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// Desativar beneficiários inadimplentes na Conexa (30 dias padrão via .env CONEXA_DIAS_INADIMPLENCIA)
Schedule::job(new ConexaDesativarInadimplentesJob)
    ->dailyAt('07:15')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();
