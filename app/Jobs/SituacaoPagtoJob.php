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

        // Data limite para inadimplencia por vencimento.
        $dataLimiteAtraso = now()->subDays(9)->toDateString();

        // Data limite para inadimplencia por falta de atividade (pagamento antigo).
        $dataLimiteValidadePagto = now()->subMonth()->subDays(9)->toDateString();

        $contratosParaAtualizar = DB::table('contratos as c')
            ->join(DB::raw("(
                SELECT
                    p.contrato_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY p.contrato_id
                        ORDER BY p.data_vencimento DESC, p.id DESC
                    ) as rn_desc,
                    p.data_vencimento,
                    p.data_pagamento,
                    p.data_baixa
                FROM parcelas p
            ) as p_info"), 'c.id', '=', 'p_info.contrato_id')
            ->select(
                'c.id as contrato_id',
                'c.situacao_pagto as situacao_pagto_original',
                DB::raw("
                    CASE
                        WHEN MAX(CASE WHEN p_info.rn_desc = 1 THEN p_info.data_pagamento END) IS NOT NULL THEN
                            CASE
                                WHEN MAX(CASE WHEN p_info.rn_desc = 1 THEN p_info.data_pagamento END) < '{$dataLimiteValidadePagto}' THEN 'I'
                                ELSE 'A'
                            END
                        ELSE
                            CASE
                                WHEN MIN(CASE
                                    WHEN p_info.data_pagamento IS NULL AND p_info.data_baixa IS NULL
                                    THEN p_info.data_vencimento
                                END) < '{$dataLimiteAtraso}' THEN 'I'
                                ELSE 'A'
                            END
                    END AS situacao_pagto_calculado
                ")
            )
            ->whereIn('c.status', ['active', 'waitingPayment'])
            ->groupBy('c.id', 'c.situacao_pagto')
            ->get();

        $this->atualizarContratos($contratosParaAtualizar);

        Log::info('Finalizando o job SituacaoPagtoJob (Regra Final com Validade).');
    }

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
