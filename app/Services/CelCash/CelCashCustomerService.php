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

class CelCashCustomerService
{

    public static function GetCustomer($cpfcnpj,$galaxId=1)
    {
        $token                           = CelCash::Token($galaxId);

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/customers";
        $endpoint                       .= "?documents=";
        $endpoint                       .= $cpfcnpj;
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

    public static function ListCustomers($query,$galaxId=1)
    {
        $token                           = CelCash::Token($galaxId);

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/customers";
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

	public static function storeCustomers($payload, $galaxId=1)
    {
        $retorno                         = new stdClass();
        $retorno->ok                     = 'N';
        $retorno->mensagem               = '';
        
        $token                           = CelCash::Token($galaxId);

        if (!isset($token->token))
        {
            $retorno->mensagem           = "Erro ao buscar token #$token";
            return $retorno;
        }

        $endpoint                       = $token->url . "/customers";

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
            $retorno->message 			= $e;
            return $retorno;
        } catch (RequestException $e) {
            $retorno->error 			= true;
            $retorno->statcode			= 500;
            $retorno->message 			= $e;
            return $retorno;
        }

        $retorno->mensagem              = CelCash::buscarMensagem($statcode, $response);
        $retorno->ok                    = $statcode == 200 ? "S" : "N";
        $retorno->response              = $response;

        return $retorno;
    }

	public static function updateCustomers($payload,$galaxPayId,$galaxid=1)
    {
        $retorno                         = new stdClass();
        $retorno->ok                     = 'N';
        $retorno->mensagem               = '';
        
        $token                           = CelCash::Token($galaxid);

        if (!isset($token->token))
        {
            $retorno->mensagem           = "Erro ao buscar token #$token";
            return $retorno;
        }

        $endpoint                       = $token->url . "/customers/$galaxPayId/galaxPayId";

        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->put($endpoint, $payload);
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

        $retorno->mensagem              = CelCash::buscarMensagem($statcode, $response);
        $retorno->ok                    = $statcode == 200 ? "S" : "N";
        $retorno->response              = $response;

        return $retorno;
    }

	public static function getCustomers($query, $galaxId=1)
    {
        $retorno 						= new stdClass();
        $token                           = CelCash::Token($galaxId);

        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/customers";
        $endpoint                       .= "?";
        $endpoint                       .= $query;


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

        if (($statcode != 200)) 
        {
            $retorno->mensagem 		    = CelCash::buscarMensagem($statcode, $response);
            $retorno->statcode          = $statcode;
            return $retorno;
        }

        $retorno                       = $response;

        return $response;
    }

	/**
	 * @deprecated Use getCustomers($query, 2) em vez deste método.
	 */
	public static function getCustomersAgendamento($query)
    {
        return self::getCustomers($query, 2);
    }

    public static function putCustomerIF($cellcash,$cliente,$galaxid=1)
	{
		$customer 											= $cellcash->Customers[0];
		$rcustomer 											= clone $customer;
		
		$galaxPayId											= $customer->galaxPayId;
		
		unset($customer->myId);
		unset($customer->galaxPayId);
		unset($customer->document);
		unset($customer->createdAt);
		unset($customer->updatedAt);
		
		$alterou											= false;
		$emails												= "";
		$phones												= "";
		
		if (isset($customer->emails[0]))
		{			
			$emails											= $customer->emails[0];
		}
		
		if (isset($customer->phones[0]))
		{
			$phones											= $customer->phones[0];
		}
		
		if (isset($customer->ExtraFields))
		{
			$ExtraFields									= $customer->ExtraFields;
		} else {
			$ExtraFields									= array();
		}
		
		unset($customer->emails);
		unset($customer->phones);
		unset($customer->ExtraFields);
		
		if ($emails !="")
		{			
			if ($emails != $cliente->email)
			{
				$alterou 									= true;
				$customer->emails							= array();
				$customer->emails[]							= $cliente->email;
				$rcustomer->emails							= $customer->emails;
			}
		}
		
		if ($phones !="")
		{
			$telefone 											= preg_replace('/\D/', '', $cliente->telefone);
			if ($phones != $telefone)
			{
				$alterou 										= true;
				$customer->phones								= array();
				$customer->phones[]								= $telefone;
				$rcustomer->phones								= $customer->phones;
			}
		}
		
		if ($customer->status != 'active')
		{
			$alterou 										= true;
			$customer->status								= 'active';
		}
		if ($customer->name != $cliente->nome)
		{
			$alterou 										= true;
			$customer->name									= $cliente->nome;
			$rcustomer->name								= $customer->name;
		}
		$cep 												= preg_replace('/\D/', '', $cliente->cep);
		if ($customer->Address->zipCode != $cep)
		{
			$alterou 										= true;
			$customer->Address->zipCode						= $cep;
			$rcustomer->Address->zipCode					= $customer->Address->zipCode;
		}
		if ($customer->Address->street != $cliente->logradouro)
		{
			$alterou 										= true;
			$customer->Address->street						= $cliente->logradouro;
			$rcustomer->Address->street						= $customer->Address->street;
		}
		if ($customer->Address->number != $cliente->numero)
		{
			$alterou 										= true;
			if (Cas::nulltoSpace($customer->Address->number) =="")
			{
				$cliente->numero							= 's/n';
			}
			$customer->Address->number						= $cliente->numero;
			$rcustomer->Address->number						= $customer->Address->number;
		}
		if ($customer->Address->complement != $cliente->complemento)
		{
			$alterou 										= true;
			$customer->Address->complement					= $cliente->complemento;
			$rcustomer->Address->complement					= $customer->Address->complement;
		}
		if ($customer->Address->neighborhood != $cliente->bairro)
		{
			$alterou 										= true;
			$customer->Address->neighborhood				= $cliente->bairro;
			$rcustomer->Address->neighborhood				= $customer->Address->neighborhood;
		}
		if ($customer->Address->city != $cliente->cidade)
		{
			$alterou 										= true;
			$customer->Address->city						= $cliente->cidade;
			$rcustomer->Address->city						= $customer->Address->city;
		}
		if ($customer->Address->state != $cliente->estado)
		{
			$alterou 										= true;
			$customer->Address->state						= $cliente->estado;
			$rcustomer->Address->state						= $customer->Address->state;
		}
		
		if ($cliente->tipo == 'F')
		{
			if ($cliente->sexo == 'M')
			{
				$sexo 											= 'Masculino';
			} else {
				if ($cliente->sexo == 'F')
				{
					$sexo 										= 'Feminino';
				} else {
					$sexo 										= "";
				}
			}			
			
			if (($cliente->data_nascimento != '1900-01-01') and (!is_null($cliente->data_nascimento)))
			{
				list($ano,$mes,$dia)        					= explode("-", $cliente->data_nascimento);
				$data_nascimento            					= $dia . "/" . $mes . "/" . $ano;
				$cp_data_nascimento 							= true;
			} else {
				$data_nascimento								= "";
				$cp_data_nascimento 							= false;
			}
			
			$cp_sexo 											= true;
			
			if (isset($ExtraFields))
			{
				foreach ($ExtraFields as $extrafield)
				{
					switch ($extrafield->tagName) {
						case 'CP_DATA_NASCIMENTO':
							if ($extrafield->tagValue == $data_nascimento)
							{
								$cp_data_nascimento 			= false;
							}
							break;
						case 'CP_SEXO':
							if ($extrafield->tagValue == $sexo)
							{
								$cp_sexo 						= false;
							}
							break;
					}
				}
			}
			if ((($cp_data_nascimento) or ($cp_sexo)) and (($data_nascimento !="") or ($sexo !="")))
			{
				$customer->ExtraFields							= array();
				if (($cp_data_nascimento) and ($data_nascimento !=""))
				{
					$extrafield 				    			= new stdClass();
					$extrafield->tagName						= "CP_DATA_NASCIMENTO";
					$extrafield->tagValue						= $data_nascimento;
					$customer->ExtraFields[] 					= $extrafield;
				}
				if (($cp_sexo) and ($sexo !=""))
				{
					$extrafield 				    			= new stdClass();
					$extrafield->tagName						= "CP_SEXO";
					$extrafield->tagValue						= $sexo;
					$customer->ExtraFields[] 					= $extrafield;
				}
			}
		}
		
		if ($alterou)
		{
			$pcustomer										= CelCash::updateCustomers($customer,$galaxPayId,$galaxid);
		} 
		
		$retorno 				    						= new stdClass();
		$retorno->Customers									= array();
		$retorno->Customers[]								= $rcustomer;
		
		return $retorno;
	}

	public static function storeCliente($id, $galaxId=1)
    {
		$retorno                        = new stdClass();
        $retorno->ok                    = 'N';
        $retorno->mensagem              = '';

        $cliente 			            = \App\Models\Cliente::find($id);

        if (!isset($cliente->id)) 
        {
            $retorno->mensagem          = "Cliente não encontrado. #$cliente_id";
            return $retorno;
        }

		if ($cliente->email == "")
		{
			if ($galaxId == 1)
			{
				$retorno->mensagem          = "Favor informar o email do cliente";
				return $retorno;
			} else {
				$cliente->email				= 'sememail@cartaoamigosaude.com.br';
			}
		}
		
		if ($cliente->cep == "")
		{
			$retorno->mensagem          = "Favor informar o CEP do cliente";
            return $retorno;
		}
		
		if (($cliente->logradouro == "") or 	
		    ($cliente->numero	  == "") or 
            ($cliente->bairro	  == "") or
            ($cliente->cidade 	  == "") or 
            ($cliente->estado	  == ""))
		{
			$retorno->mensagem          = "Favor informar o endereço completo do cliente";
            return $retorno;
		}
		
		$cpfcnpj						= preg_replace('/\D/', '', $cliente->cpfcnpj);
		
        $dados = array(
            'myId'          			=> $cliente->id,
            'name'          			=> $cliente->nome,
            'document'      			=> $cpfcnpj,
            'emails'        			=>  array(
                $cliente->email,
            ),
            'phones' => array(
                preg_replace('/\D/', '',$cliente->telefone),
            ),
            'Address' => array(
                'zipCode'       		=> preg_replace('/\D/', '',$cliente->cep),
                'street'        		=> $cliente->logradouro,
                'number'        		=> trim($cliente->numero),
                'neighborhood'  		=> $cliente->bairro,
                'city'          		=> $cliente->cidade,
                'state'         		=> $cliente->estado,
                'complement'    		=> $cliente->complemento,
            )
        );

		if ($cliente->sexo == 'M')
		{
			$sexo 						= 'Masculino';
		} else {
			$sexo 						= 'Feminino';
		}
		
		if (substr_count($cliente->data_nascimento, '-') == 2)
		{
			list($ano,$mes,$dia)        	= explode("-", $cliente->data_nascimento);
			$data_nascimento            	= $dia . "/" . $mes . "/" . $ano;
						
			 
			$dados['ExtraFields'] 			= array(
					array(
						'tagName'   => 'CP_DATA_NASCIMENTO',
						'tagValue'  => $data_nascimento
					),
					array(
						'tagName'   => 'CP_SEXO',
						'tagValue'  => $sexo
					)
			);
		}
        Log::info("storeCliente dados", ['cliente_id' => $id, 'galaxId' => $galaxId, 'customer' => $dados ]);

        $cstore                     	= self::storeCustomers($dados, $galaxId);

        if ($cstore->ok == 'N')
        {
            Log::error("storeCliente falhou", ['cliente_id' => $id, 'galaxId' => $galaxId, 'mensagem' => $cstore->mensagem, 'response' => $cstore->response ?? null]);
            $retorno->mensagem        	= $cstore->mensagem;
            return $retorno;
        }

        $retorno->ok                  	= 'S';
        $retorno->customers            	= $cstore->response;
        return $retorno;
	}

	/**
	 * @deprecated Use storeCliente($id, 2) em vez deste método.
	 */
	public static function storeClienteAgendamento($id)
    {
        return self::storeCliente($id, 2);
    }

	/**
	 * Busca o cliente na GalaxPay pelo CPF/CNPJ do cliente local.
	 * Se não existir, cria o cadastro. Retorna os dados do cliente com galaxPayId.
	 */
	public static function getStoreCustomers($id, $galaxId = 1)
    {
        $retorno          = new stdClass();
        $retorno->ok      = 'N';
        $retorno->mensagem = '';

        $cliente = \App\Models\Cliente::find($id);

        if (!isset($cliente->id))
        {
            $retorno->mensagem = "Cliente $id não encontrado";
            return $retorno;
        }

        $cpfcnpj = preg_replace('/\D/', '', $cliente->cpfcnpj);

        // Busca o cliente na GalaxPay pelo CPF/CNPJ
        $query    = "documents=$cpfcnpj&startAt=0&limit=1";
        $gcustomer = self::getCustomers($query, $galaxId);

        if (isset($gcustomer->Customers) && count($gcustomer->Customers) > 0)
        {
            // Cliente já existe – atualiza dados se necessário e devolve
            $cellcash     = new stdClass();
            $cellcash->Customers = $gcustomer->Customers;

            $updated = self::putCustomerIF($cellcash, $cliente, $galaxId);

            $retorno->ok        = 'S';
            $retorno->customers = $updated->Customers[0];
            // Conveniência: expõe galaxPayId no nível raiz
            $retorno->galaxPayId = $updated->Customers[0]->galaxPayId;
            return $retorno;
        }

        // Cliente não existe – cria na GalaxPay
        $cstore = self::storeCliente($id, $galaxId);

        if ($cstore->ok == 'N')
        {
            $retorno->mensagem = $cstore->mensagem;
            return $retorno;
        }

        // storeCliente retorna $cstore->customers com o objeto da resposta
        $retorno->ok       = 'S';
        $retorno->customers = $cstore->customers;

        if (isset($cstore->customers->Customer))
        {
            $retorno->galaxPayId = $cstore->customers->Customer->galaxPayId;
        }

        return $retorno;
    }

	/**
	 * @deprecated Use getStoreCustomers($id, 2) em vez deste método.
	 */
	public static function getStoreCustomersAgendamento($id)
    {
        $retorno          = new stdClass();
        $retorno->ok      = 'N';
        $retorno->mensagem = '';

        $cliente = \App\Models\Cliente::find($id);

        if (!isset($cliente->id))
        {
            $retorno->mensagem = "Cliente não encontrado. #$id";
            return $retorno;
        }

        if ($cliente->cep == "")
        {
            $retorno->mensagem = "Favor informar o CEP do cliente";
            return $retorno;
        }

        if (($cliente->logradouro == "") or
            ($cliente->numero     == "") or
            ($cliente->bairro     == "") or
            ($cliente->cidade     == "") or
            ($cliente->estado     == ""))
        {
            $retorno->mensagem = "Favor informar o endereço completo do cliente";
            return $retorno;
        }

        if ($cliente->email == "")
        {
            $cliente->email = 'sememail@cartaoamigosaude.com.br';
        }

        $cpfcnpj = preg_replace('/\D/', '', $cliente->cpfcnpj);
        $query   = "documents=$cpfcnpj&startAt=0&limit=1";

        // Busca na conta GalaxPay de agendamentos (galaxId=2)
        $customer = self::getCustomersAgendamento($query);

        if (isset($customer->Customers[0]))
        {
            $putcustomer      = self::putCustomerIF($customer, $cliente, 2);
            $retorno          = (object) $putcustomer->Customers[0];
            $retorno->ok      = 'S';
            return $retorno;
        }

        if (isset($customer->statcode) && $customer->statcode != 200)
        {
            $retorno->mensagem = 'Erro ao buscar o Cliente';
            if (isset($customer->mensagem) && $customer->mensagem != "")
            {
                $retorno->mensagem = $customer->mensagem;
            }
            return $retorno;
        }

        // Cria o cliente na conta GalaxPay de agendamentos (usa storeCustomersAgendamento → galaxId=2)
        $cstore = self::storeClienteAgendamento($id);

        if ($cstore->ok == 'N')
        {
            $retorno->mensagem = $cstore->mensagem;
            return $retorno;
        }

        $retorno           = $cstore->customers;
        $retorno->ok       = 'S';
        return $retorno;
    }

	/**
	 * @deprecated Use storeCustomers($payload, 2) em vez deste método.
	 */
	public static function storeCustomersAgendamento($payload)
    {
        return self::storeCustomers($payload, 2);
    }
}
