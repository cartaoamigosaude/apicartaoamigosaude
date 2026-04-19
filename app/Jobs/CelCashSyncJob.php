<?php

namespace App\Jobs;

use App\Helpers\Cas;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

class CelCashSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 3600;

    public $failOnTimeout = true;

    protected int $days;

    protected string $atualizar;

    public function __construct(int $days = 0, string $atualizar = 'S')
    {
        $this->days = $days;
        $this->atualizar = $atualizar;
    }

    public function uniqueId(): string
    {
        return 'celcash-sync-job-'.$this->days.'-'.$this->atualizar;
    }

    public function handle(): void
    {
        Log::info('CelCashSyncJob: Iniciando sincronização ativa', [
            'days' => $this->days,
            'atualizar' => $this->atualizar,
        ]);

        $dataInicio = Carbon::now('America/Sao_Paulo')->subDays($this->days)->format('Y-m-d');
        $dataFim = Carbon::now('America/Sao_Paulo')->format('Y-m-d');

        $payload = new stdClass;
        $payload->updateStatusFrom = $dataInicio;
        $payload->updateStatusTo = $dataFim;
        $payload->start = 0;
        $payload->limite = 100;
        $payload->atualizar = $this->atualizar;

        try {
            $resultado = Cas::listarTransacoesStatus($payload);

            Log::info('CelCashSyncJob: Sincronização finalizada com sucesso', [
                'periodo' => "$dataInicio a $dataFim",
                'resultado' => $resultado,
            ]);
        } catch (Throwable $e) {
            Log::error('CelCashSyncJob: Falha crítica na sincronização', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CelCashSyncJob: Job falhou permanentemente', [
            'error' => $exception->getMessage(),
        ]);
    }
}
