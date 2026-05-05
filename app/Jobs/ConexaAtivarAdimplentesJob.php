<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Helpers\Cas;
use App\Helpers\Conexa;
use Throwable;

/**
 * Job: Ativar Adimplentes na Conexa
 *
 * Busca todos os beneficiários com produto Conexa integrado
 * cujo contrato está em situação de pagamento 'A' (adimplente)
 * e os ativa na plataforma Conexa Saúde.
 *
 * Execução: Agendado diariamente às 07:00.
 */
class ConexaAtivarAdimplentesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries       = 1;
    public $timeout     = 86400;
    public $failOnTimeout = false;

    public function __construct()
    {
    }

    public function handle()
    {
        Log::info("ConexaAtivarAdimplentesJob iniciado");

        $total   = 0;
        $sucesso = 0;
        $erros   = 0;

        try {
            // Busca beneficiários com produto Conexa integrado (idintegracao preenchido)
            // e contrato adimplente (situacao_pagto = 'A')
            $beneficiarios = DB::table('beneficiario_produto as bp')
                ->join('beneficiarios as b', 'b.id', '=', 'bp.beneficiario_id')
                ->join('contratos as c', 'c.id', '=', 'b.contrato_id')
                ->join('clientes as cl', 'cl.id', '=', 'b.cliente_id')
                ->where('bp.produto_id', '=', 4)
                ->whereNotNull('bp.idintegracao')
                ->where('bp.idintegracao', '!=', '')
                ->where('b.ativo', '=', 1)
                ->whereIn('c.status', ['active', 'waitingPayment'])
                ->where('c.situacao_pagto', '=', 'A')
                ->select([
                    'bp.beneficiario_id',
                    'bp.idintegracao',
                    'bp.produto_id',
                    'bp.ativacao',
                    'b.tipo as beneficiario_tipo',
                    'b.plano_id as beneficiario_plano_id',
                    'b.parent_id',
                    'c.plano_id as contrato_plano_id',
                    'c.tipo as contrato_tipo',
                    'cl.cpfcnpj as cpf',
                ])
                ->get();

            Log::info("ConexaAtivarAdimplentesJob - Beneficiários a ativar", [
                'count' => $beneficiarios->count()
            ]);

            foreach ($beneficiarios as $benef) {
                $total++;
                $plano_id = $this->resolvePlanoId($benef);

                try {
                    $status = Conexa::buscarPacienteStatus($benef->cpf, $plano_id);

                    if ($status->ok === 'S' && isset($status->status) && strtoupper((string) $status->status) === 'ACTIVE') {
                        if ($this->precisaSincronizarAtivacaoLocal($benef)) {
                            $sincronizacao = $this->sincronizarAtivacaoLocal($benef);

                            if ($sincronizacao->ok !== 'S') {
                                $erros++;
                                Log::warning("ConexaAtivarAdimplentesJob - Falha ao sincronizar ativação local", [
                                    'beneficiario_id' => $benef->beneficiario_id,
                                    'idintegracao'    => $benef->idintegracao,
                                    'mensagem'        => $sincronizacao->mensagem ?? 'sem mensagem',
                                ]);
                                continue;
                            }
                        }

                        $sucesso++;
                        Log::info("ConexaAtivarAdimplentesJob - Ativado com sucesso", [
                            'beneficiario_id' => $benef->beneficiario_id,
                            'idintegracao'    => $benef->idintegracao,
                        ]);
                        continue;
                    }

                    $resultado = Conexa::activate($benef->idintegracao, $plano_id);

                    if ($resultado->ok !== 'S') {
                        $erros++;
                        Log::warning("ConexaAtivarAdimplentesJob - Falha ao ativar", [
                            'beneficiario_id' => $benef->beneficiario_id,
                            'idintegracao'    => $benef->idintegracao,
                            'mensagem'        => $resultado->mensagem ?? 'sem mensagem',
                        ]);
                        continue;
                    }

                    if ($this->precisaSincronizarAtivacaoLocal($benef)) {
                        $sincronizacao = $this->sincronizarAtivacaoLocal($benef);

                        if ($sincronizacao->ok !== 'S') {
                            $erros++;
                            Log::warning("ConexaAtivarAdimplentesJob - Falha ao sincronizar ativação local", [
                                'beneficiario_id' => $benef->beneficiario_id,
                                'idintegracao'    => $benef->idintegracao,
                                'mensagem'        => $sincronizacao->mensagem ?? 'sem mensagem',
                            ]);
                            continue;
                        }
                    }

                    $sucesso++;
                    Log::info("ConexaAtivarAdimplentesJob - Ativado com sucesso", [
                        'beneficiario_id' => $benef->beneficiario_id,
                        'idintegracao'    => $benef->idintegracao,
                    ]);
                } catch (Throwable $e) {
                    $erros++;
                    Log::error("ConexaAtivarAdimplentesJob - Erro ao processar beneficiário", [
                        'beneficiario_id' => $benef->beneficiario_id,
                        'idintegracao'    => $benef->idintegracao,
                        'erro'            => $e->getMessage(),
                    ]);
                }
            }

            Log::info("ConexaAtivarAdimplentesJob concluído", [
                'total'   => $total,
                'sucesso' => $sucesso,
                'erros'   => $erros,
            ]);

        } catch (Throwable $exception) {
            Log::error("ConexaAtivarAdimplentesJob - Erro geral", [
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

    private function precisaSincronizarAtivacaoLocal($benef): bool
    {
        return (int) ($benef->ativacao ?? 0) !== 1;
    }

    private function sincronizarAtivacaoLocal($benef)
    {
        return Cas::ativarDesativarProduto(
            $benef->beneficiario_id,
            (int) $benef->produto_id,
            true,
            (string) $benef->idintegracao
        );
    }

    public function failed(Throwable $exception)
    {
        Log::error("ConexaAtivarAdimplentesJob falhou", [
            'exception' => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }
}
