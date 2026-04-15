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

class CelCashSubscriptionService
{

    public static function GetSubscription($galaxPayIds,$galaxId=1)
    {
        $token                           = CelCash::Token($galaxId);

        //Log::info("celcash", ['token' => $token ]);
        
        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/subscriptions";
        $endpoint                       .= "?galaxPayIds=";
        $endpoint                       .= $galaxPayIds;
        $endpoint                       .= "&startAt=0&limit=100";

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
        return $response;

    }

    public static function GetSubscriptions($query,$galaxId=1)
    {
        $token                           = CelCash::Token($galaxId);

        //Log::info("celcash", ['ctoken' => $token ]);
        
        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/subscriptions";
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
        return $response;

    }

	public static function storeSubscriptions($payload)
    {
        $retorno 						 = new stdClass();
        $retorno->ok                     = "N";

        $token                           = CelCash::Token();

        if (!isset($token->token))
        {
            $retorno->mensagem           = "Erro ao buscar token #$token";
            return $retorno;
        }

        $endpoint                       = $token->url . "/subscriptions";


        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->post($endpoint, $payload);
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

        $retorno->mensagem              = CelCash::buscarMensagem($statcode, $response);

		if ($statcode == 200) 
        {
            $retorno->ok                = "S";
        }
		
        $retorno->response              = $response;

        return $retorno;
    }

	public static function cancelSubscriptions($id)
    {
		
		$token                          = CelCash::Token();

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/subscriptions/$id/galaxPayId";
     
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

		Log::info("response", ['response' => $response ]);
		Log::info("cancelSubscriptions", ['endpoint' => $endpoint ]);
		
        $retorno                       	= $response;
		$retorno->statcode              = $statcode;
        return $retorno;
		
	}

	public static function storeContrato($id)
    {
		$retorno 							= new stdClass();
        $retorno->ok                    	= "N";
		
		$contrato 			            	= \App\Models\Contrato::with('vendedor:id,nome','cliente')->find($id);

        if (!isset($contrato->id)) 
        {
			$retorno->mensagem          	= "Contrato ID $id não encontrado";
			return $retorno;
		}
		
		$plano              				= \App\Models\Plano::with('periodicidade:id,nome,periodicity')
															 ->select('id','nome','formapagamento','taxa_ativacao','preco','periodicidade_id','galaxPayId')
															 ->find($contrato->plano_id);
        
        if (!isset($plano->id)) 
        {
            $retorno->mensagem          	= "Plano do contrado ID $id não encontrado";
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
		
		if (isset($customer->customer->Customer->galaxPayId))
		{
			$cliente						= $customer->customer->Customer;
		}
		
		if (!isset($cliente->galaxPayId))
		{
			$retorno->mensagem          	= 'Não conseguiu mapear o cliente. Problema no Cel Cash';
			return $retorno;
		}
		
		/* aqui */
		if (isset($cliente->Address->number))
		{
			if (Cas::nulltoSpace($cliente->Address->number) =="")
			{
				$cliente->Address->number  = $contrato->cliente->numero;
			}
		}
		
		$dados = array(
				'myId'                  	=> $id,
				'planMyId'					=> $plano->id,
				'planGalaxPayId'			=> $plano->galaxPayId,
				'payday'                	=> $contrato->firstPayDayDate,
				'value'                 	=> intval($contrato->valor * 100),
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
			case 'cartao':
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
			   $retorno->mensagem          	= "Contrato ID $id forma de pagamento inválida";
			   return $retorno;
        }
		
		$dados['ExtraFields'] 				= [
												[
													'tagName'  => 'CP_VENDEDOR',
													'tagValue' => $contrato->vendedor->nome
												]
											  ];
	

		//Log::info("subscription", ['dados' => $dados ]);
				
		$subscription                        = CelCash::storeSubscriptions($dados);
		
		if ($subscription->ok != 'S')
		{
			$retorno->mensagem				= $subscription->mensagem;
			return $retorno;
		}
		
		$retorno							= $subscription->response;
		$retorno->ok 						= 'S';
		return $retorno;
		
	}

	public static function updateContratoWithSubscription($celcash)
    {
		//Log::info("updateContratoWithSubscription", ['celcash' => $celcash ]);
	
		$retorno 						 			= new stdClass();
        $retorno->ok                     			= "N";
		$retorno->status 							= "";
		
		if (!isset($celcash->Subscription))
		{
			 $retorno->mensagem          			= "Dados da inscrição não encontada";
			 return $retorno;
		}
		
		if (!isset($celcash->Subscription->galaxPayId))
		{
			$retorno->mensagem          			= "Número do contrato não encontrado";
			return $retorno;
		}
		
		$contrato 			            			= \App\Models\Contrato::find($celcash->Subscription->myId);

        if (!isset($contrato->id)) 
        {
			$retorno->mensagem          			= "Contrato enviado pelo CelCash não encontrado ID: " . $celcash->Subscription->myId;
			return $retorno;
		}
		
		$contrato->galaxPayId			            = $celcash->Subscription->galaxPayId;
        $contrato->paymentLink			            = $celcash->Subscription->paymentLink;
        $contrato->mainPaymentMethodId              = Cas::nulltoSpace($celcash->Subscription->mainPaymentMethodId);
        $contrato->status				            = $celcash->Subscription->status;
		$retorno->status							= $celcash->Subscription->status;
		
		if (isset($celcash->Subscription->quantity))
		{
			$contrato->quantity			            = $celcash->Subscription->quantity;
		} 
		if (isset($celcash->Subscription->periodicity))
		{
			$contrato->periodicity			        = $celcash->Subscription->periodicity;
		} 
		if (isset($celcash->Subscription->firstPayDayDate))
		{
			$contrato->firstPayDayDate		        = $celcash->Subscription->firstPayDayDate;
		}
		if (isset($celcash->Subscription->additionalInfo))
		{
			$contrato->additionalInfo		        = Cas::nulltoSpace($celcash->Subscription->additionalInfo);
		} 
		
        $contrato->situacao_id			            = 1;

        if (isset($celcash->Subscription->PaymentMethodBoleto))
        {
            if (isset($celcash->Subscription->PaymentMethodBoleto->fine))
            {
                $contrato->paymentMethodBoletofine          = $celcash->Subscription->PaymentMethodBoleto->fine;
            } 

            if (isset($celcash->Subscription->PaymentMethodBoleto->interest))
            {
                $contrato->paymentMethodBoletointerest      = $celcash->Subscription->PaymentMethodBoleto->interest;
            } 
            if (isset($celcash->Subscription->PaymentMethodBoleto->instructions))
            {
                $contrato->paymentMethodBoletoinstructions  = Cas::nulltoSpace($celcash->Subscription->PaymentMethodBoleto->instructions);
            } 
            if (isset($celcash->Subscription->PaymentMethodBoleto->deadlineDays))
            {
                $contrato->paymentMethodBoletodeadlineDays  = $celcash->Subscription->PaymentMethodBoleto->deadlineDays;
            } 
            if (isset($celcash->Subscription->PaymentMethodBoleto->documentNumber))
            {
                $contrato->paymentMethodBoletodocumentNumber = Cas::nulltoSpace($celcash->Subscription->PaymentMethodBoleto->documentNumber);
            } 
        } else {
            $contrato->paymentMethodBoletofine           	= 0;
            $contrato->paymentMethodBoletointerest       	= 0;
            $contrato->paymentMethodBoletoinstructions   	= "";
            $contrato->paymentMethodBoletodeadlineDays   	= 0;
            $contrato->paymentMethodBoletodocumentNumber 	= "";
        }

        if (isset($celcash->Subscription->Contract))
        {
            if (isset($celcash->Subscription->Contract->name))
            {
                $contrato->contractname            = Cas::nulltoSpace($celcash->Subscription->Contract->name);
            } 
            if (isset($celcash->Subscription->Contract->document))
            {
                $contrato->contractdocument        = Cas::nulltoSpace($celcash->Subscription->Contract->document);
            } 
            if (isset($celcash->Subscription->Contract->ip))
            {
                $contrato->contractip              = Cas::nulltoSpace($celcash->Subscription->Contract->ip);
            } 
            if (isset($celcash->Subscription->Contract->acceptedAt))
            {
                $contrato->contractacceptedAt       = $celcash->Subscription->Contract->acceptedAt;
            }
            if (isset($celcash->Subscription->Contract->pdf))
            {
                $contrato->contractpdf              = Cas::nulltoSpace($celcash->Subscription->Contract->pdf);
            } 
        } else {
            $contrato->contractname             	= "";
            $contrato->contractdocument         	= "";
            $contrato->contractip               	= "";
            $contrato->contractpdf              	= "";
        }

        if (isset($celcash->Subscription->PaymentMethodCreditCard))
        {
            $contrato->paymentMethodCreditCard      = json_encode($celcash->Subscription->PaymentMethodCreditCard);
        }
		
		if (isset($celcash->Subscription->PaymentMethodPix))
        {
            $contrato->paymentMethodPix      		= json_encode($celcash->Subscription->PaymentMethodPix);
        }
		
		if (!$contrato->save())
		{
			$retorno->mensagem          						= "Ocorreu problema na tentativa de atualizar o contrato número: " . $celcash->Subscription->galaxPayId;
			return $retorno;
		}
		
		if (isset($celcash->Subscription->Transactions))
        {
			$transactions 										= array();
			
            if (is_array($celcash->Subscription->Transactions))
            {
				$transactions									 = $celcash->Subscription->Transactions;
			} else {
				$transactions[]									 = $celcash->Subscription->Transactions;
			}
			
            foreach ($transactions as $transaction)
            {
					$parcela                       				= \App\Models\Parcela::where('contrato_id','=',$contrato->id)
																					 ->where('nparcela','=',$transaction->installment)
																					 ->first();
					if (!isset($parcela->id))
					{
						$retorno->mensagem        				= "Parcela número " . $transaction->installment . ' não encontrada';
						return $retorno;
					}
	
					if (isset($transaction->fee))
					{
						$parcela->taxa					        = ($transaction->fee / 100);
					}
					
					$parcela->galaxPayId			            = $transaction->galaxPayId;
					$parcela->status				            = Cas::nulltoSpace($transaction->status);
					$parcela->statusDescription		            = Cas::nulltoSpace($transaction->statusDescription);
					if ((isset($transaction->statusDate)) and (Cas::temData($transaction->statusDate)))
					{
						$parcela->statusDate			        = $transaction->statusDate;
					} 
					$parcela->additionalInfo		            = Cas::nulltoSpace($transaction->additionalInfo);
					if (isset($transaction->subscriptionMyId))
					{
						$parcela->subscriptionMyId		        = Cas::nulltoSpace($transaction->subscriptionMyId);
					} 
					if (isset($transaction->payedOutsideGalaxPay))
					{
						$parcela->payedOutsideGalaxPay	        = $transaction->payedOutsideGalaxPay;
					} 

					if ((isset($transaction->datetimeLastSentToOperator)) and (Cas::temData($transaction->datetimeLastSentToOperator)))
					{
						$parcela->datetimeLastSentToOperator    = $transaction->datetimeLastSentToOperator;
					}
				   
					if (isset($transaction->tid))
					{
						$parcela->tid                           = $transaction->tid;
					}

					if (isset($transaction->authorizationCode))
					{
						$parcela->authorizationCode             = $transaction->authorizationCode;
					}

					if (isset($transaction->cardOperatorId))
					{
						$parcela->cardOperatorId                = $transaction->cardOperatorId;
					}

					if ((isset($transaction->ConciliationOccurrences)) and (is_array($transaction->ConciliationOccurrences)))
					{
						$parcela->conciliationOccurrences        = json_encode($transaction->ConciliationOccurrences);
					}

					if (isset($transaction->CreditCard))
					{
						$parcela->creditCard                    = json_encode($transaction->CreditCard);
					}

					if (isset($transaction->Boleto))
					{
						if (isset($transaction->Boleto->pdf))
						{
							$parcela->boletopdf				    = Cas::nulltoSpace($transaction->Boleto->pdf);
						} 
						if (isset($transaction->Boleto->bankLine))
						{
							$parcela->boletobankLine		    = Cas::nulltoSpace($transaction->Boleto->bankLine);
						} 
						if (isset($transaction->Boleto->bankNumber))
						{
							$parcela->boletobankNumber		    = Cas::nulltoSpace($transaction->Boleto->bankNumber);
						} 
						if (isset($transaction->Boleto->barCode))
						{
							$parcela->boletobarCode			    = Cas::nulltoSpace($transaction->Boleto->barCode);
						} 
						if (isset($transaction->Boleto->bankEmissor))
						{
							$parcela->boletobankEmissor		    = Cas::nulltoSpace($transaction->Boleto->bankEmissor);
						} 
						if (isset($transaction->Boleto->bankAgency))
						{
							$parcela->boletobankAgency		    = Cas::nulltoSpace($transaction->Boleto->bankAgency);
						} 
						if (isset($transaction->Boleto->bankAccount))
						{
							$parcela->boletobankAccount		    = Cas::nulltoSpace($transaction->Boleto->bankAccount);
						} 
					} else {
						$parcela->boletopdf				    = "";
						$parcela->boletobankLine		    = "";
						$parcela->boletobankNumber		    = 0;
						$parcela->boletobarCode			    = "";
						$parcela->boletobankEmissor		    = "";
						$parcela->boletobankAgency		    = "";
						$parcela->boletobankAccount		    = "";
					}
					if (isset($transaction->Pix))
					{
						if (isset($transaction->Pix->reference))
						{
							$parcela->pixreference			= Cas::nulltoSpace($transaction->Pix->reference);
						} 
						if (isset($transaction->Pix->qrCode))
						{
							$parcela->pixqrCode				= Cas::nulltoSpace($transaction->Pix->qrCode);
						} 
						if (isset($transaction->Pix->image))
						{
							$parcela->piximage				= $transaction->Pix->image;
						} 
						if (isset($transaction->Pix->page))
						{
							$parcela->pixpage				= $transaction->Pix->page;
						} 
					} else {
						$parcela->pixreference			   	= "";
						$parcela->pixqrCode				    = "";
						$parcela->piximage				    = "";
						$parcela->pixpage				    = "";
					}

					if (!$parcela->save())
					{
						$retorno->mensagem          		= "Ocorreu problema na tentativa de atualizar a parcela número: " . $transaction->galaxPayId;
						return $retorno;
					}
            }
        } 
        
		$retorno->ok                     					= "S";
		return $retorno;
	}

	public static function cancelarContrato($id)
    {
		$retorno 							= new stdClass();
        $retorno->ok                    	= "N";
		
		$contrato 			            	= \App\Models\Contrato::find($id);

        if (!isset($contrato->id)) 
        {
			$retorno->mensagem          	= "Contrato ID $id não encontrado";
			return $retorno;
		}
		
		$cancelar 							= CelCash::cancelSubscriptions($contrato->galaxPayId);
		
		if ((isset($cancelar->statcode)) and ($cancelar->statcode == 200))
		{
			$retorno->ok                   	= "S";
			return $retorno;
		}
		
		if (isset($cancelar->error->message))
		{
			$retorno->mensagem				= $cancelar->error->message;
		} else {
			$retorno->mensagem				= "Ocorreu um erro não identificado no cancelamento";
		}
		
		return $retorno;
	}
}
