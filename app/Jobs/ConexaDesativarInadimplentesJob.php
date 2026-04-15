<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Helpers\Conexa;
use Throwable;

/**
 * Job: Desativar Inadimplentes na Conexa
 *
 * Busca todos os beneficiários com produto Conexa integrado
 * cujo contrato está em situação de pagamento 'I' (inadimplente)
 * com dias_inadimplente >= $diasInadimplencia e os bloqueia na Conexa.
 *
 * Execução: Agendado diariamente às 07:15.
 *
 * @param int $diasInadimplencia Número mínimo de dias inadimplente para desativar (padrão: env CONEXA_DIAS_INADIMPLENCIA, fallback 30)
 */
class ConexaDesativarInadimplentesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries       = 1;
    public $timeout     = 86400;
    public $failOnTimeout = false;

    protected int $diasInadimplencia;

    public function __construct(int $diasInadimplencia = 0)
    {
        // Se não informado, usa a variável de ambiente (padrão 30)
        $this->diasInadimplencia = $diasInadimplencia > 0
            ? $diasInadimplencia
            : (int) env('CONEXA_DIAS_INADIMPLENCIA', 30);
    }

    public function handle()
    {
        Log::info("ConexaDesativarInadimplentesJob iniciado", [
            'dias_inadimplencia' => $this->diasInadimplencia,
        ]);

        $total   = 0;
        $sucesso = 0;
        $erros   = 0;

        try {
            // Busca beneficiários com produto Conexa integrado (idintegracao preenchido)
            // e contrato inadimplente (situacao_pagto = 'I') há pelo menos N dias
            $beneficiarios = DB::table('beneficiario_produto as bp')
                ->join('beneficiarios as b', 'b.id', '=', 'bp.beneficiario_id')
                ->join('contratos as c', 'c.id', '=', 'b.contrato_id')
                ->whereNotNull('bp.idintegracao')
                ->where('bp.idintegracao', '!=', '')
                ->where('bp.ativacao', '=', 1)
                ->where('c.situacao_pagto', '=', 'I')
                ->where('c.dias_inadimplente', '>=', $this->diasInadimplencia)
                ->whereNotNull('bp.produto_id')
                ->select([
                    'bp.beneficiario_id',
                    'bp.idintegracao',
                    'bp.produto_id',
                    'b.tipo as beneficiario_tipo',
                    'b.plano_id as beneficiario_plano_id',
                    'b.parent_id',
                    'c.plano_id as contrato_plano_id',
                    'c.tipo as contrato_tipo',
                    'c.dias_inadimplente',
                ])
                ->get();

            Log::info("ConexaDesativarInadimplentesJob - Beneficiários a desativar", [
                'count' => $beneficiarios->count(),
            ]);

            foreach ($beneficiarios as $benef) {
                $total++;
                $plano_id = $this->resolvePlanoId($benef);

                try {
                    $resultado = Conexa::inactivate($benef->idintegracao, $plano_id);

                    if ($resultado->ok === 'S') {
                        $sucesso++;
                        Log::info("ConexaDesativarInadimplentesJob - Desativado com sucesso", [
                            'beneficiario_id'  => $benef->beneficiario_id,
                            'idintegracao'     => $benef->idintegracao,
                            'dias_inadimplente' => $benef->dias_inadimplente,
                        ]);
                    } else {
                        $erros++;
                        Log::warning("ConexaDesativarInadimplentesJob - Falha ao desativar", [
                            'beneficiario_id' => $benef->beneficiario_id,
                            'idintegracao'    => $benef->idintegracao,
                            'mensagem'        => $resultado->mensagem ?? 'sem mensagem',
                        ]);
                    }
                } catch (Throwable $e) {
                    $erros++;
                    Log::error("ConexaDesativarInadimplentesJob - Erro ao processar beneficiário", [
                        'beneficiario_id' => $benef->beneficiario_id,
                        'idintegracao'    => $benef->idintegracao,
                        'erro'            => $e->getMessage(),
                    ]);
                }
            }

            Log::info("ConexaDesativarInadimplentesJob concluído", [
                'total'              => $total,
                'sucesso'            => $sucesso,
                'erros'              => $erros,
                'dias_inadimplencia' => $this->diasInadimplencia,
            ]);

        } catch (Throwable $exception) {
            Log::error("ConexaDesativarInadimplentesJob - Erro geral", [
                'exception' => $exception->getMessage(),
                'trace'     => $exception->getTraceAsString(),
            ]);
            throw $exception;
        }
    }

    /**
     * Resolve o plano_id correto conforme tipo de contrato e beneficiário.
     */
    private function resolvePlanoId($benef): int
    {
        if ($benef->contrato_tipo === 'F') {
            return (int) ($benef->contrato_plano_id ?? 0);
        }

        // Contratos J (jurídico): usa plano do beneficiário titular
        if ($benef->beneficiario_tipo === 'T') {
            return (int) ($benef->beneficiario_plano_id ?? 0);
        }

        // Dependente: busca plano do titular
        if ($benef->parent_id) {
            $titular = DB::table('beneficiarios')
                ->select('plano_id')
                ->where('id', $benef->parent_id)
                ->first();
            return (int) ($titular->plano_id ?? 0);
        }

        return 0;
    }

    public function failed(Throwable $exception)
    {
        Log::error("ConexaDesativarInadimplentesJob falhou", [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
