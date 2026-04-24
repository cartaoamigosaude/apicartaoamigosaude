<?php

namespace App\Services\CelCash;

use App\Helpers\Cas;
use App\Helpers\CelCash;
use App\Models\Balanco;
use App\Models\Contrato;
use App\Models\Parcela;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

class CelCashConciliacaoService
{
    private static function selecionarTransacaoMaisRelevante(array $transactions): ?object
    {
        $candidatos = [];
        foreach ($transactions as $t) {
            if (! is_object($t)) {
                continue;
            }
            $candidatos[] = $t;
        }

        if (! $candidatos) {
            return null;
        }

        usort($candidatos, static function ($a, $b) {
            $aData = $a->paydayDate ?? ($a->statusDate ?? null);
            $bData = $b->paydayDate ?? ($b->statusDate ?? null);

            if (! $aData && ! $bData) {
                return 0;
            }
            if (! $aData) {
                return 1;
            }
            if (! $bData) {
                return -1;
            }

            return strcmp($bData, $aData);
        });

        return $candidatos[0];
    }

    private static function selecionarTransacaoDaParcela(Parcela $parcela, array $transactions): ?object
    {
        $contratoId = $parcela->contrato_id ?? ($parcela->contrato->id ?? null);
        $parcelaId = $parcela->id;

        foreach ($transactions as $t) {
            if (! is_object($t)) {
                continue;
            }
            if (! isset($t->chargeMyId) || substr_count((string) $t->chargeMyId, '#') !== 2) {
                continue;
            }
            [$contratoTs, $parcelaTs] = explode('#', (string) $t->chargeMyId, 3);
            if ((int) $contratoTs === (int) $contratoId && (int) $parcelaTs === (int) $parcelaId) {
                return $t;
            }
        }

        foreach ($transactions as $t) {
            if (! is_object($t)) {
                continue;
            }
            if (
                isset($t->chargeGalaxPayId, $t->galaxPayId)
                && (int) $t->chargeGalaxPayId > 0
                && (int) $t->galaxPayId > 0
                && (int) $t->chargeGalaxPayId === (int) $parcela->cgalaxPayId
                && (int) $t->galaxPayId === (int) $parcela->galaxPayId
            ) {
                return $t;
            }
        }

        foreach ($transactions as $t) {
            if (! is_object($t)) {
                continue;
            }
            if (! isset($t->galaxPayId) || (int) $t->galaxPayId <= 0) {
                continue;
            }
            if ((int) $t->galaxPayId !== (int) $parcela->galaxPayId) {
                continue;
            }

            $payday = $t->payday ?? null;
            if ($payday) {
                $paydayNorm = date('Y-m-d', strtotime((string) $payday));
                $parcelaVenc = date('Y-m-d', strtotime((string) $parcela->data_vencimento));
                if ($paydayNorm === $parcelaVenc) {
                    return $t;
                }
            }
        }

        return null;
    }

    public static function CelCashConsultaConciliar($galaxPayId)
    {
        $retorno = new stdClass;
        $retorno->status = 200;
        $retorno->erro = false;
        $retorno->dataPagamento = '';
        $retorno->dataBaixa = '';
        $retorno->mensagem = '';

        if ($galaxPayId > 0) {
            $query = 'startAt=0&limit=100&chargeGalaxPayIds='.$galaxPayId;

            $transaction = CelCash::getTransaction($query, 2);

            if ((isset($transaction->Transactions)) and (is_array($transaction->Transactions)) and (count($transaction->Transactions) > 0)) {
                $transacaoSelecionada = self::selecionarTransacaoMaisRelevante($transaction->Transactions);
                if (! $transacaoSelecionada) {
                    $retorno->status = 404;
                    $retorno->erro = true;
                    $retorno->mensagem = 'A transação não foi encontrada no Cell Cash';

                    return $retorno;
                }

                if (isset($transacaoSelecionada->paydayDate)) {
                    $retorno->dataPagamento = $transacaoSelecionada->paydayDate;
                } else {
                    if ((isset($transacaoSelecionada->status)) and ($transacaoSelecionada->status == 'cancel')) {
                        $retorno->dataBaixa = $transacaoSelecionada->statusDate;
                    }
                }
                $retorno->transaction = $transacaoSelecionada;
            } else {
                $retorno->status = 404;
                $retorno->erro = true;
                $retorno->mensagem = 'A transação não foi encontrada no Cell Cash';
            }
        } else {
            $retorno->status = 404;
            $retorno->erro = true;
            $retorno->mensagem = 'Ainda não foi gerado a cobrança no Cell Cash';
        }

        return $retorno;
    }

    public static function CelCashParcelaConciliar($id)
    {

        $retorno = new stdClass;
        $retorno->status = 200;
        $retorno->erro = false;
        $retorno->mudanca = false;
        $retorno->dataPagamento = '';
        $retorno->dataBaixa = '';
        $retorno->situacao = '';
        $retorno->mensagem = '';

        $parcela = Parcela::with('contrato')->find($id);

        if (! isset($parcela->id)) {
            $retorno->status = 404;
            $retorno->erro = true;
            $retorno->mensagem = 'Dados da parcela nao encontrado';

            return $retorno;
        }

        $parcela->ultima_consulta_celcash_at = now();
        $parcela->save();

        $query = 'startAt=0&limit=100&';

        if (($parcela->contrato->tipo == 'J') or ($parcela->contrato->avulso == 'S')) {
            if ($parcela->contrato->tipo == 'J') {
                $galaxPayId = $parcela->contrato->galaxPayId;
            } else {
                $galaxPayId = $parcela->cgalaxPayId;
            }
            $query .= 'chargeGalaxPayIds='.$galaxPayId;
            $payid = $galaxPayId;
        } else {
            $query .= 'galaxPayIds='.$parcela->galaxPayId;
            $payid = $parcela->galaxPayId;
        }

        if ($payid > 0) {
            $transaction = CelCash::getTransaction($query);

            if ((isset($transaction->Transactions)) and (is_array($transaction->Transactions)) and (count($transaction->Transactions) > 0)) {
                $transacaoSelecionada = self::selecionarTransacaoDaParcela($parcela, $transaction->Transactions);
                if (! $transacaoSelecionada) {
                    $retorno->status = 404;
                    $retorno->erro = true;
                    $retorno->mensagem = 'A transação não foi encontrada no Cell Cash';

                    return $retorno;
                }

                if (isset($transacaoSelecionada->payday)) {
                    $data_vencimento = $transacaoSelecionada->payday;
                } else {
                    $data_vencimento = $parcela->data_vencimento;
                }
                if (isset($transacaoSelecionada->paydayDate)) {
                    $data_pagamento = substr($transacaoSelecionada->paydayDate, 0, 10);
                } else {
                    $data_pagamento = $parcela->data_pagamento;
                }

                if ((isset($transacaoSelecionada->status)) and ($transacaoSelecionada->status == 'cancel')) {
                    $data_baixa = substr($transacaoSelecionada->statusDate, 0, 10);
                } else {
                    $data_baixa = $parcela->data_baixa;
                }
                if (($parcela->data_pagamento != $data_pagamento) or ($parcela->data_baixa != $data_baixa)) {
                    $retorno->mensagem = 'Atenção!! Houve mudança de situação';
                    $retorno->mudanca = true;
                } else {
                    $retorno->mensagem = 'Não houve mudança de situação';
                    $retorno->mudanca = false;
                }
                $retorno->dataPagamento = $data_pagamento;
                $retorno->dataBaixa = $data_baixa;
                $retorno->situacao = Cas::obterSituacaoParcela($data_vencimento, $data_pagamento, $data_baixa);

                if ($retorno->mudanca) {
                    $parcela->data_pagamento = $data_pagamento;
                    $parcela->data_baixa = $data_baixa;
                    $parcela->save();
                }
                $retorno->erro = false;
            } else {
                $retorno->status = 404;
                $retorno->erro = true;
                $retorno->mensagem = 'A transação não foi encontrada no Cell Cash';
            }
        } else {
            $retorno->status = 404;
            $retorno->erro = true;
            $retorno->mensagem = 'Ainda não foi gerado a cobrança no Cell Cash';
        }

        return $retorno;
    }

    /**
     * Faz o balanço entre dados extraídos das transações e registros da tabela parcelas
     *
     * @param  int  $contratoId  ID do contrato
     * @param  array  $dadosExtraidos  Dados extraídos das transações
     * @return array Array com o balanço dos dados
     */
    public static function balancearDadosTransacoesParcelas($contratoId, $transacoesExtraidas)
    {
        DB::table('balancos')->where('contrato_id', '=', $contratoId)->delete();

        // Buscar todas as parcelas do contrato
        $parcelas = Parcela::where('contrato_id', '=', $contratoId)->orderBy('nparcela')->get();

        $balance = [
            // 'resumo_transacoes' => [], // Apenas campos essenciais das transações
            // 'resumo_parcelas' => [], // Apenas campos essenciais das parcelas
            'parcelas_com_transacao' => [],
            'parcelas_sem_transacao' => [],
            'transacoes_duplicadas' => [],
            'transacoes_sem_parcela' => [],
            'estatisticas' => [
                'total_transacoes' => count($transacoesExtraidas),
                'total_parcelas' => $parcelas->count(),
                'parcelas_com_transacao' => 0,
                'parcelas_sem_transacao' => 0,
                'transacoes_duplicadas' => 0,
                'transacoes_sem_parcela' => 0,
            ],
        ];
        /*
        // Resumo das transações - apenas campos de comparação
        foreach ($transacoesExtraidas as $index => $transacao) {
            $balance['resumo_transacoes'][] = [
                'index' => $index,
                'galaxPayId' => $transacao['transaction']['galaxPayId'] ?? null,
                'payday' => $transacao['transaction']['payday'] ?? null,
                'paydayDate' => $transacao['transaction']['paydayDate'] ?? null,
                'value' => $transacao['transaction']['value'] ?? null,
                'status' => $transacao['transaction']['status'] ?? null
            ];
        }
        */
        // Resumo das parcelas - apenas campos de comparação
        /*
        foreach ($parcelas as $parcela) {
            $balance['resumo_parcelas'][] = [
                'id' => $parcela->id,
                'galaxPayId' => $parcela->galaxPayId,
                'data_vencimento' => $parcela->data_vencimento,
                'data_pagamento' => $parcela->data_pagamento,
                'valor' => $parcela->valor,
                'status' => $parcela->status
            ];
        }
        */
        // Identificar duplicatas ANTES de processar
        $transacoesPorChave = [];
        $transacoesProcessadas = [];

        foreach ($transacoesExtraidas as $index => $transacao) {
            $data = $transacao['transaction']['payday'] ?? null;
            $galaxPayId = $transacao['transaction']['galaxPayId'] ?? null;

            if ($data && $galaxPayId) {
                $dataNormalizada = date('Y-m-d', strtotime($data));
                $chave = $galaxPayId.'_'.$dataNormalizada;

                if (isset($transacoesPorChave[$chave])) {
                    // É uma duplicata
                    $balance['transacoes_duplicadas'][] = [
                        'index_original' => $transacoesPorChave[$chave]['index'],
                        'index_duplicata' => $index,
                        'galaxPayId' => $galaxPayId,
                        'payday' => $data,
                        'paydayDate' => $transacao['transaction']['paydayDate'] ?? null,
                        'value' => $transacao['transaction']['value'] ?? null,
                    ];
                    $balance['estatisticas']['transacoes_duplicadas']++;
                } else {
                    // Primeira ocorrência desta transação
                    $transacoesPorChave[$chave] = [
                        'index' => $index,
                        'transaction' => $transacao,
                    ];
                    $transacoesProcessadas[] = $transacao;
                }
            } else {
                // Transação sem dados suficientes para verificar duplicata
                $transacoesProcessadas[] = $transacao;
            }
        }

        // Agrupar transações válidas por galaxPayId
        $transacoesPorGalaxyPayId = [];
        foreach ($transacoesProcessadas as $transacao) {
            $galaxPayId = $transacao['transaction']['galaxPayId'] ?? null;
            if ($galaxPayId) {
                // Apenas a primeira transação por galaxPayId será considerada
                if (! isset($transacoesPorGalaxyPayId[$galaxPayId])) {
                    $transacoesPorGalaxyPayId[$galaxPayId] = $transacao;
                }
            }
        }

        // Processar cada parcela
        foreach ($parcelas as $parcela) {
            $parcelaGalaxyPayId = $parcela->galaxPayId;

            if ($parcelaGalaxyPayId && isset($transacoesPorGalaxyPayId[$parcelaGalaxyPayId])) {
                $transacaoCorrespondente = $transacoesPorGalaxyPayId[$parcelaGalaxyPayId];
                $data_baixa = null;

                if (($transacaoCorrespondente['transaction']['status'] == 'cancel') and (Cas::temData($transacaoCorrespondente['transaction']['statusDate']))) {
                    $data_baixa = substr($transacaoCorrespondente['transaction']['statusDate'], 0, 10);
                }

                $balance['parcelas_com_transacao'][] = [
                    'parcela_resumo' => [
                        'id' => $parcela->id,
                        'galaxPayId' => $parcela->galaxPayId,
                        'data_vencimento' => $parcela->data_vencimento,
                        'data_pagamento' => $parcela->data_pagamento,
                        'data_baixa' => $parcela->data_baixa,
                        'nparcela' => $parcela->nparcela,
                        'valor' => $parcela->valor,
                        'status' => $parcela->status,
                    ],
                    'transacao_resumo' => [
                        'galaxPayId' => $transacaoCorrespondente['transaction']['galaxPayId'] ?? null,
                        'mainPaymentMethodId' => $transacaoCorrespondente['transaction']['mainPaymentMethodId'] ?? null,
                        'payday' => $transacaoCorrespondente['transaction']['payday'] ?? null,
                        'paydayDate' => $transacaoCorrespondente['transaction']['paydayDate'] ?? null,
                        'data_baixa' => $data_baixa,
                        'installment' => $transacaoCorrespondente['transaction']['installment'] ?? 0,
                        'value' => $transacaoCorrespondente['transaction']['value'] ?? null,
                        'status' => $transacaoCorrespondente['transaction']['status'] ?? null,
                        'response' => $transacaoCorrespondente,
                    ],
                    'criterio_correspondencia' => 'galaxPayId',
                ];
                $balance['estatisticas']['parcelas_com_transacao']++;

                if ($parcela->status != $transacaoCorrespondente['transaction']['status']) {
                    if (($parcela->status != 'closed') and ($transacaoCorrespondente['transaction']['status'] != 'payedBoleto')) {
                        $balanco = new Balanco;
                        $balanco->contrato_id = $contratoId;
                        $balanco->parcela_id = $parcela->id;
                        $balanco->nparcela = $parcela->nparcela;
                        $balanco->cpf = $transacaoCorrespondente['customer']['document'] ?? '';
                        $balanco->data_vencimento = $parcela->data_vencimento;
                        $balanco->data_pagamento = $parcela->data_pagamento;
                        $balanco->data_baixa = $parcela->data_baixa;
                        $balanco->valor = $parcela->valor;
                        $balanco->situacao = $parcela->status;
                        $balanco->galaxPayId = $transacaoCorrespondente['transaction']['galaxPayId'] ?? 0;
                        $balanco->cgalaxPayId = $transacaoCorrespondente['transaction']['chargeGalaxPayId'] ?? 0;
                        $balanco->payday = $transacaoCorrespondente['transaction']['payday'] ?? null;
                        $balanco->paydayDate = isset($transacaoCorrespondente['transaction']['paydayDate']) ? $transacaoCorrespondente['transaction']['paydayDate'] : null;
                        $balanco->status = $transacaoCorrespondente['transaction']['status'] ?? '';
                        $balanco->value = $transacaoCorrespondente['transaction']['value'] ?? 0;
                        $balanco->tipo = 'PT';
                        $balanco->save();
                    }
                }

                // Remover transação processada
                unset($transacoesPorGalaxyPayId[$parcelaGalaxyPayId]);

            } else {

                $contrato = Contrato::with('cliente')->find($contratoId);

                $balanco = new Balanco;
                $balanco->contrato_id = $contratoId;
                $balanco->parcela_id = $parcela->id;
                $balanco->nparcela = $parcela->nparcela;
                $balanco->cpf = isset($contrato->cliente->cpfcnpj) ? $contrato->cliente->cpfcnp : '';
                $balanco->data_vencimento = $parcela->data_vencimento;
                $balanco->data_pagamento = $parcela->data_pagamento;
                $balanco->data_baixa = $parcela->data_baixa;
                $balanco->valor = $parcela->valor;
                $balanco->situacao = $parcela->status;
                $balanco->galaxPayId = $parcela->galaxPayId;
                $balanco->cgalaxPayId = $parcela->cgalaxPayId;
                $balanco->payday = null;
                $balanco->paydayDate = null;
                $balanco->status = '';
                $balanco->value = 0;
                $balanco->tipo = 'PS';
                $balanco->save();

                $balance['parcelas_sem_transacao'][] = [
                    'id' => $parcela->id,
                    'galaxPayId' => $parcela->galaxPayId,
                    'data_vencimento' => $parcela->data_vencimento,
                    'data_pagamento' => $parcela->data_pagamento,
                    'data_baixa' => $parcela->data_baixa,
                    'nparcela' => $parcela->nparcela,
                    'valor' => $parcela->valor,
                    'status' => $parcela->status,
                ];
                $balance['estatisticas']['parcelas_sem_transacao']++;
            }
        }

        // Processar transações restantes
        foreach ($transacoesPorGalaxyPayId as $galaxPayId => $transacao) {

            $parcela = Parcela::select('id', 'galaxPayId', 'nparcela', 'data_vencimento', 'data_pagamento', 'data_baixa', 'valor', 'status')
                ->where('contrato_id', '=', $contratoId)
                ->where('data_vencimento', '=', $transacao['transaction']['payday'])
                ->first();

            $balanco = new Balanco;
            $balanco->contrato_id = $contratoId;

            $balanco->parcela_id = isset($parcela->id) ? $parcela->id : 0;
            $balanco->nparcela = isset($parcela->nparcela) ? $parcela->nparcela : 0;
            $balanco->data_vencimento = isset($parcela->data_vencimento) ? $parcela->data_vencimento : null;
            $balanco->data_pagamento = isset($parcela->data_pagamento) ? $parcela->data_pagamento : null;
            $balanco->data_baixa = isset($parcela->data_baixa) ? $parcela->data_baixa : null;
            $balanco->valor = isset($parcela->valor) ? $parcela->valor : 0;
            $balanco->situacao = isset($parcela->status) ? $parcela->status : '';

            if (! isset($parcela->id)) {
                if ((isset($transacaoCorrespondente['transaction']['chargeMyId'])) and (substr_count($transacaoCorrespondente['transaction']['chargeMyId'], '#') == 2)) {
                    [$contrato_id_ts, $parcela_id_ts, $hash] = explode('#', $transacaoCorrespondente['transaction']['chargeMyId']);

                    if (($contrato_id_ts != $contratoId) or ($parcela_id_ts != $transacaoCorrespondente['transaction']['chargeGalaxPayId'])) {
                        $parcela = Parcela::select('id', 'galaxPayId', 'cgalaxPayId', 'nparcela', 'data_vencimento', 'data_pagamento', 'data_baixa', 'valor', 'status')
                            ->where('contrato_id', '=', $contrato_id_ts)
                            ->where('id', '=', $parcela_id_ts)
                            ->first();
                        if (isset($parcela->id)) {
                            $balanco->galaxPayId_ts = $parcela->galaxPayId;
                            $balanco->cgalaxPayId_ts = $parcela->cgalaxPayId;
                            $balanco->contrato_ts = $contrato_id_ts;
                            $balanco->parcela_ts = $parcela_id_ts;
                            $balanco->situacao_ts = $parcela->status;
                        }
                    }
                }
            }

            $balanco->cpf = $transacaoCorrespondente['customer']['document'] ?? '';
            $balanco->galaxPayId = $transacaoCorrespondente['transaction']['galaxPayId'] ?? 0;
            $balanco->cgalaxPayId = $transacaoCorrespondente['transaction']['chargeGalaxPayId'] ?? 0;
            $balanco->payday = $transacaoCorrespondente['transaction']['payday'] ?? null;
            $balanco->paydayDate = isset($transacaoCorrespondente['transaction']['paydayDate']) ? $transacaoCorrespondente['transaction']['paydayDate'] : null;
            $balanco->status = $transacaoCorrespondente['transaction']['status'] ?? '';
            $balanco->value = $transacaoCorrespondente['transaction']['value'] ?? 0;
            $balanco->tipo = 'TS';
            $balanco->save();

            $data_baixa = null;

            if (($transacao['transaction']['status'] == 'cancel') and (Cas::temData($transacao['transaction']['statusDate']))) {
                $data_baixa = substr($transacao['transaction']['statusDate'], 0, 10);
            }

            $balance['transacoes_sem_parcela'][] = [
                'galaxPayId' => $transacao['transaction']['galaxPayId'] ?? null,
                'mainPaymentMethodId' => $transacao['transaction']['mainPaymentMethodId'] ?? null,
                'payday' => $transacao['transaction']['payday'] ?? null,
                'paydayDate' => $transacao['transaction']['paydayDate'] ?? null,
                'installment' => $transacao['transaction']['installment'] ?? 0,
                'data_baixa' => $data_baixa,
                'value' => $transacao['transaction']['value'] ?? null,
                'status' => $transacao['transaction']['status'] ?? null,
                'parcela' => $parcela ?? '',
                'response' => $transacao,
            ];
            $balance['estatisticas']['transacoes_sem_parcela']++;
        }

        $contrato = Contrato::find($contratoId);
        if (isset($contrato->id)) {
            $contrato->balanco = 'S';
            $contrato->save();
        }

        return $balance;
    }

    public static function balancoContrato($contrato_id, $cpf)
    {

        $query = 'documents='.$cpf.'&startAt=0&limit=1';
        $customer = CelCash::getCustomers($query);

        if (isset($customer->Customers[0]->galaxPayId)) {

            $resultado = CelCash::listarTransacoesPorData($customer->Customers[0]->galaxPayId, 100);

            if ($resultado->statcode == 200) {
                $dadosExtraidos = CelCash::extrairDadosTransacoes($resultado->response);
                $balanco = CelCash::balancearDadosTransacoesParcelas($contrato_id, $dadosExtraidos);
            }
        }
    }

    public static function buscarMensagem($statcode, $response)
    {
        $mensagem = '';

        switch ($statcode) {
            case 200:
                $mensagem = 'Sucesso';

                if (isset($response->Charge->Transactions[0]->statusDescription)) {
                    $mensagem = $response->Charge->Transactions[0]->statusDescription;
                }
                if (isset($response->Subscription->Transactions[0]->statusDescription)) {
                    $mensagem = $response->Subscription->Transactions[0]->statusDescription;
                }
                break;
            case 400:
                $mensagem = '';

                if (isset($response->error->details)) {
                    // Log::info("errors", ['response_error-'	=> $response->error->details  ]);
                    $mensagem = 'Informe os seguintes campos para continuar: ';
                    $virgula = '';

                    foreach ($response->error->details as $field => $messages) {
                        switch ($field) {
                            case 'documents':
                                $field = 'CPF';
                                break;
                            case 'emails':
                            case 'Customer.emails':
                                $field = 'Email';
                                break;
                            case 'zipCode':
                                $field = 'CEP';
                                break;
                            case 'street':
                                $field = 'Rua';
                                break;
                            case 'number':
                                $field = 'Número da rua';
                                break;
                            case 'neighborhood':
                                $field = 'Bairro';
                                break;
                            case 'city':
                                $field = 'Cidade';
                                break;
                            case 'state':
                                $field = 'Estado';
                                break;
                        }

                        $mensagem .= $virgula."{$field}";
                        $virgula = ', ';
                    }
                }

                if (isset($response->error->message) and $mensagem == '') {
                    $mensagem = $response->error->message;

                    return $mensagem;
                }
                break;
            case 401:
                $mensagem = 'Falha ao autenticar.';
                break;
            case 403:
                $mensagem = 'Erros de validação de segurança.';
                break;
            case 422:
                $mensagem = 'Erro de validação na GalaxPay.';

                if (isset($response->error->details)) {
                    $mensagem = 'Informe os seguintes campos para continuar: ';
                    $virgula = '';
                    foreach ($response->error->details as $field => $messages) {
                        $mensagem .= $virgula."{$field}";
                        $virgula = ', ';
                    }
                }

                if (isset($response->error->message) && $mensagem == 'Erro de validação na GalaxPay.') {
                    $mensagem = $response->error->message;
                }

                Log::warning('GalaxPay 422', ['response' => $response]);
                break;
            case 500:
                $mensagem = 'Erro interno na GalaxPay.';
                if (isset($response->error->message)) {
                    $mensagem = $response->error->message;
                }
                Log::error('GalaxPay 500', ['response' => $response]);
                break;
        }

        if ($mensagem == '') {
            Log::warning('GalaxPay statcode não mapeado', ['statcode' => $statcode, 'response' => $response]);
            $mensagem = "Erro não identificado. (HTTP $statcode)";
        }

        return $mensagem;
    }
}
