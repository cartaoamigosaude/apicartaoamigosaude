<?php

namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Helpers\CelCash;

class CelCashPagamentoAvulsoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $tries 						= 1;
    public $timeout 					= 300; // 5 minutos
	public $failOnTimeout 				= true;
	public $uniqueFor 					= 3600; // 1 hora
	
    public function __construct()
    {
    }

	/**
     * Get the unique ID for the job.
     */
    public function uniqueId()
    {
        return 'celcash_pagamento_avulso_1';
    }


    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {	
		try {
			$galaxId = 1;
			Log::info("Iniciando CelCashPagamentoAvulsoJob", ['galaxId' => $galaxId]);
			$response = CelCash::celcashPagamentoAvulso($galaxId);
			Log::info("CelCashPagamentoAvulsoJob concluído", ['response' => $response]);
		} catch (\Exception $e) {
			Log::error("Erro no CelCashPagamentoAvulsoJob", [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
				'galaxId' => $galaxId ?? null
			]);
			throw $e; // Re-throw para que o Laravel gerencie as tentativas
		}
    }
	
	public function failed(Throwable $exception)
    {
		Log::error("CelCashPagamentoAvulsoJob failed definitivamente", [
			'exception' => $exception->getMessage(),
			'trace' => $exception->getTraceAsString(),
			'attempts' => $this->attempts()
		]);
	}
}
