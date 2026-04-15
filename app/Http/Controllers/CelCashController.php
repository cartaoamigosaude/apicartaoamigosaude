<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use App\Jobs\CelCashWebhookJob;
use App\Jobs\CelCashBalancoJob;
use App\Models\Cliente;
use App\Helpers\CelCash;
use App\Helpers\Cas;
use Carbon\Carbon;
use DB;
use stdClass;

class CelCashController extends Controller
{
	
	public function carne_view(Request $request)
	{
		$tipo              					= $request->input('tipo', 'onePDFCharge');
		$galaxid              				= $request->input('galaxid', 1);
		$galaxpayid              			= $request->input('galaxpayid', array());
		$response                           = CelCash::obterCarne($galaxpayid,$galaxid,$tipo);
		return $response;
	}
	
    public function get_token($id)
    {
        $token              = CelCash::Token($id);
        return response()->json($token, 200);
    }

    public function webhook_celcash(Request $request)
    {
        $payload			= (object) $request->all();
		$payload->origem	= 'COB'; /* cobrança */
        Log::info("payload-COB", ['payload' => $payload ]);
        CelCashWebhookJob::dispatch($payload)
                        ->onQueue('default');
                        
        return true;
    }
	
	public function webhook_celcashc(Request $request)
    {
        $payload			= (object) $request->all();
		$payload->origem	= 'CON'; /* Consulta */
        Log::info("payload-CON", ['payload' => $payload ]);
        CelCashWebhookJob::dispatch($payload)
                        ->onQueue('default');
                        
        return true;
    }

    public function get_customer(Request $request)
    {
        $cpfcnpj												= $request->input('cpfcnpj', '');
        $galaxId												= $request->input('galaxId', 1);
        $customer           									= CelCash::GetCustomer($cpfcnpj,$galaxId);
		
		return response()->json($customer, 200);
		 
		$alterou 												= false;
		
		if ((isset($customer->Customers)) and (isset($customer->Customers[0])))
		{
			$customer 											= $customer->Customers[0];
			
			$cliente 		= \App\Models\Cliente::where('cpfcnpj','=',$cpfcnpj)->first();
			if (isset($cliente->id))
			{
				if (isset($customer->Address))
				{
					$galaxPayId									= $customer->galaxPayId;
					unset($customer->myId);
					unset($customer->galaxPayId);
					unset($customer->document);
					unset($customer->createdAt);
					unset($customer->updatedAt);
					
					$emails										= $customer->emails[0];
					$phones										= $customer->phones[0];
					$ExtraFields								= $customer->ExtraFields;
				
					unset($customer->emails);
					unset($customer->phones);
					unset($customer->ExtraFields);
					
					if ($emails != $cliente->email)
					{
						$alterou 								= true;
						$customer->emails						= array();
						$customer->emails[]						= $cliente->email;
					}
					$telefone 									= preg_replace('/\D/', '', $cliente->telefone);
					if ($phones != $telefone)
					{
						$alterou 								= true;
						$customer->phones						= array();
						$customer->phones[]						= $telefone;
					}
					if ($customer->status != 'active')
					{
						$alterou 								= true;
						$customer->status						= 'active';
					}
					if ($customer->name != $cliente->nome)
					{
						$alterou 								= true;
						$customer->name							= $cliente->nome;
					}
					$cep 										= preg_replace('/\D/', '', $cliente->cep);
					if ($customer->Address->zipCode != $cep)
					{
						$alterou 								= true;
						$customer->Address->zipCode				= $cep;
					}
					if ($customer->Address->street != $cliente->logradouro)
					{
						$alterou 								= true;
						$customer->Address->street				= $cliente->logradouro;
					}
					if ($customer->Address->number != $cliente->numero)
					{
						$alterou 								= true;
						if (Cas::nulltoSpace($customer->Address->number) =="")
						{
							$cliente->numero					= 's/n';
						}
						$customer->Address->number				= $cliente->numero;
					}
					if ($customer->Address->complement != $cliente->complemento)
					{
						$alterou 								= true;
						$customer->Address->complement			= $cliente->complemento;
					}
					if ($customer->Address->neighborhood != $cliente->bairro)
					{
						$alterou 								= true;
						$customer->Address->neighborhood		= $cliente->bairro;
					}
					if ($customer->Address->city != $cliente->cidade)
					{
						$alterou 								= true;
						$customer->Address->city				= $cliente->cidade;
					}
					if ($customer->Address->state != $cliente->estado)
					{
						$alterou 								= true;
						$customer->Address->state				= $cliente->estado;
					}
					if ($cliente->sexo == 'M')
					{
						$sexo 									= 'Masculino';
					} else {
						$sexo 									= 'Feminino';
					}
					
					if (($cliente->data_nascimento != '1900-01-01') and (!is_null($cliente->data_nascimento)))
					{
						list($ano,$mes,$dia)        			= explode("-", $cliente->data_nascimento);
						$data_nascimento            			= $dia . "/" . $mes . "/" . $ano;
						$cp_data_nascimento 					= true;
					} else {
						$data_nascimento						= "";
						$cp_data_nascimento 					= false;
					}
					
					$cp_sexo 									= true;
					
					if (isset($ExtraFields))
					{
						foreach ($ExtraFields as $extrafield)
						{
							switch ($extrafield->tagName) {
								case 'CP_DATA_NASCIMENTO':
									 if ($extrafield->tagValue == $data_nascimento)
									 {
										 $cp_data_nascimento 	= false;
									 }
									 break;
								case 'CP_SEXO':
									if ($extrafield->tagValue == $sexo)
									{
										$cp_sexo 				= false;
									}
									break;
							}
						}
					}
					
					if (($cp_data_nascimento) or ($cp_sexo))
					{
						$customer->ExtraFields					= array();
						if (($cp_data_nascimento) and ($data_nascimento !=""))
						{
							$extrafield 				    	= new stdClass();
							$extrafield->tagName				= "CP_DATA_NASCIMENTO";
							$extrafield->tagValue				= $data_nascimento;
							$customer->ExtraFields[] 			= $extrafield;
						}
						if ($cp_sexo)
						{
							$extrafield 				    	= new stdClass();
							$extrafield->tagName				= "CP_SEXO";
							$extrafield->tagValue				= $sexo;
							$customer->ExtraFields[] 			= $extrafield;
						}
					}
				}
				
				if ($alterou)
				{
					 $galaxid									= 1;
					 $pcustomer									= CelCash::updateCustomers($customer,$galaxPayId,$galaxid);
					 return response()->json($pcustomer, 200);
				}
			}
		}
        return response()->json($customer, 200);
    }

    public function list_customers(Request $request)
    {
        $query			    = $request->input('query', '');
        $galaxId			= $request->input('galaxId', 1);

        $customer           = CelCash::ListCustomers($query,$galaxId);

        return response()->json($customer, 200);

    }

    public function get_subscription(Request $request)
    {
        $galaxPayIds        = $request->input('galaxPayIds', '');
        $galaxId			= $request->input('galaxId', 1);
        $view			    = $request->input('view', 'N');

        Log::info("celcash", ['galaxPayIds' => $galaxPayIds ]);

        $subscription       = CelCash::GetSubscription($galaxPayIds,$galaxId);

        if ($view == 'S')
        {
            return response()->json($subscription, 200);
        }

        if ((isset($subscription->Subscriptions)) and (is_array($subscription->Subscriptions)))
        {
            $migrar         = CelCash::CelCashMigrarContrato($subscription->Subscriptions[0],$galaxId);
            return response()->json($migrar, 200);
        }

        return response()->json($subscription, 200);

    }

    public function list_subscriptions(Request $request)
    {
      
        return response()->json('subscriptions', 200);
    }

    /**
     * Testa se já existe uma transação para o cliente na mesma data
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testarTransacaoExistente(Request $request)
    {
        // Validação dos parâmetros de entrada
        $validator = Validator::make($request->all(), [
            'cpf' => 'required|string|size:11',
            'data_vencimento' => 'required|date_format:Y-m-d',
            'valor' => 'required|numeric|min:0.01'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetros inválidos',
                'errors' => $validator->errors()
            ], 400);
        }

        $cpf = $request->input('cpf');
        $dataVencimento = $request->input('data_vencimento');
        $valor = $request->input('valor');
        
        // Converter valor para centavos (formato da API)
        $valorCentavos = intval($valor * 100);

        try {
            // Chamar a função de verificação
            $transacaoExistente = CelCash::verificarTransacaoExistente(
                $cpf,
                $dataVencimento,
                $valor
            );

            return response()->json([
                'success' => true,
                'transacao_existente' => $transacaoExistente->existe,
                'message' => $transacaoExistente->existe 
                    ? 'Transação já existe para este cliente na mesma data de vencimento'
                    : 'Nenhuma transação encontrada para este cliente na data especificada',
                'dados_teste' => [
                    'cpf' => $cpf,
                    'data_vencimento' => $dataVencimento,
                    'valor' => $valor,
                    'valor_centavos' => $valorCentavos
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao testar transação existente', [
                'cpf' => $cpf,
                'data_vencimento' => $dataVencimento,
                'valor' => $valor,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao verificar transação',
                'error' => $e->getMessage()
            ], 500);
        }
    }

	public function listarTransacoesStatus(Request $request)
    {
		
		$payload 						= new stdClass();
		$payload->updateStatusFrom 		= $request->input('updateStatusFrom','');
		$payload->updateStatusTo 		= $request->input('updateStatusTo','');
		$payload->start					= $request->input('start',0);
		$payload->limite				= $request->input('limite',100);
		$payload->atualizar				= $request->input('atualizar','N');
		
		if ($payload->updateStatusFrom == "")
		{
			$payload->updateStatusFrom   = date('Y-m-d');
		}
		
		if ($payload->updateStatusTo == "")
		{
			$payload->updateStatusTo   = date('Y-m-d');
		}
		
		$resultado 						= Cas::listarTransacoesStatus($payload);
		return response()->json($resultado,200);
	}
		
    /**
     * Lista transações por CPF a partir de uma data específica
     */
    public function listarTransacoesPorData(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'cpf' 			=> 'required|string|size:11',
             	'contrato_id' 	=> 'nullable|integer|exists:contratos,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $cpf 			= $request->input('cpf');
         	$contratoId 	= $request->input('contrato_id');
            $limite 		= $request->input('limite', 100);

			$query                              = "documents=" .  $cpf . "&startAt=0&limit=1";
		
			$customer                           = CelCash::getCustomers($query);
		
			if (!isset($customer->Customers[0]))
			{
				return response()->json($customer,200);
			}
			
			if (!isset($customer->Customers[0]->galaxPayId))
			{
				return response()->json($customer,200);
			}
			
            $resultado = CelCash::listarTransacoesPorData($customer->Customers[0]->galaxPayId, $limite);

            if ($resultado->statcode == 200) {
                // Extrai e formata os dados específicos das transações
                $dadosExtraidos = CelCash::extrairDadosTransacoes($resultado->response);
                /*
                return response()->json([
                    'success' => true,
                    'data' => $dadosExtraidos,
                    'total_encontradas' => count($dadosExtraidos),
                    'dados_originais' => $resultado->response // Mantém dados originais para referência
                ], 200);
				*/
				 // Se contrato_id foi fornecido, faz o balanço com a tabela parcelas
				if ($contratoId) 
				{
					$balanco 			= CelCash::balancearDadosTransacoesParcelas($contratoId, $dadosExtraidos);
					return response()->json($balanco,200);	
				}
				return response()->json($dadosExtraidos,200);		
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao consultar transações',
                    'status_code' => $resultado->statcode
                ], $resultado->statcode);
            }

        } catch (\Exception $e) {
            Log::error('Erro ao listar transações por data: ' . $e->getMessage(), [
                'cpf' => $request->input('cpf'),
                'data_inicio' => $request->input('data_inicio'),
                'limite' => $request->input('limite')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao listar transações',
                'error' => $e->getMessage()
            ], 500);
        }
    }
	
	public function ajustarTransacoesParcelasPagas(Request $request)
	{
		
		$sql 					= "SELECT 
										contratos.id AS contrato_id,
										clientes.cpfcnpj as cpf
									FROM contratos
									JOIN clientes 
										ON contratos.cliente_id = clientes.id
									JOIN parcelas 
										ON parcelas.contrato_id = contratos.id
									WHERE contratos.status = 'active'
									  AND contratos.avulso = 'S'
									  AND contratos.balanco = 'N'
									  AND parcelas.galaxPayId > 0
									GROUP BY contratos.id, clientes.cpfcnpj";
									
		$contratos				= DB::connection('mysql')->select($sql);
		
		foreach ($contratos as $contrato)
		{
			 CelCashBalancoJob::dispatch($contrato->contrato_id, $contrato->cpf)
                        ->onQueue('ajuste');
		}
	
		return true;
	
	}
	
	public function transacoesData(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'dataInicio' => 'required|date_format:Y-m-d',
			'dataFim'    => 'required|date_format:Y-m-d'
		]);

		if ($validator->fails()) 
		{
			return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
		}

		try {
			
			$dataInicio 				= Carbon::createFromFormat('Y-m-d', $request->dataInicio);
			$dataFim 					= Carbon::createFromFormat('Y-m-d', $request->dataFim);

			// Valida se a data de início não é posterior à data fim
			if ($dataInicio->gt($dataFim)) 
			{
				return response()->json(['mensagem' => 'Data de início deve ser anterior ou igual à data fim'], 422);
			}

			$resultado 					= [];
			$dataAtual 					= $dataInicio->copy();

			// Itera por todas as datas do período
			while ($dataAtual->lte($dataFim)) 
			{
				$status					= 'pendente';
				$totalTransacoes		= 0;
				$totalConciliadas		= 0;
				$totalDivergencias		= 0;
				
				$tdata 					= \App\Models\TransacaoData::where('data','=',$dataAtual->format('Y-m-d'))->first();
				
				if (isset($tdata->id))
				{
					$status 			= $tdata->status;
					$totalTransacoes	= $tdata->total;
					$totalConciliadas	= $tdata->conciliados;
					$totalDivergencias	= $tdata->divergentes;
				} 
				
				$resultado[] = [
					'data' 				=> $dataAtual->format('Y-m-d'),
					'status' 			=> $status,
					'totalTransacoes' 	=> $totalTransacoes,
					'totalConciliadas' 	=> $totalConciliadas,
					'totalDivergencias' => $totalDivergencias
				];

				$dataAtual->addDay();
			}

			return response()->json($resultado, 200);

		} catch (\Exception $e) {
			return response()->json(['mensagem' => 'Erro interno do servidor'], 500);
		}
	}
	
	
	public function transacoesConciliar(Request $request)
	{
		$payload 						= new stdClass();
		$payload->updateStatusFrom 		= $request->input('data',date('Y-m-d'));
		$payload->updateStatusTo 		= $request->input('data',date('Y-m-d'));
		$payload->start					= 0;
		$payload->limite				= 100;
		$payload->atualizar				= 'N';
		$resultado 						= Cas::listarTransacoesStatus($payload);
		$resultado->sucesso				= true;
		return response()->json($resultado,200);
	}
	
	public function transacoesDivergencias(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'dataInicio' => 'required|date_format:Y-m-d',
			'dataFim'    => 'required|date_format:Y-m-d'
		]);
		
		if ($validator->fails()) {
			return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
		}

		$divergencias 					= DB::connection('mysql')
										  ->table('transacoes_divergencias')
										  ->select('transacoes_divergencias.id',
												  'transacoes_data.data',
												  'transacoes_divergencias.payday',
												  'transacoes_divergencias.value',
												  'transacoes_divergencias.data_pagamento',
												  'transacoes_divergencias.data_baixa',
												  'transacoes_divergencias.contrato_id',
												  'transacoes_divergencias.parcela_id',
												  'transacoes_divergencias.galaxPayId',
												  'transacoes_divergencias.cgalaxPayId',
												  'clientes.cpfcnpj',
												  'clientes.nome',
												  'transacoes_divergencias.situacao')
										  ->join('transacoes_data','transacoes_data.id','=','transacoes_divergencias.transacao_data_id')
										  ->join('contratos','contratos.id','=','transacoes_divergencias.contrato_id')
										  ->join('clientes','clientes.id','=','contratos.cliente_id')
										  ->whereBetween('transacoes_data.data', [$request->dataInicio, $request->dataFim])
										  ->orderBy('transacoes_data.data','asc')
										  ->orderBy('clientes.cpfcnpj','asc')
										  ->get();

		return response()->json($divergencias, 200);
	}
	
	public function atualizarDivergencia(Request $request, $id)
	{
		
		$mensagem 						= Cas::atualizarDivergencia($id);
		return response()->json(['mensagem' => $mensagem], 422);
	}

}