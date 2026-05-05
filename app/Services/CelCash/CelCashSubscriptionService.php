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
    private static function prepararTransactionSubscription($transaction, $subscription)
    {
        $payload = json_decode(json_encode($transaction));

        if ((! isset($payload->subscriptionGalaxPayId)) && (isset($subscription->galaxPayId))) {
            $payload->subscriptionGalaxPayId = $subscription->galaxPayId;
        }

        if ((! isset($payload->subscriptionMyId)) && (isset($subscription->myId))) {
            $payload->subscriptionMyId = $subscription->myId;
        }

        return $payload;
    }

    private static function sincronizarTransactionsSubscription($contrato, $subscription, $transactions, $galaxId = 1)
    {
        $retorno = new stdClass();
        $retorno->ok = "S";
        $retorno->transactions = array();

        foreach ($transactions as $transaction) {
            $payload = self::prepararTransactionSubscription($transaction, $subscription);

            Log::info('celcash.subscription.transaction_sync_inicio', [
                'contrato_id' => $contrato->id ?? null,
                'subscriptionGalaxPayId' => $payload->subscriptionGalaxPayId ?? null,
                'transactionGalaxPayId' => $payload->galaxPayId ?? null,
                'installment' => $payload->installment ?? null,
                'payday' => $payload->payday ?? null,
                'status' => $payload->status ?? null,
            ]);

            $migracao = CelCash::CelCashMigrarTransaction($payload, $galaxId, 'C');

            if (! $migracao->ok) {
                Log::warning('celcash.subscription.transaction_sync_falha', [
                    'contrato_id' => $contrato->id ?? null,
                    'subscriptionGalaxPayId' => $payload->subscriptionGalaxPayId ?? null,
                    'transactionGalaxPayId' => $payload->galaxPayId ?? null,
                    'installment' => $payload->installment ?? null,
                    'payday' => $payload->payday ?? null,
                    'status' => $payload->status ?? null,
                    'erro' => $migracao->mensagem ?? null,
                ]);
                continue;
            }

            Log::info('celcash.subscription.transaction_sync_sucesso', [
                'contrato_id' => $contrato->id ?? null,
                'subscriptionGalaxPayId' => $payload->subscriptionGalaxPayId ?? null,
                'transactionGalaxPayId' => $payload->galaxPayId ?? null,
                'installment' => $payload->installment ?? null,
                'parcela_id' => $migracao->parcela_id ?? null,
            ]);

            $retorno->transactions[] = $migracao;
        }

        return $retorno;
    }

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
		
		$contratoId								= $celcash->Subscription->myId ?? null;
		$contrato 			            			= null;

		if (!empty($contratoId))
		{
			$contrato 							= \App\Models\Contrato::find($contratoId);
		}

		if (!isset($contrato->id))
		{
			$contrato 							= \App\Models\Contrato::where('galaxPayId','=',$celcash->Subscription->galaxPayId)
														 ->where('tipocontrato','=','C')
														 ->first();
		}

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

            CelCashMigrationService::garantirTitularPfAtivo($contrato);
			
			if (isset($celcash->Subscription->Transactions))
        {
			$transactions 										= array();
			
            if (is_array($celcash->Subscription->Transactions))
            {
				$transactions									 = $celcash->Subscription->Transactions;
			} else {
				$transactions[]									 = $celcash->Subscription->Transactions;
			}

            $retorno->sincronizacao = self::sincronizarTransactionsSubscription($contrato, $celcash->Subscription, $transactions, 1);
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
