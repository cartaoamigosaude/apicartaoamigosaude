<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('horizon:snapshot')->everyFiveMinutes(); 

 /* 
Schedule::job(new \App\Jobs\CancelarContratoParcelaJob())
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping()
            ->hourly(); 
*/
Schedule::job(new \App\Jobs\CelCashPagamentoConsultaJob())
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping()
             ->everyFifteenMinutes(); 

			
Schedule::job(new \App\Jobs\CelCashPagamentoEmpresaJob())
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping()
            ->hourly();

Schedule::job(new \App\Jobs\CelCashPagamentoAvulsoJob())
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping()
            ->everyThirtyMinutes();

Schedule::job(new \App\Jobs\TransacaoCellCashJob())
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping()
            ->dailyAt('07:00');	
			
Schedule::job(new \App\Jobs\SituacaoPagtoJob())
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping()
            ->hourly();		
			

// ============================================================
// AGENDAMENTO SABEMI - FLUXO DE MOVIMENTAÇÃO
// ============================================================

// 1. Processo 1: Gestão Mensal do Endosso (Abre/Fecha Endosso)
// Executa no dia 1º de cada mês, às 01:00 da manhã.
Schedule::job(new \App\Jobs\GestaoEndossoMensalJob())
         ->monthlyOn(1, '01:00')
         ->timezone('America/Sao_Paulo')
         ->withoutOverlapping();

// 2. Processo 2: Atualização Diária de Inadimplência (Controle de Ciclo de Vida)
// Executa diariamente, às 06:00 da manhã.
Schedule::job(new \App\Jobs\AtualizarInadimplenciaJob())
         ->dailyAt('06:00')
		 //->everyThirtyMinutes()
         ->timezone('America/Sao_Paulo')
         ->withoutOverlapping();

// 3. Processo 3: Processamento Diário de Movimentações (Envio para Sabemi)
// Executa diariamente, às 23:00 (Endosso Aberto o Mês Todo).
Schedule::job(new \App\Jobs\ProcessarMovimentacoesDiariasJob())
         ->dailyAt('23:00')
         ->timezone('America/Sao_Paulo')
         ->withoutOverlapping();

// ============================================================
// CONEXA — Sincronização Diária de Status dos Beneficiários
// ============================================================

// Ativar todos os beneficiários adimplentes na Conexa
Schedule::job(new \App\Jobs\ConexaAtivarAdimplentesJob())
         ->dailyAt('07:00')
         ->timezone('America/Sao_Paulo')
         ->withoutOverlapping();

// Desativar beneficiários inadimplentes na Conexa (30 dias padrão via .env CONEXA_DIAS_INADIMPLENCIA)
Schedule::job(new \App\Jobs\ConexaDesativarInadimplentesJob())
         ->dailyAt('07:15')
         ->timezone('America/Sao_Paulo')
         ->withoutOverlapping();

