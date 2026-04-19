<?php

namespace App\Services\Cas;

use App\Helpers\Cas;
use App\Helpers\CelCash;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Parcela;
use App\Models\TransacaoData;
use App\Models\TransacaoDivergencia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

class TransacaoService
{
    public static function contarResultadosAtualizados($atualizados)
    {
        $metricas = [
            'parcelas' => 0,
            'divergencias' => 0,
        ];

        if (! is_array($atualizados) || empty($atualizados)) {
            return $metricas;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($atualizados));
        foreach ($iterator as $item) {
            if ($item instanceof Parcela) {
                $metricas['parcelas']++;
            }
            if ($item instanceof TransacaoDivergencia) {
                $metricas['divergencias']++;
            }
        }

        return $metricas;
    }

    public static function obterComo($transaction)
    {
        $achar_como = '';

        if ((isset($transaction->chargeMyId)) and (Cas::nulltoSpace($transaction->chargeMyId) != '')) {
            $achar_como = 'chargeMyId';
        } else {
            if ((isset($transaction->chargeGalaxPayId)) and (Cas::nulltoSpace($transaction->chargeGalaxPayId) != '')) {
                $achar_como = 'chargeGalaxPayId';
            } else {
                $achar_como = 'galaxPayId';
            }
        }

        return $achar_como;
    }

    public static function obterParcelaComo($transaction, $achar_como)
    {
        $parcela = new stdClass;
        $parcela->id = 0;

        switch ($achar_como) {
            case 'chargeMyId':
                $parcela = Cas::obterParcelaMyId($transaction);
                break;
            case 'chargeGalaxPayId':
                $parcela = Cas::obterParcelachargeMyId($transaction);
                break;
            case 'galaxPayId':
                $parcela = Cas::obterParcelagalaxPayId($transaction);
                break;
        }

        return $parcela;
    }

    public static function obterParcelaMyId($transaction)
    {

        if (substr_count($transaction->chargeMyId, '#') == 2) {
            [$contrato_id, $parcela_id, $aleatorio] = explode('#', $transaction->chargeMyId);
            $parcela = DB::table('parcelas as p')
                ->join('contratos as c', 'c.id', '=', 'p.contrato_id')
                ->where('c.id', $contrato_id)
                ->where('p.id', $parcela_id)
                ->select('p.id', 'p.status', 'p.data_vencimento', 'p.data_pagamento', 'p.data_baixa', 'p.contrato_id')
                ->first();

        }

        if (! isset($parcela->id)) {
            Log::info('obterParcelaMyId', ['contrato_id' => $contrato_id, 'parcela_id' => $parcela_id]);
            $parcela = DB::table('parcelas')
                ->where('cgalaxPayId', $transaction->chargeGalaxPayId)
                ->where('galaxPayId', $transaction->galaxPayId)
                ->select('id', 'status', 'data_vencimento', 'data_pagamento', 'data_baixa', 'contrato_id')
                ->first();
        }

        if (! isset($parcela->id)) {
            Log::info('obterParcelaMyId', ['cgalaxPayId' => $transaction->chargeGalaxPayId, 'galaxPayId' => $transaction->galaxPayId]);
            $parcela = new stdClass;
            $parcela->id = 0;
        }

        return $parcela;

        // $transaction->chargeMyId
    }

    public static function obterParcelachargeMyId($transaction)
    {
        // $transaction->chargeGalaxPayId

        $parcela = DB::table('parcelas')
            ->where('cgalaxPayId', $transaction->chargeGalaxPayId)
            ->where('galaxPayId', $transaction->galaxPayId)
            ->select('id', 'status', 'data_vencimento', 'data_pagamento', 'data_baixa', 'contrato_id')
            ->first();

        if (! isset($parcela->id)) {
            Log::info('obterParcelachargeMyId', ['cgalaxPayId' => $transaction->chargeGalaxPayId, 'galaxPayId' => $transaction->galaxPayId]);
            $parcela = new stdClass;
            $parcela->id = 0;
        }

        return $parcela;
    }

    public static function obterParcelagalaxPayId($transaction)
    {
        // $transaction->galaxPayId, $transaction->subscriptionGalaxPayId

        $parcela = DB::table('parcelas as p')
            ->join('contratos as c', 'c.id', '=', 'p.contrato_id')
            ->where('c.galaxPayId', $transaction->subscriptionGalaxPayId)
            ->where('p.galaxPayId', $transaction->galaxPayId)
            ->select('p.id', 'p.status', 'p.data_vencimento', 'p.data_pagamento', 'data_baixa', 'p.contrato_id')
            ->first();

        if (! isset($parcela->id)) {
            Log::info('obterParcelagalaxPayId', ['galaxPayId' => $transaction->subscriptionGalaxPayId, 'galaxPayId' => $transaction->galaxPayId]);
            $parcela = new stdClass;
            $parcela->id = 0;
        }

        return $parcela;

    }

    public static function atualizarCriarParcela($transaction, $parcela, $transacao_data_id, $atualizar = 'N')
    {
        $transctions = [];
        $achar_como = Cas::obterComo($transaction);

        $contextoLog = [
            'achar_como' => $achar_como,
            'transaction_status' => $transaction->status ?? null,
            'transaction_payday' => $transaction->payday ?? null,
            'transaction_paydayDate' => isset($transaction->paydayDate) ? substr($transaction->paydayDate, 0, 10) : null,
            'transaction_statusDate' => isset($transaction->statusDate) ? substr($transaction->statusDate, 0, 10) : null,
            'galaxPayId' => $transaction->galaxPayId ?? null,
            'chargeGalaxPayId' => $transaction->chargeGalaxPayId ?? null,
            'chargeMyId' => $transaction->chargeMyId ?? null,
        ];

        if ($parcela->id > 0) {
            if ($parcela->status != $transaction->status) {
                switch ($transaction->status) {
                    case 'captured':
                    case 'payedBoleto':
                    case 'payExternal':
                    case 'payedPix':
                        if ($parcela->data_pagamento != substr($transaction->paydayDate, 0, 10)) {
                            $aparcela = Parcela::find($parcela->id);

                            if (isset($aparcela->id)) {
                                Log::info('pagar', ['parcela_id' => $parcela->id]);

                                if ($atualizar === 'S') {
                                    $antes = [
                                        'status' => $aparcela->status,
                                        'data_pagamento' => $aparcela->data_pagamento,
                                        'data_baixa' => $aparcela->data_baixa,
                                    ];

                                    $aparcela->status = $transaction->status;
                                    $aparcela->data_pagamento = substr($transaction->paydayDate, 0, 10);
                                    $aparcela->statusDate = $transaction->statusDate;
                                    $aparcela->statusDescription = $transaction->statusDescription;
                                    $aparcela->additionalInfo = $transaction->additionalInfo;
                                    $aparcela->galaxPayId = $transaction->galaxPayId;

                                    if (isset($transaction->chargeGalaxPayId)) {
                                        $aparcela->cgalaxPayId = $transaction->chargeGalaxPayId;
                                    }

                                    if (isset($transaction->payedOutsideGalaxPay)) {
                                        $aparcela->payedOutsideGalaxPay = $transaction->payedOutsideGalaxPay;
                                    } else {
                                        $aparcela->payedOutsideGalaxPay = false;
                                    }
                                    $aparcela->save();
                                    Log::info('celcash.sync.parcela_atualizada', $contextoLog + [
                                        'acao' => 'pagar',
                                        'parcela_id' => $aparcela->id,
                                        'contrato_id' => $aparcela->contrato_id,
                                        'antes' => $antes,
                                        'depois' => [
                                            'status' => $aparcela->status,
                                            'data_pagamento' => $aparcela->data_pagamento,
                                            'data_baixa' => $aparcela->data_baixa,
                                        ],
                                    ]);
                                    $transctions[] = $aparcela;
                                } else {
                                    $divergencia = new TransacaoDivergencia;
                                    $divergencia->transacao_data_id = $transacao_data_id;
                                    $divergencia->parcela_id = $parcela->id;
                                    $divergencia->contrato_id = $parcela->contrato_id;
                                    $divergencia->galaxPayId = $transaction->galaxPayId;
                                    if (isset($transaction->chargeGalaxPayId)) {
                                        $divergencia->cgalaxPayId = $transaction->chargeGalaxPayId;
                                    }
                                    $divergencia->status = $transaction->status;
                                    $divergencia->value = ($transaction->value / 100);
                                    $divergencia->payday = $transaction->payday;
                                    $divergencia->statusDate = $transaction->statusDate;
                                    $divergencia->statusDescription = $transaction->statusDescription;
                                    $divergencia->additionalInfo = $transaction->additionalInfo;
                                    $divergencia->data_pagamento = substr($transaction->paydayDate, 0, 10);
                                    $divergencia->data_baixa = null;
                                    $divergencia->situacao = 'Atualizar Pagamento CRM';
                                    $divergencia->transacao = null;
                                    $divergencia->save();
                                    Log::info('celcash.sync.divergencia_criada', $contextoLog + [
                                        'situacao' => $divergencia->situacao,
                                        'divergencia_id' => $divergencia->id,
                                        'parcela_id' => $divergencia->parcela_id,
                                        'contrato_id' => $divergencia->contrato_id,
                                    ]);
                                    $transctions[] = $divergencia;
                                }
                            }
                        }
                        break;
                    case 'cancelByContract':
                    case 'cancel':
                        if ($parcela->data_baixa != substr($transaction->statusDate, 0, 10)) {
                            $aparcela = Parcela::where('contrato_id', '=', $parcela->contrato_id)
                                ->where('data_vencimento', '=', $transaction->payday)
                                ->where('id', '<>', $parcela->id)
                                ->first();
                            if (! isset($aparcela->id)) {
                                $aparcela = Parcela::find($parcela->id);

                                if (isset($aparcela->id)) {
                                    $permite = 'N';
                                    $document = '';
                                    $customerGalaxPayId = 0;

                                    if (isset($transaction->Subscription)) {
                                        if (isset($transaction->Subscription->Customer)) {
                                            $document = $transaction->Subscription->Customer->document;
                                            $customerGalaxPayId = $transaction->Subscription->Customer->galaxPayId;
                                        }
                                    } else {
                                        if (isset($transaction->Charge)) {
                                            if (isset($transaction->Charge->Customer)) {
                                                $document = $transaction->Charge->Customer->document;
                                                $customerGalaxPayId = $transaction->Charge->Customer->galaxPayId;
                                            }
                                        }
                                    }
                                    try {
                                        $resultado = CelCash::listarTransacoesVencimento(
                                            $transaction->payday,
                                            $transaction->payday,
                                            $customerGalaxPayId
                                        );
                                        if ((isset($resultado->totalQtdFoundInPage)) and ($resultado->totalQtdFoundInPage > 0)) {
                                            foreach ($resultado->Transactions as $ctransaction) {
                                                if (($transaction->galaxPayId == $ctransaction->galaxPayId) and ($transaction->status == $ctransaction->status)) {
                                                    $permite = 'S';
                                                }
                                            }
                                        }
                                    } catch (\Exception $e) {
                                        Log::error('Erro no processamento de listarTransacoesVencimento', [
                                            'payday' => $transaction->payday,
                                            'customerGalaxPayId' => $customerGalaxPayId,
                                        ]);
                                    }
                                    if ($permite == 'S') {
                                        Log::info('cancelar', ['parcela_id' => $parcela->id]);
                                        if ($atualizar === 'S') {
                                            $antes = [
                                                'status' => $aparcela->status,
                                                'data_pagamento' => $aparcela->data_pagamento,
                                                'data_baixa' => $aparcela->data_baixa,
                                            ];

                                            $aparcela->status = $transaction->status;
                                            $aparcela->data_baixa = substr($transaction->statusDate, 0, 10);
                                            $aparcela->statusDate = $transaction->statusDate;
                                            $aparcela->statusDescription = $transaction->statusDescription;
                                            $aparcela->save();
                                            Log::info('celcash.sync.parcela_atualizada', $contextoLog + [
                                                'acao' => 'baixar',
                                                'parcela_id' => $aparcela->id,
                                                'contrato_id' => $aparcela->contrato_id,
                                                'antes' => $antes,
                                                'depois' => [
                                                    'status' => $aparcela->status,
                                                    'data_pagamento' => $aparcela->data_pagamento,
                                                    'data_baixa' => $aparcela->data_baixa,
                                                ],
                                            ]);
                                            $transctions[] = $aparcela;
                                        } else {
                                            $divergencia = new TransacaoDivergencia;
                                            $divergencia->transacao_data_id = $transacao_data_id;
                                            $divergencia->parcela_id = $parcela->id;
                                            $divergencia->contrato_id = $parcela->contrato_id;
                                            $divergencia->galaxPayId = $transaction->galaxPayId;
                                            if (isset($transaction->chargeGalaxPayId)) {
                                                $divergencia->cgalaxPayId = $transaction->chargeGalaxPayId;
                                            }
                                            $divergencia->status = $transaction->status;
                                            $divergencia->value = ($transaction->value / 100);
                                            $divergencia->payday = $transaction->payday;
                                            $divergencia->statusDate = $transaction->statusDate;
                                            $divergencia->statusDescription = $transaction->statusDescription;
                                            $divergencia->additionalInfo = $transaction->additionalInfo;
                                            $divergencia->data_pagamento = null;
                                            $divergencia->data_baixa = substr($transaction->statusDate, 0, 10);
                                            $divergencia->situacao = 'Atualizar Baixa CRM';
                                            $divergencia->transacao = null;
                                            $divergencia->save();
                                            Log::info('celcash.sync.divergencia_criada', $contextoLog + [
                                                'situacao' => $divergencia->situacao,
                                                'divergencia_id' => $divergencia->id,
                                                'parcela_id' => $divergencia->parcela_id,
                                                'contrato_id' => $divergencia->contrato_id,
                                            ]);
                                            $transctions[] = $divergencia;
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    default:
                        Cas::transacaoNencontrada($transaction);

                        return;
                }
            }
        } else {
            $document = '';
            $customerGalaxPayId = 0;
            if (isset($transaction->Subscription)) {
                if (isset($transaction->Subscription->Customer)) {
                    $document = $transaction->Subscription->Customer->document;
                    $customerGalaxPayId = $transaction->Subscription->Customer->galaxPayId;
                } else {
                    Cas::transacaoNencontrada($transaction);
                }
            } else {
                if (isset($transaction->Charge)) {
                    if (isset($transaction->Charge->Customer)) {
                        $document = $transaction->Charge->Customer->document;
                        $customerGalaxPayId = $transaction->Charge->Customer->galaxPayId;
                    } else {
                        Cas::transacaoNencontrada($transaction);
                    }
                } else {
                    Cas::transacaoNencontrada($transaction);
                }
            }
            if ($document != '') {
                $document = str_pad($document, 11, '0', STR_PAD_LEFT);
                $cliente = Cliente::where('cpfcnpj', '=', $document)->first();

                if (isset($cliente->id)) {

                    // Log::info("cliente", ['cliente_id' => $cliente->id ]);
                    $contrato = Contrato::where('cliente_id', '=', $cliente->id)
                        ->whereIn('status', ['active', 'waitingPayment'])
                        ->first();
                    if (! isset($contrato->id)) {
                        $contrato = Contrato::where('cliente_id', '=', $cliente->id)
                            ->whereNotIn('status', ['active', 'waitingPayment'])
                            ->orderBy('updated_at', 'desc')
                            ->first();
                    }
                    // Log::info("contrato", ['contrato' => $contrato ]);
                    if (isset($contrato->id)) {
                        $parcela = Parcela::where('contrato_id', '=', $contrato->id)
                            ->where('data_vencimento', '=', $transaction->payday)
                            ->first();
                        if (! isset($parcela->id)) {
                            Log::info('nova parcela', ['transaction' => $transaction]);

                            if ($atualizar === 'S') {
                                $parcela = new Parcela;
                                $parcela->contrato_id = $contrato->id;
                                $parcela->data_vencimento = $transaction->payday;
                                $parcela->data_pagamento = $transaction->paydayDate ? substr($transaction->paydayDate, 0, 10) : null;

                                $value = ($transaction->value / 100);
                                if (isset($transaction->fee)) {
                                    $parcela->taxa = ($transaction->fee / 100);
                                }
                                $parcela->valor = $contrato->valor;
                                if ($transaction->installment != 1) {
                                    if ($contrato->valor > $value) {
                                        $parcela->desconto = $contrato->valor - $value;
                                    }
                                    if ($contrato->valor < $value) {
                                        $parcela->juros = $value - $contrato->valor;
                                    }
                                }
                                $parcela->valor_pago = $value;
                                $parcela->nparcela = $transaction->installment;
                                $parcela->galaxPayId = $transaction->galaxPayId;
                                if (isset($transaction->chargeGalaxPayId)) {
                                    $parcela->cgalaxPayId = $transaction->chargeGalaxPayId;
                                }
                                $parcela->status = Cas::nulltoSpace($transaction->status);
                                $parcela->statusDescription = Cas::nulltoSpace($transaction->statusDescription);
                                if ((isset($transaction->statusDate)) and (Cas::temData($transaction->statusDate))) {
                                    $parcela->statusDate = $transaction->statusDate;
                                }
                                if ($parcela->status != '') {
                                    if (($parcela->status == 'cancel') and (Cas::temData($transaction->statusDate))) {
                                        $parcela->data_baixa = substr($transaction->statusDate, 0, 10);
                                    }
                                }
                                $parcela->additionalInfo = Cas::nulltoSpace($transaction->additionalInfo);
                                if (isset($transaction->subscriptionMyId)) {
                                    $parcela->subscriptionMyId = Cas::nulltoSpace($transaction->subscriptionMyId);
                                } else {
                                    $parcela->subscriptionMyId = '';
                                }
                                if (isset($transaction->payedOutsideGalaxPay)) {
                                    $parcela->payedOutsideGalaxPay = $transaction->payedOutsideGalaxPay;
                                } else {
                                    $parcela->payedOutsideGalaxPay = false;
                                }
                                if ((isset($transaction->datetimeLastSentToOperator)) and (Cas::temData($transaction->datetimeLastSentToOperator))) {
                                    $parcela->datetimeLastSentToOperator = $transaction->datetimeLastSentToOperator;
                                }
                                if (isset($transaction->tid)) {
                                    $parcela->tid = $transaction->tid;
                                }
                                if (isset($transaction->authorizationCode)) {
                                    $parcela->authorizationCode = $transaction->authorizationCode;
                                }
                                if (isset($transaction->cardOperatorId)) {
                                    $parcela->cardOperatorId = $transaction->cardOperatorId;
                                }
                                if ((isset($transaction->ConciliationOccurrences)) and (is_array($transaction->ConciliationOccurrences))) {
                                    $parcela->conciliationOccurrences = json_encode($transaction->ConciliationOccurrences);
                                }
                                if (isset($transaction->CreditCard)) {
                                    $parcela->creditCard = json_encode($transaction->CreditCard);
                                }
                                if (isset($transaction->reasonDenied)) {
                                    $parcela->reasonDenied = $transaction->reasonDenied;
                                }
                                if (isset($transaction->Boleto)) {
                                    if (isset($transaction->Boleto->pdf)) {
                                        $parcela->boletopdf = Cas::nulltoSpace($transaction->Boleto->pdf);
                                    } else {
                                        $parcela->boletopdf = '';
                                    }
                                    if (isset($transaction->Boleto->bankLine)) {
                                        $parcela->boletobankLine = Cas::nulltoSpace($transaction->Boleto->bankLine);
                                    } else {
                                        $parcela->boletobankLine = '';
                                    }
                                    if (isset($transaction->Boleto->bankNumber)) {
                                        $parcela->boletobankNumber = Cas::nulltoSpace($transaction->Boleto->bankNumber);
                                    } else {
                                        $parcela->boletobankNumber = 0;
                                    }
                                    if (isset($transaction->Boleto->barCode)) {
                                        $parcela->boletobarCode = Cas::nulltoSpace($transaction->Boleto->barCode);
                                    } else {
                                        $parcela->boletobarCode = '';
                                    }
                                    if (isset($transaction->Boleto->bankEmissor)) {
                                        $parcela->boletobankEmissor = Cas::nulltoSpace($transaction->Boleto->bankEmissor);
                                    } else {
                                        $parcela->boletobankEmissor = '';
                                    }
                                    if (isset($transaction->Boleto->bankAgency)) {
                                        $parcela->boletobankAgency = Cas::nulltoSpace($transaction->Boleto->bankAgency);
                                    } else {
                                        $parcela->boletobankAgency = '';
                                    }
                                    if (isset($transaction->Boleto->bankAccount)) {
                                        $parcela->boletobankAccount = Cas::nulltoSpace($transaction->Boleto->bankAccount);
                                    } else {
                                        $parcela->boletobankAccount = '';
                                    }
                                } else {
                                    $parcela->boletopdf = '';
                                    $parcela->boletobankLine = '';
                                    $parcela->boletobankNumber = 0;
                                    $parcela->boletobarCode = '';
                                    $parcela->boletobankEmissor = '';
                                    $parcela->boletobankAgency = '';
                                    $parcela->boletobankAccount = '';
                                }
                                if (isset($transaction->Pix)) {
                                    if (isset($transaction->Pix->reference)) {
                                        $parcela->pixreference = Cas::nulltoSpace($transaction->Pix->reference);
                                    } else {
                                        $parcela->pixreference = '';
                                    }
                                    if (isset($transaction->Pix->qrCode)) {
                                        $parcela->pixqrCode = Cas::nulltoSpace($transaction->Pix->qrCode);
                                    } else {
                                        $parcela->pixqrCode = '';
                                    }
                                    if (isset($transaction->Pix->image)) {
                                        $parcela->piximage = $transaction->Pix->image;
                                    } else {
                                        $parcela->piximage = '';
                                    }
                                    if (isset($transaction->Pix->page)) {
                                        $parcela->pixpage = $transaction->Pix->page;
                                    } else {
                                        $parcela->pixpage = '';
                                    }
                                } else {
                                    $parcela->pixreference = '';
                                    $parcela->pixqrCode = '';
                                    $parcela->piximage = '';
                                    $parcela->pixpage = '';
                                }
                                $parcela->save();
                                $transctions[] = $parcela;
                            } else {
                                $divergencia = new TransacaoDivergencia;
                                $divergencia->transacao_data_id = $transacao_data_id;
                                $divergencia->parcela_id = $parcela->id;
                                $divergencia->contrato_id = $contrato->id;
                                $divergencia->galaxPayId = $transaction->galaxPayId;
                                if (isset($transaction->chargeGalaxPayId)) {
                                    $divergencia->cgalaxPayId = $transaction->chargeGalaxPayId;
                                }
                                $divergencia->status = $transaction->status;
                                $divergencia->statusDate = $transaction->statusDate;
                                $divergencia->statusDescription = $transaction->statusDescription;
                                $divergencia->additionalInfo = $transaction->additionalInfo;
                                if (($transaction->status == 'cancelByContract') or ($transaction->status == 'cancel')) {
                                    $divergencia->data_baixa = substr($transaction->statusDate, 0, 10);
                                    $divergencia->data_pagamento = null;
                                    $divergencia->situacao = 'Inserir Baixa no CRM';
                                } else {
                                    $divergencia->data_pagamento = substr($transaction->paydayDate, 0, 10);
                                    $divergencia->data_baixa = null;
                                    $divergencia->situacao = 'Inserir Pagamento no CRM';
                                }
                                $divergencia->value = ($transaction->value / 100);
                                $divergencia->payday = $transaction->payday;
                                $divergencia->transacao = json_encode($transaction);
                                $divergencia->save();
                                $transctions[] = $divergencia;
                            }
                        } else {

                            switch ($transaction->status) {
                                case 'captured':
                                case 'payedBoleto':
                                case 'payExternal':
                                case 'payedPix':
                                    $data_pagamento = $parcela->data_pagamento;
                                    if ($parcela->data_pagamento != substr($transaction->paydayDate, 0, 10)) {
                                        if ($atualizar === 'S') {
                                            $antes = [
                                                'status' => $parcela->status,
                                                'data_pagamento' => $parcela->data_pagamento,
                                                'data_baixa' => $parcela->data_baixa,
                                            ];

                                            $parcela->status = $transaction->status;
                                            $parcela->data_pagamento = substr($transaction->paydayDate, 0, 10);
                                            $parcela->statusDate = $transaction->statusDate;
                                            $parcela->statusDescription = $transaction->statusDescription;
                                            $parcela->additionalInfo = $transaction->additionalInfo;
                                            $parcela->galaxPayId = $transaction->galaxPayId;

                                            if (isset($transaction->chargeGalaxPayId)) {
                                                $parcela->cgalaxPayId = $transaction->chargeGalaxPayId;
                                            }

                                            if (isset($transaction->payedOutsideGalaxPay)) {
                                                $parcela->payedOutsideGalaxPay = $transaction->payedOutsideGalaxPay;
                                            } else {
                                                $parcela->payedOutsideGalaxPay = false;
                                            }
                                            Log::info('nova_pagar', ['parcela_id' => $parcela->id]);
                                            Log::info('nova_pagar', ['data_pagamento' => $data_pagamento]);
                                            Log::info('nova_pagar', ['paydayDate' => substr($transaction->paydayDate, 0, 10)]);
                                            $transctions[] = $parcela;
                                            $parcela->save();
                                            Log::info('celcash.sync.parcela_atualizada', $contextoLog + [
                                                'acao' => 'pagar',
                                                'parcela_id' => $parcela->id,
                                                'contrato_id' => $parcela->contrato_id,
                                                'antes' => $antes,
                                                'depois' => [
                                                    'status' => $parcela->status,
                                                    'data_pagamento' => $parcela->data_pagamento,
                                                    'data_baixa' => $parcela->data_baixa,
                                                ],
                                            ]);
                                        } else {
                                            $divergencia = new TransacaoDivergencia;
                                            $divergencia->transacao_data_id = $transacao_data_id;
                                            $divergencia->parcela_id = $parcela->id;
                                            $divergencia->contrato_id = $parcela->contrato_id;
                                            $divergencia->galaxPayId = $transaction->galaxPayId;
                                            if (isset($transaction->chargeGalaxPayId)) {
                                                $divergencia->cgalaxPayId = $transaction->chargeGalaxPayId;
                                            }
                                            $divergencia->status = $transaction->status;
                                            $divergencia->value = ($transaction->value / 100);
                                            $divergencia->payday = $transaction->payday;
                                            $divergencia->statusDate = $transaction->statusDate;
                                            $divergencia->statusDescription = $transaction->statusDescription;
                                            $divergencia->additionalInfo = $transaction->additionalInfo;
                                            $divergencia->data_pagamento = substr($transaction->paydayDate, 0, 10);
                                            $divergencia->data_baixa = null;
                                            $divergencia->situacao = 'Atualizar Pagamento CRM';
                                            $divergencia->transacao = null;
                                            $divergencia->save();
                                            Log::info('celcash.sync.divergencia_criada', $contextoLog + [
                                                'situacao' => $divergencia->situacao,
                                                'divergencia_id' => $divergencia->id,
                                                'parcela_id' => $divergencia->parcela_id,
                                                'contrato_id' => $divergencia->contrato_id,
                                            ]);
                                            $transctions[] = $divergencia;
                                        }
                                    }
                                    break;
                                case 'cancelByContract':
                                case 'cancel':
                                    /* aqui é necessário verificar se tem mais de uma cobrança na mesma data de vencimento */
                                    if ($parcela->data_baixa != substr($transaction->statusDate, 0, 10)) {
                                        $permite = 'N';
                                        try {
                                            $resultado = CelCash::listarTransacoesVencimento(
                                                $transaction->payday,
                                                $transaction->payday,
                                                $customerGalaxPayId
                                            );
                                            if ((isset($resultado->totalQtdFoundInPage)) and ($resultado->totalQtdFoundInPage > 0)) {
                                                foreach ($resultado->Transactions as $ctransaction) {
                                                    if (($transaction->galaxPayId == $ctransaction->galaxPayId) and ($transaction->status == $ctransaction->status)) {
                                                        $permite = 'S';
                                                    }
                                                }
                                            }
                                        } catch (\Exception $e) {
                                            Log::error('Erro no processamento de listarTransacoesVencimento', [
                                                'payday' => $transaction->payday,
                                                'customerGalaxPayId' => $customerGalaxPayId,
                                            ]);
                                        }
                                        if ($permite == 'S') {
                                            Log::info('nova_cancelar', ['parcela_id' => $parcela->id]);

                                            if ($atualizar === 'S') {
                                                $antes = [
                                                    'status' => $parcela->status,
                                                    'data_pagamento' => $parcela->data_pagamento,
                                                    'data_baixa' => $parcela->data_baixa,
                                                ];

                                                $parcela->status = $transaction->status;
                                                $parcela->data_baixa = substr($transaction->statusDate, 0, 10);
                                                $parcela->statusDate = $transaction->statusDate;
                                                $parcela->statusDescription = $transaction->statusDescription;
                                                $parcela->save();
                                                $transctions[] = $parcela;
                                                Log::info('celcash.sync.parcela_atualizada', $contextoLog + [
                                                    'acao' => 'baixar',
                                                    'parcela_id' => $parcela->id,
                                                    'contrato_id' => $parcela->contrato_id,
                                                    'antes' => $antes,
                                                    'depois' => [
                                                        'status' => $parcela->status,
                                                        'data_pagamento' => $parcela->data_pagamento,
                                                        'data_baixa' => $parcela->data_baixa,
                                                    ],
                                                ]);
                                            } else {
                                                $divergencia = new TransacaoDivergencia;
                                                $divergencia->transacao_data_id = $transacao_data_id;
                                                $divergencia->parcela_id = $parcela->id;
                                                $divergencia->contrato_id = $parcela->contrato_id;
                                                $divergencia->galaxPayId = $transaction->galaxPayId;
                                                if (isset($transaction->chargeGalaxPayId)) {
                                                    $divergencia->cgalaxPayId = $transaction->chargeGalaxPayId;
                                                }
                                                $divergencia->status = $transaction->status;
                                                $divergencia->value = ($transaction->value / 100);
                                                $divergencia->payday = $transaction->payday;
                                                $divergencia->statusDate = $transaction->statusDate;
                                                $divergencia->statusDescription = $transaction->statusDescription;
                                                $divergencia->additionalInfo = $transaction->additionalInfo;
                                                $divergencia->data_pagamento = null;
                                                $divergencia->data_baixa = substr($transaction->statusDate, 0, 10);
                                                $divergencia->situacao = 'Atualizar Baixa CRM';
                                                $divergencia->transacao = null;
                                                $divergencia->save();
                                                Log::info('celcash.sync.divergencia_criada', $contextoLog + [
                                                    'situacao' => $divergencia->situacao,
                                                    'divergencia_id' => $divergencia->id,
                                                    'parcela_id' => $divergencia->parcela_id,
                                                    'contrato_id' => $divergencia->contrato_id,
                                                ]);
                                                $transctions[] = $divergencia;
                                            }
                                        }
                                    }
                                    break;
                            }
                        }
                    } else {
                        Cas::transacaoNencontrada($transaction);
                    }

                } else {
                    Cas::transacaoNencontrada($transaction);
                }
            }
        }

        return $transctions;
    }

    public static function transacaoNencontrada($transaction)
    {
        Log::warning('celcash.sync.transacao_sem_match', [
            'transaction_status' => $transaction->status ?? null,
            'galaxPayId' => $transaction->galaxPayId ?? null,
            'chargeGalaxPayId' => $transaction->chargeGalaxPayId ?? null,
            'chargeMyId' => $transaction->chargeMyId ?? null,
            'payday' => $transaction->payday ?? null,
        ]);
    }

    public static function atualizarTransaction($transactions, $transacao_data_id, $atualizar = 'N')
    {
        $atualizados = [];
        if (is_array($transactions)) {
            foreach ($transactions as $transaction) {
                $atualizado = Cas::atualizarCriarParcela($transaction, Cas::obterParcelaComo($transaction, Cas::obterComo($transaction)), $transacao_data_id, $atualizar);
                if (count($atualizado) > 0) {
                    $atualizados[] = $atualizado;
                }
            }
        }

        return $atualizados;
    }

    public static function listarTransacoesStatus($payload)
    {

        if (! isset($payload->atualizar)) {
            $payload->atualizar = 'N';
        }

        if ($payload->atualizar === 'N') {
            $transacaoDataIds = TransacaoData::whereBetween('data', [$payload->updateStatusFrom, $payload->updateStatusTo])->pluck('id')->toArray();
            // Segundo: Excluir TransacaoDivergencia relacionadas às TransacaoData do período
            $deletedDivergenciasCount = 0;

            if (! empty($transacaoDataIds)) {
                $deletedDivergenciasCount = TransacaoDivergencia::whereIn('transacao_data_id', $transacaoDataIds)->delete();
            }
            // Terceiro: Excluir as TransacaoData do período
            $deletedTransacaoDataCount = TransacaoData::whereBetween('data', [$payload->updateStatusFrom, $payload->updateStatusTo])->delete();
        }

        $chave = $payload->updateStatusFrom.'#'.$payload->updateStatusTo;
        Cache::forget($chave);

        if (Cache::has($chave)) {
            $payload->start = Cache::get($chave);
        } else {
            $payload->start = 0;
            Cache::forever($chave, $payload->start);
        }

        // Configurações de segurança
        $maxIteracoes = 1000; // Limite máximo de iterações para evitar loop infinito
        $contadorIteracoes = 0;
        $totalProcessados = 0;
        $metricas = [
            'total_transacoes' => 0,
            'parcelas_atualizadas' => 0,
            'divergencias_criadas' => 0,
        ];

        // Inicializar a variável para controle do loop
        $totalQtdFoundInPage = 1; // Começar com valor > 0 para entrar no loop

        // Log de início do processamento
        Log::info('Iniciando processamento de transações', [
            'updateStatusFrom' => $payload->updateStatusFrom,
            'updateStatusTo' => $payload->updateStatusTo,
            'start' => $payload->start,
            'limite' => $payload->limite,
        ]);

        $transacaoData = TransacaoData::firstOrNew(['data' => $payload->updateStatusFrom]);

        if (! $transacaoData->exists) {
            $transacaoData->total = 0;
            $transacaoData->conciliados = 0;
            $transacaoData->divergentes = 0;
        }

        $transacaoData->status = 'processando';
        $transacaoData->save();

        while ($totalQtdFoundInPage > 0 && $contadorIteracoes < $maxIteracoes) {
            $contadorIteracoes++;

            try {
                $resultado = CelCash::listarTransacoesStatus(
                    $payload->updateStatusFrom,
                    $payload->updateStatusTo,
                    $payload->start,
                    $payload->limite
                );

                $totalQtdFoundInPage = 0;

                if (isset($resultado->totalQtdFoundInPage)) {
                    if (isset($resultado->Transactions)) {
                        if (($resultado->totalQtdFoundInPage > 0) && (count($resultado->Transactions) > 0)) {
                            $atualizados = Cas::atualizarTransaction($resultado->Transactions, $transacaoData->id, $payload->atualizar);
                            $metricaPagina = self::contarResultadosAtualizados($atualizados);
                            $metricas['total_transacoes'] += count($resultado->Transactions);
                            $metricas['parcelas_atualizadas'] += $metricaPagina['parcelas'];
                            $metricas['divergencias_criadas'] += $metricaPagina['divergencias'];
                            $totalProcessados += count($resultado->Transactions);
                            if ($payload->atualizar === 'N') {
                                $transacaoData->total += $resultado->totalQtdFoundInPage;
                                $transacaoData->save();
                            }
                            Log::info('celcash.sync.progresso', [
                                'iteracao' => $contadorIteracoes,
                                'start' => $payload->start,
                                'totalQtdFoundInPage' => $resultado->totalQtdFoundInPage,
                                'transactions_count' => count($resultado->Transactions),
                                'parcelas_atualizadas' => $metricaPagina['parcelas'],
                                'divergencias_criadas' => $metricaPagina['divergencias'],
                                'atualizar' => $payload->atualizar,
                            ]);
                        }
                    }

                    $totalQtdFoundInPage = $resultado->totalQtdFoundInPage;

                    $payload->start = $payload->start + $resultado->totalQtdFoundInPage;
                    Cache::forever($chave, $payload->start);
                }

                // Delay para não sobrecarregar o sistema
                usleep(200000); // 200ms

            } catch (\Exception $e) {
                Log::error('Erro no processamento de transações', [
                    'iteracao' => $contadorIteracoes,
                    'start' => $payload->start,
                    'erro' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Em caso de erro, interromper o loop para evitar problemas
                break;
            }
        }

        // Log final do processamento
        if ($contadorIteracoes >= $maxIteracoes) {
            Log::warning('Processamento interrompido por atingir o limite máximo de iterações', [
                'maxIteracoes' => $maxIteracoes,
                'totalProcessados' => $totalProcessados,
                'ultimoStart' => $payload->start,
            ]);
        } else {
            Log::info('Processamento concluído com sucesso', [
                'iteracoes' => $contadorIteracoes,
                'totalProcessados' => $totalProcessados,
                'startFinal' => $payload->start,
                'metricas' => $metricas,
            ]);
        }

        if ($payload->atualizar === 'N') {
            $qtdivergentes = TransacaoDivergencia::where('transacao_data_id', '=', $transacaoData->id)->count();
            $transacaoData->divergentes = $qtdivergentes;
            $transacaoData->conciliados = $transacaoData->total - $qtdivergentes;
            $transacaoData->status = 'finalizado';
            $transacaoData->save();
        }
        Cache::forget($chave);

        return $payload;
    }

    public static function atualizarDivergencia($id)
    {
        $divergencia = TransacaoDivergencia::find($id);

        if (isset($divergencia->id)) {

            switch ($divergencia->situacao) {
                case 'Inserir Baixa no CRM':
                case 'Inserir Pagamento no CRM':

                    $parcela = Parcela::where('contrato_id', '=', $divergencia->contrato_id)
                        ->where('data_vencimento', '=', $divergencia->payday)
                        ->first();

                    if (isset($parcela->id)) {
                    }
                    break;

                case 'Atualizar Pagamento CRM':
                    $parcela = Parcela::find($divergencia->parcela_id);

                    if (isset($parcela->id)) {
                        if (is_null($parcela->data_pagamento)) {
                            $parcela->status = $divergencia->status;
                            $parcela->data_pagamento = $divergencia->data_pagamento;
                            $parcela->data_baixa = null;
                            if ($parcela->save()) {
                                $divergencia->delete();
                                Log::info('celcash.sync.divergencia_aplicada', [
                                    'divergencia_id' => $id,
                                    'situacao' => $divergencia->situacao,
                                    'parcela_id' => $parcela->id,
                                    'contrato_id' => $parcela->contrato_id,
                                ]);

                                return 'Atualizado para pagamento';
                            }
                        } else {
                            $divergencia->delete();
                            Log::info('celcash.sync.divergencia_descartada', [
                                'divergencia_id' => $id,
                                'situacao' => $divergencia->situacao,
                                'parcela_id' => $parcela->id,
                                'contrato_id' => $parcela->contrato_id,
                            ]);

                            return 'Já havia atualizado para pagamento';
                        }
                    }
                    break;
                case 'Atualizar Baixa CRM':

                    $parcela = Parcela::find($divergencia->parcela_id);

                    if (isset($parcela->id)) {
                        if ((is_null($parcela->data_baixa)) and (is_null($parcela->data_pagamento))) {
                            $parcela->status = $divergencia->status;
                            $parcela->data_baixa = $divergencia->data_baixa;
                            if ($parcela->save()) {
                                $divergencia->delete();

                                return 'Atualizado para baixado';
                            }
                        } else {
                            $divergencia->delete();

                            return 'Já havia seido atualizado para baixado';
                        }
                    }
                    break;
            }
        }

        return 'Não foi atualizado a divergencia!';
    }

    public static function cancelar_contrato_parcela()
    {

        $contratos = DB::table('contratos')
            ->whereIn('status', ['canceled', 'stopped', 'closed'])
            ->get();
        foreach ($contratos as $contrato) {
            Cas::cancelar_parcela_beneficiario($contrato->id);
        }

        return true;
    }

    public static function cancelar_parcela_beneficiario($id)
    {

        $contrato = Contrato::find($id);

        if (! isset($contrato->id)) {
            return false;
        }

        if (($contrato->status != 'canceled') and ($contrato->status != 'stopped') and ($contrato->status != 'closed')) {
            return false;
        }

        DB::beginTransaction();

        $parcelas = DB::table('parcelas')
            ->where('contrato_id', '=', $id)
            ->where('data_pagamento', '=', null)
            ->where('data_baixa', '=', null)
            ->update(['status' => 'cancel',
                'data_baixa' => date('Y-m-d'),
                'statusDate' => date('Y-m-d H:m:s'),
            ]);
        $beneficiarios = DB::table('beneficiarios')
            ->where('contrato_id', $id)
            ->where('ativo', '=', 1)
            ->update(['ativo' => 0,
                'vigencia_fim' => date('Y-m-d'),
                'desc_status' => 'INATIVO',
            ]);
        DB::commit();

        return true;
    }

    public static function obterQuemFez($id)
    {

        $sql = "SELECT id, name as nome FROM users where id=$id";
        $users = DB::select($sql);

        if ((isset($users[0]->nome)) and ($users[0]->nome != '')) {
            return $users[0]->nome;
        }

        return '';

    }
}
