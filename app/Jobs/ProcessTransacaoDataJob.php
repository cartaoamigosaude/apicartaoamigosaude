<?php

namespace App\Jobs;

use App\Models\TransacaoData;
use App\Models\TransacaoDivergencia;
use App\Helpers\Cas;  
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use stdClass;
use Exception;

class ProcessTransacaoDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Parâmetros para o processamento
     */
    protected $updateStatusFrom;
    protected $updateStatusTo;
    protected $start;
    protected $limite;

    /**
     * Número de tentativas do job
     */
    public $tries = 1;

    /**
     * Timeout do job em segundos
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param string $updateStatusFrom
     * @param string $updateStatusTo
     * @param int $start
     * @param int $limite
     */
    public function __construct(
        string $updateStatusFrom = '',
        string $updateStatusTo = '',
        int $start = 0,
        int $limite = 100
    ) {
        $this->updateStatusFrom 	= $updateStatusFrom ?: date('Y-m-d');
        $this->updateStatusTo 		= $updateStatusTo ?: date('Y-m-d');
        $this->start 				= $start;
        $this->limite 				= $limite;
    }

    /**
     * Get the unique ID for the job (chave única baseada nas datas)
     */
    public function uniqueId(): string
    {
        return 'process_transacao_data_' . $this->updateStatusFrom . '_' . $this->updateStatusTo;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info('Iniciando processamento de transações', [
                'update_status_from' 	=> $this->updateStatusFrom,
                'update_status_to' 		=> $this->updateStatusTo,
                'start' 				=> $this->start,
                'limite' 				=> $this->limite
            ]);

            // Preparar payload para a chamada da API
            $payload 											= $this->prepararPayload();

            // Chamar a função Cas::listarTransacoesStatus
            $resultado 											= Cas::listarTransacoesStatus($payload);

            Log::info('Processamento de transações concluído com sucesso', [
                'periodo' 	=> "{$this->updateStatusFrom} - {$this->updateStatusTo}",
				'resultado'	=> $resultado
            ]);

        } catch (Exception $e) {
            Log::error('Erro no processamento de transações', [
                'periodo' => "{$this->updateStatusFrom} - {$this->updateStatusTo}",
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Preparar payload para chamada da API
     */
    protected function prepararPayload(): stdClass
    {
        $payload 						= new stdClass();
        $payload->updateStatusFrom 		= $this->updateStatusFrom;
        $payload->updateStatusTo 		= $this->updateStatusTo;
        $payload->start 				= $this->start;
        $payload->limite 				= $this->limite;

        return $payload;
    }

    /**
     * Handle a job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error('Job ProcessTransacaoData falhou definitivamente', [
            'periodo' => "{$this->updateStatusFrom} - {$this->updateStatusTo}",
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}