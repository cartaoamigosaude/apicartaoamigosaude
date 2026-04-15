<?php
namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Helpers\CelCash;
use Illuminate\Support\Facades\Log;

class CelCashBalancoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de tentativas - 1 = executa apenas uma vez
     */
    public $tries = 1;
    
    /**
     * Timeout do job em segundos
     */
    public $timeout = 86400;
    
    /**
     * Não falhar por timeout
     */
    public $failOnTimeout = false;
    
    /**
     * ID da parcela a ser processada
     */
    public $id;
	public $cpf;

    /**
     * Create a new job instance.
     *
     * @param mixed $id
     * @return void
     */
    public function __construct($id, $cpf)
    {
        $this->id 	= $id;
		$this->cpf 	= $cpf;
    }

    /**
     * Get the unique ID for the job (evita jobs duplicados na fila)
     */
    public function uniqueId()
    {
        return 'celcash_balanco' . $this->id . "#".  $this->cpf;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            Log::info("Iniciando processamento CelCash para contrato ID: {$this->id}");
            
            $return = CelCash::balancoContrato($this->id, $this->cpf);
            
            Log::info("CelCash processado com sucesso para contrato ID: {$this->id}", [
                'result' => $return
            ]);
            
        } catch (\Exception $e) {
            // Log do erro
            Log::error("Erro no processamento CelCash para contrato ID: {$this->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts()
            ]);
            
            // Marcar job como falhado sem reprocessar
            $this->fail($e);
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error("Job CelCashParcelasAvulsaJob falhou definitivamente para parcela ID: {$this->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
        
        // Opcional: Atualizar status no banco de dados
        // \DB::table('sua_tabela_parcelas')
        //     ->where('id', $this->id)
        //     ->update([
        //         'status' => 'erro_celcash',
        //         'erro_mensagem' => $exception->getMessage(),
        //         'updated_at' => now()
        //     ]);
    }
}