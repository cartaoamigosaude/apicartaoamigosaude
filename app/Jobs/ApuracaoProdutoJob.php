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
use DB;

class ApuracaoProdutoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries 			= 1;
    public $timeout 		= 86400;
    public $failOnTimeout 	= false;

    public function uniqueId(): string
    {
        return 'apuracao-produto-job';
    }

    public function handle(): void
    {
        Log::info('Iniciando o job ApuracaoProdutoJob');
		
		// Obtém a data de hoje
		$hoje 							= date('Y-m-d');
		// Obtém beneficiarios ativos 
		$beneficiarios 					= DB::connection('mysql')
										  ->table('beneficiarios')
										  ->select('id')
										  ->where('ativo','=',1)
										  ->get();
		
		foreach ($beneficiarios as $beneficiario) 
		{
			$plano 						= Cas::obterPlanoBeneficios($beneficiario->id);
			foreach ($plano->produtos as $produto)
			{
				
			}
		}
		
		Log::info("apuracao-produto-job", ['mensagens' => $mensagens ]);
		

    }

}
