<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Helpers\Cas;
use stdClass;
use DB;

class TransacaoCellCashJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries 			= 1;
    public $timeout 		= 86400;
    public $failOnTimeout 	= false;

    public function uniqueId(): string
    {
        return 'transacao-cellcash-job';
    }

    public function handle(): void
    {
        Log::info('Iniciando o job TransacaoCellCash');
		
		// Obtém a data de ontem (um dia antes da data atual)
		$dataOntem 						= Carbon::yesterday('America/Sao_Paulo')->format('Y-m-d');
		$dataInicio						= $dataOntem;
		$dataFim						= $dataOntem;
		
		// Gerar divergencias 
		$payload 						= new stdClass();
		$payload->updateStatusFrom 		= $dataInicio;
		$payload->updateStatusTo 		= $dataFim;
		$payload->start					= 0;
		$payload->limite				= 100;
		$payload->atualizar				= 'N';
		$resultado 						= Cas::listarTransacoesStatus($payload);
		
		Log::info("transacao-cellcash-job", ['resultado ' => $resultado  ]);
		// Obtém divergencias 
		$divergencias 					= DB::connection('mysql')
										  ->table('transacoes_divergencias')
										  ->select('transacoes_divergencias.id')
										  ->whereBetween('transacoes_data.data', [$dataInicio, $dataFim])
										  ->orderBy('transacoes_divergencias.id','asc')
										  ->get();
		
		$mensagens 						= array();
		
		foreach ($divergencias as $divergencia) 
		{
			$mensagens[] 				= Cas::atualizarDivergencia($divergencia->id);
		}
		
		Log::info("transacao-cellcash-job", ['mensagens' => $mensagens ]);
		
		// Gerar divergencias 
		$payload 						= new stdClass();
		$payload->updateStatusFrom 		= $dataInicio;
		$payload->updateStatusTo 		= $dataFim;
		$payload->start					= 0;
		$payload->limite				= 100;
		$payload->atualizar				= 'N';
		$resultado 						= Cas::listarTransacoesStatus($payload);
		
		Log::info("transacao-cellcash-job", ['resultado ' => $resultado  ]);
    }

}
