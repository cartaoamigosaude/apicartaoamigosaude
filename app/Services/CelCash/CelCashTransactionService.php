<?php

namespace App\Services\CelCash;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use App\Helpers\Cas;
use App\Helpers\CelCash;
use Carbon\Carbon;
use DB;
use stdClass;
use App\Jobs\CelCashParcelaAvulsaJob;

class CelCashTransactionService
{

	public static function getTransaction($query,$galaxid=1)
    {
		
		$token                          = CelCash::Token($galaxid);

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/transactions?$query";
		$statcode						= "";
		
        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->get($endpoint);
            $statcode					= $hresponse->status();
            $response 					= $hresponse->object();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        } catch (RequestException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        }

		//Log::info("getTransaction", ['response' => $response ]);

        $retorno                       	= $response;
		$retorno->statcode              = $statcode;
        return $retorno;
		
	}

	public static function alterarTransaction($id,$body, $typeId='galaxPayId')
    {
		
		$token                          = CelCash::Token();

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/transactions/$id/$typeId";
     
        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->put($endpoint, $body);
            $statcode					= $hresponse->status();
            $response 					= $hresponse->object();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        } catch (RequestException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        }

		//Log::info("alterarTransaction", ['response' => $response ]);
	
        $retorno                       	= $response;
		$retorno->statcode              = $statcode;
        return $retorno;
		
	}

	public static function adicionarTransaction($id,$body, $typeId='galaxPayId')
    {
		
		$token                          = CelCash::Token();

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/transactions/$id/$typeId/add";
     
        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->post($endpoint, $body);
            $statcode					= $hresponse->status();
            $response 					= $hresponse->object();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        } catch (RequestException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        }

		//Log::info("adicionarTransaction", ['response' => $response ]);

        $retorno                       	= $response;
		$retorno->statcode              = $statcode;
        return $retorno;
		
	}

	public static function cancelTransaction($id,$galaxid=1,$typeId="galaxPayId")
    {
		
		$token                          = CelCash::Token($galaxid);

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/transactions/$id/$typeId";
     
        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->delete($endpoint);
            $statcode					= $hresponse->status();
            $response 					= $hresponse->object();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        } catch (RequestException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->mensagem 			= $e;
            return $retorno;
        }

		$response->galaxPayId			= $id;
		$response->typeId				= $typeId;
		$response->endpoint				= $endpoint;
		
		//Log::info("cancelTransaction", ['response' => $response ]);

        $retorno                       	= $response;
		$retorno->statcode              = $statcode;
        return $retorno;
		
	}

	public static function updateParcelaWithChargeTransaction($celcash,$response="")
	{
		//Log::info("updateParcelaWithChargeTransaction", ['celcash' => $celcash ]);
		//Log::info("updateParcelaWithChargeTransaction", ['response' => $response ]);
		
		$retorno 						 			= new stdClass();
        $retorno->ok                     			= "N";
		
		if ((!isset($celcash->chargeMyId)) and (!isset($celcash->myId)))
		{
			 $retorno->mensagem          			= "Dados da cobrança não encontrada";
			 return $retorno;
		}
		
		if (isset($celcash->myId))
		{
			$celcash->chargeMyId					= $celcash->myId;
		}
		
		
		if (substr_count($celcash->chargeMyId, '#') <> 2) 
		{
			 $retorno->mensagem          			= "Dados da cobrança não encontrado";
			 return $retorno;
		}
		/*
		if (Cas::nulltoSpace($celcash->status) == "canceled")
		{
			 $retorno->mensagem          			= "Cancelado pelo Cell Cash. Porém nao pode aqui.";
			 return $retorno;
		}
		*/
		list($contrato_id,$parcela_id,$hash)        = explode("#",$celcash->chargeMyId);
		
		$parcela 				                    = \App\Models\Parcela::find($parcela_id);
                                                                  
		if (!isset($parcela->id))
		{
			 $retorno->mensagem          			= "Dados da parcela não encontrado";
			 return $retorno;
		}

		if (isset($celcash->payday))
		{			
			$parcela->data_vencimento		        = $celcash->payday;
		}
		
		if ((!isset($celcash->paydayDate)) and (isset($response->Transaction->paydayDate)))
		{
			$celcash->paydayDate					= $response->Transaction->paydayDate;
		}
			 
        if (isset($celcash->paydayDate))
        {
            if (Cas::temData($celcash->paydayDate))
            {
                $parcela->data_pagamento		    = substr($celcash->paydayDate,0,10);
            }
        }
        
        $value                                      = ($celcash->value / 100);
        if (isset($celcash->fee))
        {
            $parcela->taxa					        = ($celcash->fee / 100);
        }
		
        $parcela->valor_pago			            = $value;
		
		if ($parcela->galaxPayId == 0)
		{
			$parcela->galaxPayId			        = $celcash->galaxPayId;
		}
		
		$parcela->chargeMyId						= $celcash->chargeMyId;
		if (isset($response->galaxPayId))
		{
			$parcela->cgalaxPayId			        = $response->galaxPayId;
			$parcela->response 						= json_encode($response);
		}
        $parcela->status				            = Cas::nulltoSpace($celcash->status);
		
		if (isset($celcash->statusDescription))
		{
			$parcela->statusDescription		        = Cas::nulltoSpace($celcash->statusDescription);
		}
		
        if ((isset($celcash->statusDate)) and (Cas::temData($celcash->statusDate)))
        {
            $parcela->statusDate			        = $celcash->statusDate;
        } 
        if ($parcela->status !="")
        {
            if (($parcela->status == 'cancel') and (Cas::temData($celcash->statusDate)))
            {
                $parcela->data_baixa			    =  substr($celcash->statusDate,0,10);
            }
        } 
		if (isset($celcash->additionalInfo))
		{
			$parcela->additionalInfo		        = Cas::nulltoSpace($celcash->additionalInfo);
		}
		if (isset($celcash->subscriptionMyId))
		{
			$parcela->subscriptionMyId		        = Cas::nulltoSpace($celcash->subscriptionMyId);
		} else {
			$parcela->subscriptionMyId		        = "";
		}
		if (isset($celcash->payedOutsideGalaxPay))
		{
			$parcela->payedOutsideGalaxPay	        = $celcash->payedOutsideGalaxPay;
		} else {
			$parcela->payedOutsideGalaxPay	        = false;
		}

        if ((isset($celcash->datetimeLastSentToOperator)) and (Cas::temData($celcash->datetimeLastSentToOperator)))
        {
            $parcela->datetimeLastSentToOperator    = $celcash->datetimeLastSentToOperator;
        }
       
        if (isset($celcash->tid))
        {
            $parcela->tid                           = $celcash->tid;
        }

        if (isset($celcash->authorizationCode))
        {
            $parcela->authorizationCode             = $celcash->authorizationCode;
        }

        if (isset($celcash->cardOperatorId))
        {
            $parcela->cardOperatorId                = $celcash->cardOperatorId;
        }

        if ((isset($celcash->ConciliationOccurrences)) and (is_array($celcash->ConciliationOccurrences)))
        {
            $parcela->conciliationOccurrences        = json_encode($celcash->ConciliationOccurrences);
        }

        if (isset($celcash->CreditCard))
        {
            $parcela->creditCard                    = json_encode($celcash->CreditCard);
        }
		
		if (isset($celcash->reasonDenied))
        {
            $parcela->reasonDenied                  = $celcash->reasonDenied;
        }

        if (isset($celcash->Boleto))
        {
            if (isset($celcash->Boleto->pdf))
            {
                $parcela->boletopdf				    = Cas::nulltoSpace($celcash->Boleto->pdf);
            } else {
                $parcela->boletopdf				    = "";
            }
            if (isset($celcash->Boleto->bankLine))
            {
                $parcela->boletobankLine		    = Cas::nulltoSpace($celcash->Boleto->bankLine);
            } else {
                $parcela->boletobankLine		    = "";
            }
            if (isset($celcash->Boleto->bankNumber))
            {
                $parcela->boletobankNumber		    = Cas::nulltoSpace($celcash->Boleto->bankNumber);
            } else {
                $parcela->boletobankNumber          = 0;
            }
            if (isset($celcash->Boleto->barCode))
            {
                $parcela->boletobarCode			    = Cas::nulltoSpace($celcash->Boleto->barCode);
            } else {
                $parcela->boletobarCode			    = "";
            }
            if (isset($celcash->Boleto->bankEmissor))
            {
                $parcela->boletobankEmissor		    = Cas::nulltoSpace($celcash->Boleto->bankEmissor);
            } else {
                $parcela->boletobankEmissor         = "";
            }
            if (isset($celcash->Boleto->bankAgency))
            {
                $parcela->boletobankAgency		    = Cas::nulltoSpace($celcash->Boleto->bankAgency);
            } else {
                $parcela->boletobankAgency          = "";
            }
            if (isset($celcash->Boleto->bankAccount))
            {
                $parcela->boletobankAccount		    = Cas::nulltoSpace($celcash->Boleto->bankAccount);
            } else {
                $parcela->boletobankAccount         = "";
            }
        } 
		
        if (isset($celcash->Pix))
        {
            if (isset($celcash->Pix->reference))
            {
                $parcela->pixreference			     = Cas::nulltoSpace($celcash->Pix->reference);
            } else {
                $parcela->pixreference			     = "";
            }
            if (isset($celcash->Pix->qrCode))
            {
                $parcela->pixqrCode				     = Cas::nulltoSpace($celcash->Pix->qrCode);
            } else {
                $parcela->pixqrCode				     = "";
            }
            if (isset($celcash->Pix->image))
            {
                $parcela->piximage				     = $celcash->Pix->image;
            } else {
                $parcela->piximage				     = "";
            }
            if (isset($celcash->Pix->page))
            {
                $parcela->pixpage				     = $celcash->Pix->page;
            } else {
                $parcela->pixpage				     = "";
            }
        }
		
		$parcela->save();
		$retorno->ok                     			= "S";
		return $retorno;
	}

	public static function listarTransacoesVencimento($payDayFrom,$payDayTo,$customerGalaxPayId)
    {
      
		$token 					= CelCash::Token();
        
        // Query otimizada com filtros específicos e ordenação
		$query = [
			'startAt' 				=> 0,
			'limit' 				=> 100,
			'customerGalaxPayIds' 	=> $customerGalaxPayId,
			'payDayFrom'			=> $payDayFrom,
			'payDayTo'				=> $payDayTo,
			'status' 				=> 'captured,payedBoleto,payedPix,payExternal,cancelByContract,cancel,notSend',
			'order' 				=> 'payday.asc'  
		];
        
        $queryString 				= http_build_query($query);
        
    	$endpoint = $token->url . "/transactions?$queryString";
		
        $response = Http::withHeaders([
            'Authorization' => "Bearer $token->token",
            'Content-Type' => 'application/json',
        ])->get($endpoint);
        
        $responseData = $response->object();
		
		return $responseData;
    }

	public static function listarTransacoesStatus($updateStatusFrom,$updateStatusTo,$start=0,$limite=100)
    {
      
		$token 					= CelCash::Token();
        
        // Query otimizada com filtros específicos e ordenação
		$query = [
			'startAt' 			=> $start,
			'limit' 			=> $limite,
			'updateStatusFrom'	=> $updateStatusFrom,
			'updateStatusTo'	=> $updateStatusTo,
			'status' 			=> 'captured,payedBoleto,payedPix,payExternal,cancelByContract,cancel,notSend',
			'order' 			=> 'payday.asc'  
		];
        
        $queryString 			= http_build_query($query);
        
    	$endpoint = $token->url . "/transactions?$queryString";
		
        $response = Http::withHeaders([
            'Authorization' => "Bearer $token->token",
            'Content-Type' => 'application/json',
        ])->get($endpoint);
        
        $responseData = $response->object();
		
		return $responseData;
    }

    /**
     * Lista transações por CPF a partir de uma data específica
     * 
     * @param string $cpf CPF do cliente
     * @param string $dataInicio Data de início (formato Y-m-d), padrão 2025-01-01
     * @param int $limite Limite de registros, padrão 100
     * @return object Resposta da API com as transações encontradas
     */
    public static function listarTransacoesPorData($customerGalaxPayIds, $limite = 100)
    {
      
		$token = CelCash::Token();
        
        // Query otimizada com filtros específicos e ordenação
		$query = [
			'startAt' => 0,
			'customerGalaxPayIds' => $customerGalaxPayIds,
			'limit' => $limite,
			'order' => 'createdAt.desc'  // Mais recente primeiro
		];
        
        $queryString = http_build_query($query);
        
       // Log::info('CelCash::listarTransacoesPorData - Query: ' . $queryString);
        
		$endpoint = $token->url . "/transactions?$queryString";
		
        $response = Http::withHeaders([
            'Authorization' => "Bearer $token->token",
            'Content-Type' => 'application/json',
        ])->get($endpoint);
        
        $responseData = $response->json();
        
        //Log::info('CelCash::listarTransacoesPorData - Response: ' . json_encode($responseData));
        
        return (object) [
            'response' => $responseData,
            'statcode' => $response->status()
        ];
    }

    /**
     * Extrai e formata dados específicos das transações
     * 
     * @param array $transacoes Array de transações da API
     * @return array Array com dados formatados
     */
    public static function extrairDadosTransacoes($transacoes)
    {
        $dadosExtraidos = [];
        
        if (!isset($transacoes['Transactions']) || !is_array($transacoes['Transactions'])) {
            return $dadosExtraidos;
        }
        
        foreach ($transacoes['Transactions'] as $transacao) {
            $item = [];
            
            // 1) Campos de Transactions
            $item['transaction'] = [
                'galaxPayId' => $transacao['galaxPayId'] ?? null,
                'chargeGalaxPayId' => $transacao['chargeGalaxPayId'] ?? null,
                'chargeMyId' => $transacao['chargeMyId'] ?? null,
                'value' => $transacao['value'] ?? null,
                'payday' => $transacao['payday'] ?? null,
                'payedOutsideGalaxPay' => $transacao['payedOutsideGalaxPay'] ?? null,
				'mainPaymentMethodId' => $transacao['Subscription']['mainPaymentMethodId'] ?? null,
                'installment' => $transacao['installment'] ?? null,
                'paydayDate' => $transacao['paydayDate'] ?? null,
                'statusDate' => $transacao['statusDate'] ?? null,
                'status' => $transacao['status'] ?? null,
            ];
            
            // 2) Campos de Boleto
            $item['boleto'] = [
                'pdf' => $transacao['Boleto']['pdf'] ?? null,
                'bankLine' => $transacao['Boleto']['bankLine'] ?? null,
                'bankAgency' => $transacao['Boleto']['bankAgency'] ?? null,
                'bankAccount' => $transacao['Boleto']['bankAccount'] ?? null
            ];
            
            // 3) Campos de Charge
            $item['charge'] = [
                'galaxPayId' => $transacao['Charge']['galaxPayId'] ?? null,
                'myId' => $transacao['Charge']['myId'] ?? null,
                'mainPaymentMethodId' => $transacao['Charge']['mainPaymentMethodId'] ?? null,
                'value' => $transacao['Charge']['value'] ?? null,
                'status' => $transacao['Charge']['status'] ?? null
            ];
            
            // 4) Campos de Customer dentro do Charge
            $item['customer'] = [
                'name' => $transacao['Charge']['Customer']['name'] ?? null,
                'document' => $transacao['Charge']['Customer']['document'] ?? null
            ];
            
            $dadosExtraidos[] = $item;
        }
        
        return $dadosExtraidos;
    }

    public static function verificarTransacaoExistente($cpf, $galaxPayId, $dataVencimento, $valor)
    {
		$retorno = new stdClass();
		$retorno->existe = false;
		$retorno->transacao = null;
		$retorno->mensagem = "";
		
		// Busca transação com filtros otimizados
		$resultado = CelCash::getTransactionForDuplicateCheck($cpf, $galaxPayId, $dataVencimento, $valor);
		
		if ($resultado->ok != "S") {
			$retorno->mensagem = "Erro ao consultar transações: " . ($resultado->mensagem ?? 'Erro desconhecido');
			return $retorno;
		}
		
		// Verifica se encontrou transações
		if (isset($resultado->response->Transactions) && count($resultado->response->Transactions) > 0) {
			$transacaoEncontrada = $resultado->response->Transactions[0];
			if (isset($transacaoEncontrada->value)) 
			{
				if (isset($transacaoEncontrada->Charge->Customer->document))
				{					
					if ($transacaoEncontrada->Charge->Customer->document !== $cpf)
					{
						$retorno->mensagem = "Nenhuma transação encontrada para os critérios especificados";
						return $retorno;
					}
				}
			}
			// Comparação EXATA do valor (sem tolerância)
			if (isset($transacaoEncontrada->value)) {
				$valorTransacao = (float) $transacaoEncontrada->value;
				$valorComparacao = (float) ($valor * 100); // Valor em centavos
				
				if ($valorTransacao === $valorComparacao) {
					// Verificar se a transação está em status aberto (não paga, cancelada, etc.)
					$statusFechados = ['inactive','notSend', 'authorized', 'captured', 'denied', 'reversed','chargeback','payedBoleto','notCompensated','pendingPix','payedPix','unavailablePix','cancel','payExternal','cancelbycontract','free'];
					$statusTransacao = strtolower($transacaoEncontrada->status ?? '');
					
					if (in_array($statusTransacao, $statusFechados)) {
						$retorno->existe = false;
						$retorno->transacao = $transacaoEncontrada;
						$retorno->mensagem = "Transação encontrada mas com status fechado ($statusTransacao) - CPF: $cpf, Data: $dataVencimento, Valor: $valor";
						
						Log::info("Transação encontrada mas com status fechado", [
							'cpf' => $cpf,
							'data_vencimento' => $dataVencimento,
							'valor' => $valor,
							'status' => $statusTransacao,
							'transacao_id' => $transacaoEncontrada->galaxPayId ?? 'N/A'
						]);
					} else {
						$retorno->existe = true;
						$retorno->transacao = $transacaoEncontrada;
						$retorno->mensagem = "Transação duplicada encontrada (status aberto: $statusTransacao) - CPF: $cpf, Data: $dataVencimento, Valor: $valor";
						
						Log::info("Transação duplicada detectada (status aberto)", [
							'cpf' => $cpf,
							'data_vencimento' => $dataVencimento,
							'valor' => $valor,
							'status' => $statusTransacao,
							'transacao' => $transacaoEncontrada,
							'transacao_id' => $transacaoEncontrada->galaxPayId ?? 'N/A'
						]);
					}
				} else {
					$retorno->mensagem = "Transação encontrada mas com valor diferente - Esperado: $valor, Encontrado: " . ($valorTransacao / 100);
					
					Log::info("Transação com valor diferente", [
						'cpf' => $cpf,
						'valor_esperado' => $valor,
						'valor_encontrado' => ($valorTransacao / 100)
					]);
				}
			} else {
				$retorno->mensagem = "Transação encontrada mas sem informação de valor";
			}
		} else {
			$retorno->mensagem = "Nenhuma transação encontrada para os critérios especificados";
		}
		
		return $retorno;
	}

	// Função específica para verificar transação existente com filtros otimizados
	public static function getTransactionForDuplicateCheck($cpf, $galaxPayId, $dataVencimento, $valor)
	{
		$retorno = new stdClass();
		$retorno->ok = "N";
		
		$token = CelCash::Token();
		
		if (!isset($token->token)) {
			$retorno->mensagem = "Erro ao buscar token";
			return $retorno;
		}
		
		// Query otimizada com filtros específicos e ordenação
		$queryParams = [
			'startAt' 				=> 0,
			'customerGalaxPayIds' 	=> $galaxPayId,
			'payDayFrom' 			=> $dataVencimento,
			'payDayTo' 				=> $dataVencimento,
			'limit' 				=> 1,
			'order' 				=> 'createdAt.desc'  // Mais recente primeiro
		];
		
		$query = http_build_query($queryParams);
		$endpoint = $token->url . "/transactions?$query";
		
		try {
			$hresponse = Http::withHeaders([
				'Authorization' => "Bearer $token->token",
				'Content-Type' => 'application/json'
			])->get($endpoint);
			
			$statcode = $hresponse->status();
			$response = $hresponse->object();
			
			Log::info("getTransactionForDuplicateCheck", [
				'query' => $queryParams,
				'response' => $response
			]);
			
		} catch (\Exception $e) {
			$retorno->error = true;
			$retorno->statcode = 500;
			$retorno->mensagem = $e->getMessage();
			return $retorno;
		}
		
		$retorno->response = $response;
		$retorno->statcode = $statcode;
		
		if ($statcode == 200) {
			$retorno->ok = "S";
		}
		
		return $retorno;
	}
}
