<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\Log;
use DB;

class SituacaoPagtoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 86400;
    public $failOnTimeout = false;

    public function uniqueId(): string
    {
        return 'situacao-pagto-job-regra-final-com-validade';
    }

    public function handle(): void
    {
        Log::info('Iniciando o job SituacaoPagtoJob (Regra Final com Validade).');

        // Data limite para inadimplência por vencimento
        $dataLimiteAtraso = now()->subDays(9)->toDateString();
        
        // Data limite para inadimplência por falta de atividade (pagamento antigo)
        $dataLimiteValidadePagto = now()->subMonth()->subDays(9)->toDateString();

        // Consulta Otimizada para a Regra Final
        $contratosParaAtualizar = DB::table('contratos as c')
            ->join(DB::raw("(
                -- Subconsulta para obter a ÚLTIMA parcela e a PRIMEIRA parcela em aberto
                SELECT
                    p.contrato_id,
                    ROW_NUMBER() OVER (PARTITION BY p.contrato_id ORDER BY p.data_vencimento DESC, p.id DESC) as rn_desc,
                    ROW_NUMBER() OVER (
                        PARTITION BY p.contrato_id,
                        CASE
                            -- Parcela em aberto para a rotina: sem pagamento e sem baixa.
                            WHEN p.data_pagamento IS NULL AND p.data_baixa IS NULL THEN 1
                            ELSE 0
                        END
                        ORDER BY p.data_vencimento ASC, p.id ASC
                    ) as rn_asc,
                    p.data_vencimento,
                    p.data_pagamento
                FROM parcelas p
            ) as p_info"), 'c.id', '=', 'p_info.contrato_id')
            ->select(
                'c.id as contrato_id',
                'c.situacao_pagto as situacao_pagto_original',
                DB::raw("
                    CASE
                        -- CENÁRIO A: A última parcela (rn_desc=1) está PAGA?
                        WHEN MAX(CASE WHEN p_info.rn_desc = 1 THEN p_info.data_pagamento END) IS NOT NULL THEN
                            -- SIM. Agora, a data desse pagamento é mais antiga que o limite de validade?
                            CASE
                                WHEN MAX(CASE WHEN p_info.rn_desc = 1 THEN p_info.data_pagamento END) < '{$dataLimiteValidadePagto}' THEN 'I' -- Se sim, INATIVO.
                                ELSE 'A' -- Se não (pagamento recente), ATIVO.
                            END
                        
                        -- CENÁRIO B: A última parcela NÃO está paga.
                        ELSE
                            -- NÃO. Então, a parcela vencida mais antiga (rn_asc=1) ultrapassou o limite de atraso?
                            CASE
                                WHEN MAX(CASE WHEN p_info.rn_asc = 1 THEN p_info.data_vencimento END) < '{$dataLimiteAtraso}' THEN 'I' -- Se sim, INATIVO.
                                ELSE 'A' -- Se não, ATIVO.
                            END
                    END AS situacao_pagto_calculado
                ")
            )
            ->whereIn('c.status', ['active', 'waitingPayment'])
            ->groupBy('c.id', 'c.situacao_pagto')
            ->get();

        // O código de atualização em massa permanece o mesmo...
        $this->atualizarContratos($contratosParaAtualizar);

        Log::info('Finalizando o job SituacaoPagtoJob (Regra Final com Validade).');
    }

    /**
     * Helper para executar a atualização dos contratos.
     */
    private function atualizarContratos($contratos): void
    {
        $idsParaMarcarComoInativo = [];
        $idsParaMarcarComoAtivo = [];

        foreach ($contratos as $contrato) {
            if ($contrato->situacao_pagto_original !== $contrato->situacao_pagto_calculado) {
                if ($contrato->situacao_pagto_calculado === 'I') {
                    $idsParaMarcarComoInativo[] = $contrato->contrato_id;
                } else {
                    $idsParaMarcarComoAtivo[] = $contrato->contrato_id;
                }
            }
        }

        if (!empty($idsParaMarcarComoInativo)) {
            DB::table('contratos')->whereIn('id', $idsParaMarcarComoInativo)->update(['situacao_pagto' => 'I']);
            Log::info('Contratos marcados como Inativos (I): ' . count($idsParaMarcarComoInativo));
        }

        if (!empty($idsParaMarcarComoAtivo)) {
            DB::table('contratos')->whereIn('id', $idsParaMarcarComoAtivo)->update(['situacao_pagto' => 'A']);
            Log::info('Contratos marcados como Ativos (A): ' . count($idsParaMarcarComoAtivo));
        }
    }
}
