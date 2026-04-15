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

class CelCashChargeService
{

	 public static function GetCharges($query,$galaxId=1)
    {
        $token                           = CelCash::Token($galaxId);

        //Log::info("celcash", ['ctoken' => $token ]);
        
        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/charges";
        $endpoint                       .= "?";
        $endpoint                       .= $query;

        $retorno 						= new stdClass();

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
            $retorno->message 			= $e;
            return $retorno;
        } catch (RequestException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->message 			= $e;
            return $retorno;
        }

        $response->statcode             = $statcode;
        $response->endpoint             = $endpoint;
		$response->token             	= $token->token;
        return $response;

    }

	public static function storeCharges($payload, $galaxId=1)
    {
        $retorno 						 = new stdClass();
        $retorno->ok                     = "N";

        $token                           = CelCash::Token($galaxId);

        if (!isset($token->token))
        {
            $retorno->mensagem           = "Erro ao buscar token #$token";
            return $retorno;
        }

        $endpoint                       = $token->url . "/charges";

        try {
            $hresponse                  = Http::timeout(720)
											->withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->post($endpoint, $payload);
            $statcode					= $hresponse->status();
            $response 					= $hresponse->object();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $retorno->statcode = 408;
            if (strpos($e->getMessage(), 'timed out') !== false) 
			{
                $retorno->mensagem = "Tempo limite excedido ao conectar com a API CelCash. Por favor, tente novamente mais tarde.";
                Log::error("Timeout na API CelCash: " . $e->getMessage() . " - Endpoint: $endpoint");
            } else {
                $retorno->mensagem = "Erro de conexão com a API CelCash: " . $e->getMessage();
                Log::error("Erro de conexão com API CelCash: " . $e->getMessage() . " - Endpoint: $endpoint");
            }
            return $retorno;
        } catch (RequestException $e) {
           $retorno->error 		= true;
           $retorno->statcode 	= $e->getCode() ?: 500;
           $retorno->mensagem 	= "Falha na requisição para API CelCash: " . $e->getMessage();
           Log::error("Erro na requisição para API CelCash: " . $e->getMessage() . " - Endpoint: $endpoint");  
           return $retorno;
        } catch (\Exception $e) {
            $retorno->error 	= true;
            $retorno->statcode 	= 500;
            $retorno->mensagem 	= "Erro inesperado ao processar requisição: " . $e->getMessage();
            Log::error("Erro inesperado na comunicação com API CelCash: " . $e->getMessage() . " - Endpoint: $endpoint");
            return $retorno;
        }   

		if (($statcode == 408) or ($statcode == 504))
		{
			$retorno->mensagem          = "Cell Cash passando por instabilidade!";
		} else {
			$retorno->mensagem          = CelCash::buscarMensagem($statcode, $response);
		}

        if ($statcode == 200) 
        {
            $retorno->ok                = "S";
        }

        $retorno->response              = $response;

        return $retorno;
    }

	/**
	 * @deprecated Use storeCharges($payload, 2) em vez deste método.
	 */
	public static function storeChargesAgendamento($payload)
    {
        return self::storeCharges($payload, 2);
    }

	public static function alterarCharges($id,$body, $typeId='galaxPayId', $galaxId=1)
    {
		
		$token                          = CelCash::Token($galaxId);

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/charges/$id/$typeId";
     
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

		//Log::info("alterarCharges", ['response' => $response ]);

        $retorno                       	= $response;
		$retorno->statcode              = $statcode;
        return $retorno;
		
	}

	public static function cancelCharges($id,$galaxid=1,$typeId="galaxPayId")
    {
		
		$token                          = CelCash::Token($galaxid);

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/charges/$id/$typeId";
     
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
		
		//Log::info("cancelar", ['cobranca' => $response ]);

        $retorno                       	= $response;
		$retorno->statcode              = $statcode;
        return $retorno;
		
		
	}

	public static function storeContratoCharges($parcela_id)
    {
		$retorno 							= new stdClass();
        $retorno->ok                    	= "N";
		$retorno->mensagem 					= "";
		
		$parcela 							= \App\Models\Parcela::find($parcela_id);
		
		if (!isset($parcela->id)) 
        {
            $retorno->mensagem          	= "Parcela $parcela_id não encontrado";
			return $retorno;
        }
		
		if ($parcela->galaxPayId > 0)
        {
			$retorno->mensagem          	= "Já existe a cobrança no Cell Cash";
			return $retorno;
		}
		
		$contrato 			            	= \App\Models\Contrato::with('vendedor:id,nome')->find($parcela->contrato_id);

        if (!isset($contrato->id)) 
        {
			$retorno->mensagem          	= "Contrato ID " . $parcela->contrato_id. " não encontrado";
			return $retorno;
		}
		
		$contrato_id						= $contrato->id;
		
		$plano              				= \App\Models\Plano::with('periodicidade:id,nome,periodicity')
												->select('id','nome','formapagamento','taxa_ativacao','preco','periodicidade_id')
												->find($contrato->plano_id);
        
        if (!isset($plano->id)) 
        {
            $retorno->mensagem          	= "Plano do contrado ID $contrato_id não encontrado";
			return $retorno;
        }
		
		$customer                   		= CelCash::getStoreCustomers($contrato->cliente_id);
		
		//Log::info("customer", ['customer' => $customer ]);
		
		if ($customer->ok == "N")
        {
			$retorno->mensagem          	= $customer->mensagem;
			return $retorno;
        }
		
		if (isset($customer->galaxPayId))
		{
			$cliente						= $customer;
		}
		
		if (isset($customer->customers->galaxPayId))
		{
			$cliente						= $customer->customers;
		}
		
		if (isset($customer->customers->Customer->galaxPayId))
		{
			$cliente						= $customer->customers->Customer;
		}
		
		if (!isset($cliente->galaxPayId))
		{
			$retorno->mensagem          	= 'Não conseguiu mapear o cliente. Problema no Cel Cash';
			return $retorno;
		}
		
		// Verificar se já existe transação para o mesmo cliente na mesma data de vencimento
		
		 
		$verificacao = CelCash::verificarTransacaoExistente(
			$cliente->document,
			$cliente->galaxPayId,
			$parcela->data_vencimento,
			$parcela->valor
		);
		
		if ($verificacao->existe)
		{
			//$retorno->mensagem          					= 'Já existe a transaçao no CellCash';
			//return $retorno;
			
			if (isset($verificacao->transacao->Charge))
			{
				// Log::info("Charge", ['Charge' => $verificacao->transacao->Charge ]);
				
				$parcela->galaxPayId			            = $verificacao->transacao->galaxPayId;
				$parcela->cgalaxPayId			            = $verificacao->transacao->Charge->galaxPayId;
				$parcela->chargeMyId						= $verificacao->transacao->Charge->myId;
		
				if (isset($verificacao->transacao->Boleto))
				{
					if (isset($verificacao->transacao->Boleto->pdf))
					{
						$parcela->boletopdf				    = Cas::nulltoSpace($verificacao->transacao->Boleto->pdf);
					} else {
						$parcela->boletopdf				    = "";
					}
					if (isset($verificacao->transacao->Boleto->bankLine))
					{
						$parcela->boletobankLine		    = Cas::nulltoSpace($verificacao->transacao->Boleto->bankLine);
					} else {
						$parcela->boletobankLine		    = "";
					}
					if (isset($verificacao->transacao->Boleto->bankNumber))
					{
						$parcela->boletobankNumber		    = Cas::nulltoSpace($verificacao->transacao->Boleto->bankNumber);
					} else {
						$parcela->boletobankNumber          = 0;
					}
					if (isset($verificacao->transacao->Boleto->barCode))
					{
						$parcela->boletobarCode			    = Cas::nulltoSpace($verificacao->transacao->Boleto->barCode);
					} else {
						$parcela->boletobarCode			    = "";
					}
					if (isset($verificacao->transacao->Boleto->bankEmissor))
					{
						$parcela->boletobankEmissor		    = Cas::nulltoSpace($verificacao->transacao->Boleto->bankEmissor);
					} else {
						$parcela->boletobankEmissor         = "";
					}
					if (isset($verificacao->transacao->Boleto->bankAgency))
					{
						$parcela->boletobankAgency		    = Cas::nulltoSpace($verificacao->transacao->Boleto->bankAgency);
					} else {
						$parcela->boletobankAgency          = "";
					}
					if (isset($verificacao->transacao->Boleto->bankAccount))
					{
						$parcela->boletobankAccount		    = Cas::nulltoSpace($verificacao->transacao->Boleto->bankAccount);
					} else {
						$parcela->boletobankAccount         = "";
					}
				} 
				$parcela->save();
				$retorno 						= new stdClass();
				$retorno->Charge				= $verificacao->transacao->Charge;
				$retorno->ok 					= 'S';
			}
			return $retorno;
		}
		 
		$dados = array(
				'myId'                  	=> $contrato_id . '#' . $parcela_id . "#" . bin2hex(random_bytes(5)),
				'payday'                	=> $parcela->data_vencimento,
				'value'                 	=> intval($parcela->valor * 100),
				'additionalInfo'			=> "",
				"payedOutsideGalaxPay"  	=> false,
				"mainPaymentMethodId"   	=> $plano->formapagamento,
				"Customer"              	=> [
					"myId"              	=> $cliente->myId,
					"galaxPayId"        	=> $cliente->galaxPayId,
					"name"              	=> $cliente->name,
					"document"          	=> $cliente->document,
					"emails"            	=> $cliente->emails,
					"phones"            	=> $cliente->phones,
					"Address"           	=> $cliente->Address
				],
        );
		
		switch($plano->formapagamento) {
            case 'creditcard':
                $dados['PaymentMethodCreditCard'] = [
                    'Card' => [],
                ];
                break;
            case 'boleto':
                $dados['PaymentMethodBoleto'] = [
                    'instructions' => "Boleto de pagamento",
                ];
                break;
            case 'pix':
                $dados['PaymentMethodPix'] = [
                    'instructions' => "Pagamento via pix",
                ];
                break;
            default:
			   $retorno->mensagem          	= "Contrato ID $contrato_id forma de pagamento inválida";
			   return $retorno;
        }
		
		$dados['ExtraFields'] 				= [
												[
													'tagName'  => 'CP_VENDEDOR',
													'tagValue' => $contrato->vendedor->nome
												]
											  ];
	

		//Log::info("charges", ['dados' => $dados ]);
				
		$charges                        	= CelCash::storeCharges($dados);
		
		//Log::info("charges", ['charges' => $charges ]);
		
		if ($charges->ok != 'S')
		{
			$retorno->mensagem				= $charges->mensagem;
			return $retorno;
		}
		
		$retorno							= $charges->response;
		$retorno->ok 						= 'S';
		return $retorno;
		
	}

	public static function storeAgendamentoCharges($id)
    {
		$retorno 							= new stdClass();
        $retorno->ok                    	= "N";
		$retorno->mensagem 					= "";
		
		$agendamento                        = \App\Models\ClinicaBeneficiario::with('clinica','beneficiario','especialidade')->find($id);
		
		if (!isset($agendamento->id)) 
        {
            $retorno->mensagem          	= "Agendamento $id não encontrado";
			return $retorno;
        }
		
		// Guard: Evitar cobranças duplicadas no CellCash
		if (!empty($agendamento->galaxPayId) && $agendamento->galaxPayId > 0)
		{
			$retorno->mensagem          	= "Já existe a cobrança no Cell Cash para este agendamento";
			Log::warning("Tentativa de criar cobrança duplicada para agendamento $id - galaxPayId existente: {$agendamento->galaxPayId}");
			return $retorno;
		}
		
		$cliente_id 						= $agendamento->beneficiario->cliente_id;
		
		$cliente                        	= \App\Models\Cliente::find($cliente_id);
		
		if (!isset($cliente->id)) 
        {
            $retorno->mensagem          	= "Cliente do agendamento $id não encontrado";
			return $retorno;
        }
		
		if (($cliente->data_nascimento == '1900-01-01') or (is_null($cliente->data_nascimento)))
		{
			$retorno->mensagem          	= "Favor ajustar a idade do beneficiário";
			return $retorno;
		}
		
		$nome 								= $cliente->nome;
		$cpf 								= $cliente->cpfcnpj;
		
		$idade 				    			= Carbon::createFromDate($cliente->data_nascimento)->age;
		
		if (($idade < 18) and ($agendamento->beneficiario->tipo == 'T'))
		{
			$retorno->mensagem          	= "Beneficiário titular deve ser maior que 18 anos";
			return $retorno;
		}
		
		if ($idade < 18)
		{
			$beneficiario               	= \App\Models\Beneficiario::with('contrato')->find($agendamento->beneficiario->id);
			if (!isset($beneficiario->id)) 
			{
				$retorno->mensagem          = "Beneficiário do agendamento $id não encontrado";
				return $retorno;
			}
				
			if ($beneficiario->contrato->tipo == 'J')
			{
				$beneficiario               = \App\Models\Beneficiario::find($beneficiario->parent_id);
				
				if (!isset($beneficiario->id)) 
				{
					$retorno->mensagem          = "Beneficiário do agendamento $id não encontrado";
					return $retorno;
				}
			
				$cliente_id 				= $beneficiario->cliente_id;
			} else {
				$beneficiario               = \App\Models\Beneficiario::where('contrato_id','=',$beneficiario->contrato_id)
																	  ->where('tipo','=','T')
																	  ->first();
				if (!isset($beneficiario->id)) 
				{
					$retorno->mensagem      = "Beneficiário do agendamento $id não encontrado";
					return $retorno;
				}
				$cliente_id 				= $beneficiario->cliente_id;
			}
			
			$cliente                        = \App\Models\Cliente::find($cliente_id);
		
			if (!isset($cliente->id)) 
			{
				$retorno->mensagem          = "Cliente Titular do agendamento $id não encontrado";
				return $retorno;
			}
			
			if (($cliente->data_nascimento == '1900-01-01') or (is_null($cliente->data_nascimento)))
			{
				$retorno->mensagem          = "Favor ajustar a idade do beneficiário Titular";
				return $retorno;
			}
			
			$idade 				    		= Carbon::createFromDate($cliente->data_nascimento)->age;
			
			if ($idade < 18)
			{
				$retorno->mensagem          = "Beneficiário titular deve ser maior que 18 anos";
				return $retorno;
			}
		}
		
		$customer                   		= CelCash::getStoreCustomersAgendamento($cliente_id);
		
		Log::info("getStoreCustomersAgendamento retorno", ['customer' => $customer ]);
		
		if ($customer->ok == "N")
        {
			$retorno->mensagem          	= $customer->mensagem;
			return $retorno;
        }
		
		if (isset($customer->galaxPayId))
		{
			$ccliente						= $customer;
		}
		
		if (isset($customer->customers->galaxPayId))
		{
			$ccliente						= $customer->customers;
		}
		
		if (isset($customer->customers->Customer->galaxPayId))
		{
			$ccliente						= $customer->customers->Customer;
		}
		
		if (isset($customer->customer->Customer->galaxPayId))
		{
			$ccliente						= $customer->customer->Customer;
		}
		
		if (isset($customer->Customer->galaxPayId))
		{
			$ccliente						= $customer->Customer;
		}
		
		if (!isset($ccliente->galaxPayId))
		{
			$retorno->mensagem          	= 'Não conseguiu mapear o cliente. Problema no Cel Cash';
			return $retorno;
		}
		
		switch($agendamento->forma) {
            case 'creditcard':
			case 'C':
               $formapagamento				= 'creditcard';
                break;
            case 'B':
                $formapagamento				= 'boleto';
                break;
            case 'P':
                $formapagamento				= 'PIX';
                break;
            default:
			   $formapagamento				= 'boleto';
        }
		
		if (is_null($agendamento->vencimento))
		{
			$subDays						= 2;
			
			$agendardata					= substr($agendamento->agendamento_data_hora,0,10);
			$vencimento						= Carbon::parse($agendardata)->subDays($subDays)->format('Y-m-d');
			
			if ($vencimento < date('Y-m-d'))
			{
				$retorno->mensagem          = 'Data de vencimento não pode ser menor que hoje';
				return $retorno;
			}
		} else {
			$vencimento						= $agendamento->vencimento;
		}
		
		if ($agendamento->tipo == 'C')
		{
			$tipo 							= 'Consulta';
		} else {
			$tipo 							= 'Exame';
		}
		
		$instrucoes 						= "Pagamento referente a especialidade " . $agendamento->especialidade->nome;
		
		$dados = array(
				'myId'                  	=> $id . '#' . bin2hex(random_bytes(5)),
				'payday'                	=> $vencimento,
				'value'                 	=> intval($agendamento->valor_a_pagar * 100),
				'additionalInfo'			=> "",
				"payedOutsideGalaxPay"  	=> false,
				"mainPaymentMethodId"   	=> $formapagamento,
				"Customer"              	=> [
					"myId"              	=> $ccliente->myId,
					"galaxPayId"        	=> $ccliente->galaxPayId,
					"name"              	=> $cliente->nome, //$ccliente->name,
					"document"          	=> $ccliente->document,
					"emails"            	=> $ccliente->emails,
					"phones"            	=> $ccliente->phones,
					"Address"           	=> $ccliente->Address
				],
        );
		
		if ($agendamento->parcelas ==0)
		{
			$minparcelas 					= 1;
			$maxparcelas 					= 1;
		} else {
			$minparcelas 					= 1;
			$maxparcelas 					= $agendamento->parcelas;
		}
		 
		switch($formapagamento) {
            case 'creditcard':
                $dados['PaymentMethodCreditCard'] = [
					'Link' => [
						'minInstallment' => $minparcelas,
						'maxInstallment' => $maxparcelas,
					],
                    'Card' 	=> [],
					'qtdInstallments' => $maxparcelas,
                ];
                break;
            case 'boleto':
                $dados['PaymentMethodBoleto'] = [
                    'instructions' => $instrucoes,
                ];
                break;
            case 'pix':
                $dados['PaymentMethodPix'] = [
                    'instructions' => $instrucoes,
                ];
                break;
            default:
			   $retorno->mensagem          	= "Agendamento ID $id forma de pagamento inválida";
			   return $retorno;
        }
		
		//Log::info("charges", ['dados' => $dados ]);
				
		$charges                        	= CelCash::storeChargesAgendamento($dados);
		
		//Log::info("charges", ['charges' => $charges ]);
		
		if ($charges->ok != 'S')
		{
			$retorno->mensagem				= $charges->mensagem;
			return $retorno;
		}
	
		if (!isset($charges->response->Charge->myId))
		{
			 $retorno->mensagem          	= "Dados da cobrança não encontrada";
			 return $retorno;
		}
	
		$agendamento                        = \App\Models\ClinicaBeneficiario::find($id);
		
		if (!isset($agendamento->id)) 
        {
            $retorno->mensagem          	= "Agendamento $id não encontrado";
			return $retorno;
        }
						
		$agendamento->galaxPayId			= $charges->response->Charge->Transactions[0]->galaxPayId;
        $agendamento->paymentLink			= $charges->response->Charge->paymentLink;
        $agendamento->myId					= $charges->response->Charge->myId;
		$agendamento->status				= $charges->response->Charge->Transactions[0]->status;
		 
        
		if (isset(($charges->response->Charge->Transactions[0]->Boleto)))
		{
			if (isset(($charges->response->Charge->Transactions[0]->Boleto->pdf)))
			{
				$agendamento->boletopdf	= $charges->response->Charge->Transactions[0]->Boleto->pdf;
			}
			if (isset(($charges->response->Charge->Transactions[0]->Boleto->bankNumber)))
			{
				$agendamento->boletobankNumber	= $charges->response->Charge->Transactions[0]->Boleto->bankNumber;
			}
		}
		
		$agendamento->pixpage					= "";
		$agendamento->pixqrCode					= "";
		
		if (isset(($charges->response->Charge->Transactions[0]->Pix)))
		{
			if (isset(($charges->response->Charge->Transactions[0]->Pix->page)))
			{
				$agendamento->pixpage			= $charges->response->Charge->Transactions[0]->Pix->page;
			}
			if (isset(($charges->response->Charge->Transactions[0]->Pix->image)))
			{
				$agendamento->piximage			= $charges->response->Charge->Transactions[0]->Pix->image;
			}
			if (isset(($charges->response->Charge->Transactions[0]->Pix->qrCode)))
			{
				$agendamento->pixqrCode			= $charges->response->Charge->Transactions[0]->Pix->qrCode;
			}
		}
   
    	$agendamento->response 				= json_encode($charges->response->Charge);
		$agendamento->vencimento			= $vencimento;
		$agendamento->asituacao_id 			= 4;
		$agendamento->save();
		
		$retorno							= $charges->response->Charge;
		$retorno->asituacao_id				= $agendamento->asituacao_id;
		$retorno->galaxPayId				= $agendamento->galaxPayId;
		$retorno->paymentLink				= $agendamento->paymentLink;
		$retorno->pixpage					= $agendamento->pixpage;
		$retorno->pixqrCode					= $agendamento->pixqrCode;
		$retorno->ok 						= 'S';
		return $retorno;
	}

	public static function updateContratoWithCharge($celcash)
	{
		//Log::info("updateContratoWithCharge", ['charge' => $celcash ]);
		
		$retorno 						 			= new stdClass();
        $retorno->ok                     			= "N";
		
		if (!isset($celcash->myId))
		{
			 $retorno->mensagem          			= "Dados da cobrança não encontrada";
			 return $retorno;
		}
		
		if (substr_count($celcash->myId, '#') <> 2) 
		{
			 $retorno->mensagem          			= "Dados da cobrança não encontrado";
			 return $retorno;
		}
		
		list($contrato_id,$parcela_id,$hash)        = explode("#",$celcash->myId);
		
		$contrato 				                    = \App\Models\Contrato::find($contrato_id);
                                                                  
		if (!isset($contrato->id))
		{
			 $retorno->mensagem          			= "Dados do contrato não encontrado";
			 return $retorno;
		}
						
		$contrato->galaxPayId			            = $celcash->galaxPayId;
        $contrato->paymentLink			            = $celcash->paymentLink;
        $contrato->mainPaymentMethodId              = Cas::nulltoSpace($celcash->mainPaymentMethodId);
		if (isset($celcash->quantity))
		{
			$contrato->quantity			            = $celcash->quantity;
		} else {
			$contrato->quantity			            = 1;
		}
		if (isset($celcash->periodicity))
		{
			$contrato->periodicity			        = $celcash->periodicity;
		} else {
			$contrato->periodicity					= 1;
		}
		if (isset($celcash->firstPayDayDate))
		{
			$contrato->firstPayDayDate		        = $celcash->firstPayDayDate;
		}
		if (isset($celcash->additionalInfo))
		{
			$contrato->additionalInfo		        = Cas::nulltoSpace($celcash->additionalInfo);
		} else {
			$contrato->additionalInfo		        = "";
		}
		
        if (isset($celcash->PaymentMethodBoleto))
        {
            if (isset($celcash->PaymentMethodBoleto->fine))
            {
                $contrato->paymentMethodBoletofine          = $celcash->PaymentMethodBoleto->fine;
            } else {
                $contrato->paymentMethodBoletofine          = 0;
            }

            if (isset($celcash->PaymentMethodBoleto->interest))
            {
                $contrato->paymentMethodBoletointerest      = $celcash->PaymentMethodBoleto->interest;
            } else {
                $contrato->paymentMethodBoletointerest      = 0;
            }
            if (isset($celcash->PaymentMethodBoleto->instructions))
            {
                $contrato->paymentMethodBoletoinstructions  = Cas::nulltoSpace($celcash->PaymentMethodBoleto->instructions);
            } else {
                $contrato->paymentMethodBoletoinstructions  = "";
            }
            if (isset($celcash->PaymentMethodBoleto->deadlineDays))
            {
                $contrato->paymentMethodBoletodeadlineDays  = $celcash->PaymentMethodBoleto->deadlineDays;
            } else {
                $contrato->paymentMethodBoletodeadlineDays  = 0;
            }
            if (isset($celcash->PaymentMethodBoleto->documentNumber))
            {
                $contrato->paymentMethodBoletodocumentNumber = Cas::nulltoSpace($celcash->PaymentMethodBoleto->documentNumber);
            } else {
                 $contrato->paymentMethodBoletodocumentNumber= "";
            }
        } 

        if (isset($celcash->Contract))
        {
            if (isset($celcash->Contract->name))
            {
                $contrato->contractname            = Cas::nulltoSpace($celcash->Contract->name);
            } else {
                $contrato->contractname            = "";
            }
            if (isset($celcash->Contract->document))
            {
                $contrato->contractdocument        = Cas::nulltoSpace($celcash->Contract->document);
            } else {
                $contrato->contractdocument        = "";
            }
            if (isset($celcash->Contract->ip))
            {
                $contrato->contractip              = Cas::nulltoSpace($celcash->Contract->ip);
            } else {
                $contrato->contractip              = "";
            }
            if (isset($celcash->Contract->acceptedAt))
            {
                $contrato->contractacceptedAt       = $celcash->Contract->acceptedAt;
            }
            if (isset($celcash->Contract->pdf))
            {
                $contrato->contractpdf              = Cas::nulltoSpace($celcash->Contract->pdf);
            } else {
                $contrato->contractpdf              = "";
            }
        }

        if (isset($celcash->PaymentMethodCreditCard))
        {
            $contrato->paymentMethodCreditCard      = json_encode($celcash->PaymentMethodCreditCard);
        }
		
		if (isset($celcash->PaymentMethodPix))
        {
            $contrato->paymentMethodPix      		= json_encode($celcash->PaymentMethodPix);
        }
		
		if ($contrato->save())
		{
			if (isset($celcash->Transactions[0]))
			{
				$retorno 							= CelCash::updateParcelaWithChargeTransaction($celcash->Transactions[0], $celcash);
				return $retorno;
			} else {
				$retorno->mensagem          		= "Dados da transaçao não encontrada";
			}
		}
		
		return $retorno;
	}
}
