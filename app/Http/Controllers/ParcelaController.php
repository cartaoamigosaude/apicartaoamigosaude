<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use App\Models\Parcela;
use App\Helpers\Cas;
use App\Helpers\CelCash;
use App\Helpers\ChatHot;
use Carbon\Carbon;
use DB;
use stdClass;

class ParcelaController extends Controller
{
	public function show(Request $request, $id)
    {
		
		
		
	}
	
	public function observacao(Request $request, $id)
    {
		
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$parcela 				                = \App\Models\Parcela::find($id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		$parcela->observacao					= $request->observacao;
		
		$parcela->save();
		
		return response()->json($parcela->id, 200);
		
	}
	
	public function pagamento(Request $request, $id)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
            'data_pagamento'	=> 'required|date',
            'valor' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
        ]);
		
		if ($validator->fails()) 
		{
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$parcela 				                = \App\Models\Parcela::with('contrato')->find($id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		if (!is_null($parcela->data_pagamento))
		{
			return response()->json(['mensagem' => 'A parcela já foi paga. Não pode ser para novamente.'], 404);
		}
		
		if (!isset($request->forma_pagamento))
		{
			$request->forma_pagamento			= 'Dinheiro';
		}
		
		if (!isset($request->observacao))
		{
			$request->observacao				= '';
		}

		DB::beginTransaction();
		
		$parcela->observacao					= $request->observacao;
		$parcela->data_pagamento				= substr($request->data_pagamento,0,10);
		$parcela->valor_pago					= str_replace(",",".",$request->valor);
		$parcela->statusDescription				= 'Paga fora do sistema | Forma: ' . $request->forma_pagamento;
		
		if ($parcela->valor_pago > $parcela->valor)
		{
			$parcela->juros 					= $parcela->valor_pago - $parcela->valor; 
		} else {
			if ($parcela->valor_pago < $parcela->valor)
			{
				$parcela->desconto 				= $parcela->valor - $parcela->valor_pago;
			}
		}
		
		if (($parcela->cgalaxPayId == 0) and ($parcela->galaxPayId==0))
		{
			if ($parcela->save())
			{
				DB::commit();
				return response()->json($parcela->id, 200);
			}
		} else {
			if ($parcela->contrato->tipo == 'J')
			{
				$cancelar 						= CelCash::cancelCharges($parcela->contrato->galaxPayId,1,"galaxPayId");
			} else {
				if ($parcela->contrato->avulso == 'S')
				{
					$cancelar 					= CelCash::cancelCharges($parcela->cgalaxPayId,1,"galaxPayId");
				} else {
					$cancelar 					= CelCash::cancelTransaction($parcela->galaxPayId,1,"galaxPayId");
								
				}
			}
			if ((isset($cancelar->statcode)) and ($cancelar->statcode ==200))
			{
				if ($parcela->save())
				{
					DB::commit();
					return response()->json($parcela->id, 200);
				}
			}
		}
		
		DB::rollBack();
		return response()->json(['mensagem' => 'Ocorreu erro na tentativa de pagamento. Registro do pagamento não realizado'], 404);
	}
	
	public function pagamentoFora(Request $request, $id)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
            'data_pagamento'	=> 'required|date',
            'valor' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
        ]);
		
		if ($validator->fails()) 
		{
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$parcela 				                = \App\Models\Parcela::with('contrato')->find($id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		if (!is_null($parcela->data_pagamento))
		{
			return response()->json(['mensagem' => 'A parcela já foi paga. Não pode ser para novamente.'], 404);
		}
		
		if (!isset($request->forma_pagamento))
		{
			$request->forma_pagamento			= 'Dinheiro';
		}
		
		if (!isset($request->observacao))
		{
			$request->observacao				= '';
		}

		DB::beginTransaction();
		
		$parcela->observacao					= $request->observacao;
		$parcela->data_pagamento				= substr($request->data_pagamento,0,10);
		$parcela->valor_pago					= str_replace(",",".",$request->valor);
		$parcela->statusDescription				= 'Paga fora do sistema | Forma: ' . $request->forma_pagamento;
		
		if ($parcela->valor_pago > $parcela->valor)
		{
			$parcela->juros 					= $parcela->valor_pago - $parcela->valor; 
		} else {
			if ($parcela->valor_pago < $parcela->valor)
			{
				$parcela->desconto 				= $parcela->valor - $parcela->valor_pago;
			}
		}
		
		if (($parcela->cgalaxPayId == 0) and ($parcela->galaxPayId==0))
		{
			if ($parcela->save())
			{
				DB::commit();
				return response()->json($parcela->id, 200);
			}
		} else {
			$payload               				= new stdClass;
			$payload->payedOutsideGalaxPay		= true;
			$payload->additionalInfo			= $parcela->statusDescription;
			
			if (($parcela->contrato->tipo == 'J') or ($parcela->contrato->avulso == 'S'))
			{
				$alterar 						= CelCash::alterarCharges($parcela->cgalaxPayId,$payload,"galaxPayId");
			} else {
				$alterar 						= CelCash::alterarTransaction($parcela->galaxPayId,$payload,"galaxPayId");
			}
			
			if (!isset($alterar->error))
			{
				$parcela->save();
				DB::commit();
				return response()->json($alterar, 200);
			} else {
				DB::rollBack();
				return response()->json(['mensagem' => $alterar->error->message], 404);
			}
			
		}
		
		DB::rollBack();
		return response()->json(['mensagem' => 'Ocorreu erro na tentativa de pagamento. Registro do pagamento não realizado'], 404);
	}
	
	public function cancelar_pagamento(Request $request, $id)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$parcela 				                = \App\Models\Parcela::find($id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		if (is_null($parcela->data_pagamento))
		{
			return response()->json(['mensagem' => 'A parcela ainda não foi paga'], 404);
		}
		
		$parcela->data_pagamento				= null;
		$parcela->valor_pago					= 0;
		$parcela->statusDescription				= '';
		$parcela->juros 						= 0; 
		$parcela->desconto 						= 0;
		$parcela->save();
		
		return response()->json($parcela->id, 200);
	}
	
	public function cancelar_baixa(Request $request, $id)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$parcela 				                = \App\Models\Parcela::find($id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		if (is_null($parcela->data_baixa))
		{
			return response()->json(['mensagem' => 'A parcela ainda não foi baixada'], 404);
		}
		
		$parcela->data_baixa				= null;
		$parcela->save();
		
		return response()->json($parcela->id, 200);
	}
	
	public function sendCollection(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.contratos'))
        {
            return response()->json(['mensagem' => 'Não autorizado.'], 403);
        }

        $parcela = \App\Models\Parcela::with('contrato')->find($id);

        if (!isset($parcela->id))
        {
            return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
        }

        if (!is_null($parcela->data_pagamento))
        {
            return response()->json(['mensagem' => 'A parcela já está paga. Não é possível enviar cobrança.'], 422);
        }

        if (!is_null($parcela->data_baixa))
        {
            return response()->json(['mensagem' => 'A parcela já está baixada. Não é possível enviar cobrança.'], 422);
        }

        // Se ainda não tem cobrança gerada no CelCash, gera agora
        if ($parcela->galaxPayId == 0 && $parcela->cgalaxPayId == 0)
        {
            $charges = CelCash::storeContratoCharges($parcela->id);

            if (($charges->ok == 'S') && isset($charges->Charge))
            {
                $scharge = CelCash::updateContratoWithCharge($charges->Charge);
                if ($scharge->ok !== 'S')
                {
                    return response()->json(['mensagem' => $scharge->mensagem ?? 'Erro ao registrar cobrança.'], 422);
                }
            } else {
                return response()->json(['mensagem' => $charges->mensagem ?? 'Erro ao gerar cobrança no CelCash.'], 422);
            }

            $parcela->refresh();
        }

        return response()->json([
            'mensagem' => 'Cobrança enviada com sucesso.',
            'id'       => $parcela->id,
        ], 200);
    }

	public function cancelar_cobranca(Request $request, $id)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$parcela 				                = \App\Models\Parcela::with('contrato')->find($id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		if (!is_null($parcela->data_pagamento))
		{
			return response()->json(['mensagem' => 'A parcela já foi paga. Cancelamento não permitido!'], 404);
		}
		
		if (!is_null($parcela->data_baixa))
		{
			return response()->json(['mensagem' => 'A parcela já foi baixada. Cancelamento não permitido!'], 404);
		}
		
		$observacao                          			= $request->input('observacao', '');
		
		if ($parcela->contrato->tipo == 'J')
		{
			if ($parcela->contrato->galaxPayId > 0)
			{
				$cancelar 								= CelCash::cancelCharges($parcela->contrato->galaxPayId,1,"galaxPayId");
			} else {
				$parcela->data_baixa					= date('Y-m-d');
				$parcela->statusDate					= date('Y-m-d H:m:s');
				$parcela->observacao 					= $observacao;
				$parcela->boletobankNumber				= 0;
				$parcela->save();
				return response()->json($parcela->id, 200);
			}
		} else {
			if ($parcela->contrato->avulso == 'S')
			{
				if ($parcela->cgalaxPayId > 0)
				{
					$cancelar 								= CelCash::cancelCharges($parcela->cgalaxPayId,1,"galaxPayId");
				} else {
					$parcela->data_baixa					= date('Y-m-d');
					$parcela->statusDate					= date('Y-m-d H:m:s');
					$parcela->observacao 					= $observacao;
					$parcela->boletobankNumber				= 0;
					$parcela->save();
					return response()->json($parcela->id, 200);
				}
			} else {
				if ($parcela->galaxPayId > 0)
				{
					$cancelar 								= CelCash::cancelTransaction($parcela->galaxPayId,1,"galaxPayId");
				} else {
					$parcela->data_baixa					= date('Y-m-d');
					$parcela->statusDate					= date('Y-m-d H:m:s');
					$parcela->observacao 					= $observacao;
					$parcela->boletobankNumber				= 0;
					$parcela->save();
					return response()->json($parcela->id, 200);
				}
			}
		}
		
		if ((isset($cancelar->statcode)) and ($cancelar->statcode ==200))
		{
			$parcela->data_baixa					= date('Y-m-d');
			$parcela->statusDate					= date('Y-m-d H:m:s');
			$parcela->observacao 					= $observacao;
			$parcela->boletobankNumber				= 0;
			//$parcela->galaxPayId					= 0;
			$parcela->save();
			return response()->json($parcela->id, 200);
		}
		
		if ((isset($cancelar->statcode)) and ($cancelar->statcode ==404))
		{
			$parcela->data_baixa					= date('Y-m-d');
			$parcela->statusDate					= date('Y-m-d H:m:s');
			$parcela->observacao 					= $observacao;
			$parcela->boletobankNumber				= 0;
			$parcela->save();
			return response()->json($parcela->id, 200);
		}
		
		return response()->json(['mensagem' => 'Cancelamento não foi realizado', 'response'=> $cancelar], 404);
	}
	
	public function baixar(Request $request, $id)
    {
	}
	
	public function ajustar(Request $request)
    {
		
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
		   'id' 				=> 'required|exists:parcelas,id',
           'data_vencimento'	=> 'required|date',
           'valor' 				=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$parcela 				                = \App\Models\Parcela::find($request->id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		$parcela->data_vencimento	    		= $request->data_vencimento;
		$parcela->valor							= str_replace(",",".",$request->valor); 
		$parcela->save();
		
		return response()->json($parcela->id, 200);
	}
	
	public function store(Request $request)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar parcelas.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'contrato_id' 		=> 'required|exists:contratos,id',
            'nparcela' 			=> 'required',
            'data_vencimento'	=> 'required|date',
            'valor' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$retorno 				    			= new stdClass();
		$retorno->ok							= 'N';
		$retorno->mensagem 						= "";
		
		
		if ((!isset($request->repete)) or (!is_numeric($request->repete)))
		{
			$request->repete					= 0;
		}
		
		$parcela 				                = \App\Models\Parcela::where('contrato_id','=',$request->contrato_id)  
																	 ->where('data_vencimento','=',$request->data_vencimento)  
																	 ->where('data_pagamento','=',null)
																	 ->where('data_baixa','=',null)
																	 ->first();
                                                                  
        if (isset($parcela->id))
        {
			$retorno->mensagem 					= "Já existe uma parcela com a mesma data de vencimento.";
			return response()->json($retorno, 404);
		}
		
		$parcela 				                = \App\Models\Parcela::where('contrato_id','=',$request->contrato_id)  
																	 ->where('nparcela','=',$request->nparcela)  
																	 ->where('data_pagamento','=',null)
																	 ->where('data_baixa','=',null)
																	 ->first();
                                                                  
        if (isset($parcela->id))
        {
			$retorno->mensagem 					= "Já existe uma parcela com o mesmo número de parcela.";
			return response()->json($retorno, 404);
		}
		
		DB::beginTransaction();
		$rparcela 								= new \App\Models\Parcela();
		$rparcela->contrato_id					= $request->contrato_id;
		$rparcela->nparcela						= $request->nparcela;
		$rparcela->data_vencimento	    		= $request->data_vencimento;
		$rparcela->data_pagamento				= null;
		$rparcela->data_baixa					= null;
		$rparcela->taxa							= 0;
		$rparcela->valor						= str_replace(",",".",$request->valor); 
		$rparcela->desconto						= 0;
		$rparcela->juros						= 0;
		$rparcela->valor_pago					= 0;
		$rparcela->galaxPayId					= 0;
		$rparcela->boletobankNumber				= 0;
		$rparcela->payedOutsideGalaxPay			= false;
		$rparcela->statusDate					= null;
		$rparcela->datetimeLastSentToOperator	= null;
					
		$rparcela->status						= "";
		$rparcela->statusDescription			= "";
		$rparcela->additionalInfo				= "";
		$rparcela->subscriptionMyId				= "";
		$rparcela->boletopdf					= "";
		$rparcela->boletobankLine				= "";
		$rparcela->boletobarCode				= "";
		$rparcela->boletobankEmissor			= "";
		$rparcela->boletobankAgency				= "";
		$rparcela->boletobankAccount			= "";
		$rparcela->pixreference					= "";
		$rparcela->pixqrCode					= "";
		$rparcela->piximage						= "";
		$rparcela->pixpage						= "";
		$rparcela->tid							= "";
		$rparcela->authorizationCode			= "";
		$rparcela->cardOperatorId				= "";
		$rparcela->conciliationOccurrences		= "{}";
		$rparcela->creditCard					= "{}";
		
		if ($rparcela->save())
		{
			DB::commit();
			
			$acontrato 				                    = \App\Models\Contrato::find($request->contrato_id);
                                                                  
			if (isset($acontrato->id))
			{
				$acontrato->valor 						= $rparcela->valor;
				$acontrato->status 						= 'active';
				
				if ($acontrato->save())
				{
					$charges							= CelCash::storeContratoCharges($rparcela->id);
					if (($charges->ok == 'S') and (isset($charges->Charge)))
					{
						$scharge 						= CelCash::updateContratoWithCharge($charges->Charge);
						if ($scharge->ok == 'S')
						{
							$retorno->ok				= 'S';
							$retorno->mensagem 			= "Parcela inserida com sucesso";
							if ($request->repete > 0)
							{
								$retorno->repete 		= $this->repeteParcela($request->contrato_id,$rparcela->valor,$request->repete);
							}
							return response()->json($retorno, 200);
						} else {
							DB::table('parcelas')->where('id','=',$rparcela->id)->delete();	
							$retorno->ok				= 'N';
							$retorno->mensagem 			= $scharge->mensagem;
							return response()->json($retorno, 404);							
						}
					} else {
						DB::table('parcelas')->where('id','=',$rparcela->id)->delete();	
						$retorno->ok					= 'N';
						$retorno->mensagem 				= $charges->mensagem;
						return response()->json($retorno, 404);			
					}
				} else {
					DB::table('parcelas')->where('id','=',$rparcela->id)->delete();	
					$retorno->ok						= 'N';
					$retorno->mensagem 					= "Não foi possivel atualizar o contrato";
					return response()->json($retorno, 404);			
				}
			} else {
				DB::table('parcelas')->where('id','=',$rparcela->id)->delete();	
				$retorno->ok							= 'N';
				$retorno->mensagem 						= "Contrato da parcela não encontrado";
				return response()->json($retorno, 404);	
			}
		}
					
		$retorno->ok									= 'N';
		$retorno->mensagem 								= "Ocorreu um problema não identificado na tentativa de inserir a parcela. Entre em contato com o suporte.";
		return response()->json($retorno, 404);
	}
	
	public function repeteParcela($contrato_id,$valor,$repete)
    {
		
		$parcelas 										= array();
		
		$parcela 										= \App\Models\Parcela::select('id','nparcela','data_vencimento')
																		->where('contrato_id','=',$contrato_id)
																		->orderBy('nparcela','desc') 
																		->first();
		if (isset($parcela->id))
		{
			$nparcela 									= $parcela->nparcela;
			$vencimento 								= Carbon::createFromFormat('Y-m-d', $parcela->data_vencimento);
			$dataVencimento 							= clone $vencimento;	
			$dataVencimento->addMonth();
			
			for ($i = 1; $i <= $repete; $i++) 
			{	
				$retorno 				    			= new stdClass();
				$rparcela 								= new \App\Models\Parcela();
				$rparcela->contrato_id					= $contrato_id;
				$rparcela->nparcela						= $nparcela + $i;
				$rparcela->data_vencimento				= $dataVencimento->format('Y-m-d');			 
				$rparcela->data_pagamento				= null;
				$rparcela->data_baixa					= null;
				$rparcela->taxa							= 0;
				$rparcela->valor						= $valor;				
				$rparcela->desconto						= 0;
				$rparcela->juros						= 0;
				$rparcela->valor_pago					= 0;
				$rparcela->galaxPayId					= 0;
				$rparcela->boletobankNumber				= 0;
				$rparcela->payedOutsideGalaxPay			= false;
				$rparcela->statusDate					= null;
				$rparcela->datetimeLastSentToOperator	= null;
				$rparcela->status						= "";
				$rparcela->statusDescription			= "";
				$rparcela->additionalInfo				= "";
				$rparcela->subscriptionMyId				= "";
				$rparcela->boletopdf					= "";
				$rparcela->boletobankLine				= "";
				$rparcela->boletobarCode				= "";
				$rparcela->boletobankEmissor			= "";
				$rparcela->boletobankAgency				= "";
				$rparcela->boletobankAccount			= "";
				$rparcela->pixreference					= "";
				$rparcela->pixqrCode					= "";
				$rparcela->piximage						= "";
				$rparcela->pixpage						= "";
				$rparcela->tid							= "";
				$rparcela->authorizationCode			= "";
				$rparcela->cardOperatorId				= "";
				$rparcela->conciliationOccurrences		= "{}";
				$rparcela->formapagamento				= 'boleto';
				$rparcela->creditCard					= "{}";
				if ($rparcela->save())
				{
					$charges							= CelCash::storeContratoCharges($rparcela->id);
					if (($charges->ok == 'S') and (isset($charges->Charge)))
					{
						$scharge 						= CelCash::updateContratoWithCharge($charges->Charge);
						$retorno->ok					= $scharge->ok;
					} else {
						$retorno->ok					= $charges->ok;	
					}
				} else {
					$retorno->ok						= "N";
				}
				$retorno->nparcela 						= $rparcela->nparcela;
				$parcelas[]								= $retorno;
				$dataVencimento->addMonth();
			}
		}
		
		return $parcelas;
		
	}
	
	public function destroy(Request $request, $id)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['mensagem' => 'Não autorizado para pagar parcelas.'], 403);
        }
		
		$parcela 				                = \App\Models\Parcela::find($id);
                                                                  
        if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		if (!is_null($parcela->data_pagamento))
		{
			return response()->json(['mensagem' => 'A parcela já foi paga, não pode ser excluída'], 404);
		}
		
		$parcela->delete();
		
		return response()->json($parcela->id, 200);
	}
	
	public function update(Request $request, $id)
    {
	}
	
	public function cancel(Request $request)
	{
		$query									= DB::connection('mysql')
													->table('parcelas')
													->where('parcelas.status','=','cancel')
													->whereNull('parcelas.data_baixa');

		$parcelas								= $query->get();

		foreach ($parcelas as $parcela)
		{
			$cancelar                          = \App\Models\Parcela::find($parcela->id);
			if (isset($cancelar->id))
			{	
				$cancelar->data_baixa         = substr($parcela->statusDate,0,10);
				$cancelar->save();
			}
		}

		return count($parcelas);

	}

	public function nova_parcela(Request $request)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar parcelas.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
           'contrato_id' 		=> 'required|exists:contratos,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 404);
        }
		
		$retorno 				    			= new stdClass();
		$retorno->nparcela						= 0;
		$retorno->data_vencimento				= "";
		$retorno->valor 						= 0;
		$retorno->qtde 							= 0;
		$retorno->mensagem						= "";
		
		if (\App\Models\Parcela::where('contrato_id','=',$request->contrato_id)->count() ==0)
		{
			$retorno->nparcela 					= 1;
			$retorno->data_vencimento 			= date('Y-m-d');
		} else {
			$parcela 							= \App\Models\Parcela::select('id','nparcela','data_pagamento','data_vencimento','contrato_id','valor')
														 ->where('contrato_id','=',$request->contrato_id)
														 ->orderBy('nparcela','desc') 
														 ->first();
			if (!isset($parcela->id))
			{
				$retorno->nparcela 				= 1;
				$retorno->data_vencimento 		= date('Y-m-d');
			} else {
				//if (!is_null($parcela->data_pagamento))
				//{
					list($ano,$mes,$diav)       = explode("-",$parcela->data_vencimento);
					$retorno->nparcela 			= $parcela->nparcela + 1;
					$data_vencimento			= Carbon::parse($parcela->data_vencimento)->addMonth()->format('Y-m-d');
					list($ano,$mes,$dia)        = explode("-",$data_vencimento);
					$data_vencimentov			= $ano . "-" . $mes . "-" . $diav;
					$retorno->valor 			= $parcela->valor;
					$retorno->data_vencimento	= $data_vencimentov;
				//} else {
				//	$retorno->mensagem 			= 'A última parcela ' . $parcela->nparcela . ' ainda não foi quitada. Não é permitido criar parcelas';
				//}
			}
		}
		
		if ($retorno->nparcela > 0)
		{
			$contrato            			    			= \App\Models\Contrato::select('id','tipo')
																		->find($request->contrato_id);
		
			if ((isset($contrato->id)) and ($contrato->tipo == 'J'))
			{
				 $retorno->valor 							= 0;
				 $titulares 								= \App\Models\Beneficiario::where('contrato_id','=',$request->contrato_id)
																  ->where('tipo','=','T')
																 // ->where('desc_status','=','ATIVO')
																  ->where('ativo','=',1)
																  ->get();
				 foreach ($titulares as $titular)
				 {
					 
					$plano              					= \App\Models\Plano::select('preco')->find($titular->plano_id);
				
					if (isset($plano->preco))
					{
						$retorno->valor  					= $retorno->valor +  $plano->preco;
					}
					$retorno->qtde ++;
				 }
			}
		}
		
		$retorno->valor										= number_format($retorno->valor,2);
		$retorno->valor 									= str_replace(',','',$retorno->valor);
		$retorno->valor 									= str_replace('.',',',$retorno->valor);
		return response()->json($retorno, 200);
	}

    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar parcelas.'], 403);
        }

        $limite              					= $request->input('limite', 12);
		$orderby             					= $request->input('orderby', 'parcelas.id');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');
        $contrato_id                            = $request->input('contrato_id', 0);
		$situacao                            	= $request->input('situacao','');
		
        $query									= DB::connection('mysql')
														->table('parcelas')
														->select(
															     'parcelas.id',
                                                                 'parcelas.contrato_id',
                                                                 'parcelas.galaxPayId',
                                                                 'parcelas.valor',
                                                                 'parcelas.nparcela',
                                                                 'parcelas.data_vencimento',
                                                                 'parcelas.data_pagamento',
                                                                 'parcelas.data_baixa',
                                                                 'parcelas.valor_pago',
                                                                 'parcelas.boletobankNumber',
                                                                 'parcelas.statusDescription',
																 'parcelas.negociar',
																 'parcelas.observacao',
																 'parcelas.reasonDenied'
                                                                )
                                                        ->where('parcelas.contrato_id','=',$contrato_id);
														
		if ($situacao !='')
		{
			switch ($situacao) 
			{
				case "Paga":
					$query->where("parcelas.data_pagamento","<>", null);
					break;
				case "Baixada":
					$query->where("parcelas.data_baixa","<>", null);
					$query->where("negociar","=", 'N');
					break;
				case "Negociada":
					$query->where("parcelas.data_baixa","<>", null);
					$query->where("negociar","=", 'S');
					break;
				case "Vencida":
					$query->where("parcelas.data_pagamento","=", null);
					$query->where("parcelas.data_baixa","=", null);
					$query->where("parcelas.data_vencimento","<=", date('Y-m-d'));
					break;
				case "Á vencer":
					$query->where("parcelas.data_pagamento","=", null);
					$query->where("parcelas.data_baixa","=", null);
					$query->where("parcelas.data_vencimento",">", date('Y-m-d'));
					break;
			}
		}
		
        $query->orderBy($orderby,$direction);
		
        $parcelas								= $query->paginate($limite);

        $parcelas->getCollection()->transform(function ($parcela) 
        {
			$desabilitar 					= false;
			$parcela->reasonDenied			= Cas::nulltoSpace($parcela->reasonDenied);
            $parcela->psituacao 			= Cas::obterSituacaoParcela($parcela->data_vencimento,$parcela->data_pagamento,$parcela->data_baixa);
			
			if ($parcela->nparcela == 1)
			{
				if (Cas::ecartaoContrato($parcela->contrato_id))
				{
					$desabilitar 			= true;
				}
			}
			
			if (!$desabilitar)
			{
				$parcela->podepagar   		    = Cas::podepagarParcela($parcela->psituacao);
				$parcela->podebaixar		    = Cas::podebaixarParcela($parcela->psituacao,$parcela->galaxPayId);
				$parcela->podeebaixa		    = Cas::podeebaixaParcela($parcela->psituacao,$parcela->id,$parcela->contrato_id);
				$parcela->podeepagar		    = Cas::podeepagarParcela($parcela->psituacao,$parcela->id,$parcela->contrato_id);
				$parcela->podeexcluir           = Cas::podeexcluirParcela($parcela->contrato_id,$parcela->id,$parcela->data_pagamento,$parcela->data_baixa,$parcela->galaxPayId,$parcela->nparcela);
				$parcela->podeeditar            = Cas::podeeditarParcela($parcela->psituacao,$parcela->galaxPayId);
				$parcela->podeboleto            = Cas::podeboletoParcela($parcela->psituacao,$parcela->galaxPayId);
				$parcela->podeeboleto           = Cas::podeeboletoParcela($parcela->psituacao,$parcela->galaxPayId,$parcela->contrato_id);
				$parcela->podenegociar          = Cas::podenegociarParcela($parcela->psituacao);
				$parcela->podecancel            = Cas::podecancelCobranca($parcela->psituacao,$parcela->galaxPayId);
			} else {
				$parcela->podepagar   		    = "N";
				$parcela->podebaixar		    = "N";
				$parcela->podeebaixa		    = "N";
				$parcela->podeepagar		    = "N";
				$parcela->podeexcluir           = "N";
				$parcela->podeeditar            = "N";
				$parcela->podeboleto            = "N";
				$parcela->podeeboleto           = "N";
				$parcela->podenegociar          = "N";
				$parcela->podecancel            = "N";
			}
			
            if ((!is_null($parcela->data_pagamento)) and ($parcela->valor_pago > 0))
			{
				$parcela->valor 				= $parcela->valor_pago;
			}
            if ($parcela->psituacao == 'Paga')
            {
                $parcela->formapagamento    	= $parcela->statusDescription;
            }  
			if (($parcela->psituacao == 'Baixada') and ($parcela->negociar =='S'))
            {
                $parcela->psituacao    			= 'Negociada';
            } 			
            return $parcela;
         });
                    
         return response()->json($parcelas, 200);

    }

    public function filtro(Request $request)
    {
        if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar parcelas.'], 403);
        }

        $payload								= (object) $request->all();

        $limite              					= $request->input('limite', 12);
		$orderby             					= $request->input('orderby', 'parcelas.id');
		$direction          					= $request->input('direction', 'asc');
		$pulou                                  = $request->input('pulou','N');
		
		$hoje 									= Carbon::now()->format('Y-m-d');
		
		if ($pulou !="S")
		{
			$query									= DB::connection('mysql')
															->table('parcelas')
															->select(
																	 'contratos.id',
																	 'contratos.galaxPayId',
																	 'contratos.status',
																	 'contratos.firstPayDayDate',
																	 'contratos.paymentLink',
																	 'clientes.cpfcnpj',
																	 'clientes.nome as cliente',
																	 'clientes.telefone',
																	 'planos.nome as plano',
																	 'planos.formapagamento',
																	 'periodicidades.nome as periodicidade',
																	 'situacoes.nome as situacao',
																	 'parcelas.id as parcela_id',
																	 'parcelas.galaxPayId',
																	 'parcelas.boletobankNumber',
																	 'parcelas.valor',
																	 'parcelas.nparcela',
																	 'parcelas.contrato_id',
																	 'parcelas.data_vencimento',
																	 'parcelas.data_pagamento',
																	 'parcelas.data_baixa',
																	 'parcelas.valor_pago',
																	 'parcelas.negociar',
																	 'parcelas.statusDescription',
																	 DB::raw("DATEDIFF('$hoje', parcelas.data_vencimento) as dias")
																	);
				  
			if (isset($payload->campos))
			{
				$query                              = Cas::montar_filtro($query, $payload);
			}

			$query->leftJoin('contratos',       'parcelas.contrato_id',     '=', 'contratos.id')
				  ->leftJoin('clientes',        'contratos.cliente_id',    '=', 'clientes.id')
				  ->leftJoin('situacoes',	    'contratos.situacao_id',   '=', 'situacoes.id')
				  ->leftJoin('planos',	        'contratos.plano_id',      '=', 'planos.id')
				  ->leftJoin('periodicidades',  'planos.periodicidade_id',  '=', 'periodicidades.id');
		} else {
			$query									= DB::table('parcelas')
														->join('parcelas as p2',    'parcelas.contrato_id', 	'=', 'p2.contrato_id')
														->join('contratos',    		'parcelas.contrato_id', 	'=', 'contratos.id')  
													    ->leftJoin('clientes',      'contratos.cliente_id',    	'=', 'clientes.id')
													    ->leftJoin('situacoes',	    'contratos.situacao_id',   	'=', 'situacoes.id')
													    ->leftJoin('planos',	    'contratos.plano_id',      	'=', 'planos.id')
													    ->leftJoin('periodicidades','planos.periodicidade_id',  '=', 'periodicidades.id')
														->select('contratos.id',
																 'contratos.galaxPayId',
																 'contratos.status',
																 'contratos.firstPayDayDate',
																 'contratos.paymentLink',
																 'clientes.cpfcnpj',
																 'clientes.nome as cliente',
																 'clientes.telefone',
																 'planos.nome as plano',
																 'planos.formapagamento',
																 'periodicidades.nome as periodicidade',
																 'situacoes.nome as situacao',
																 'parcelas.id as parcela_id',
																 'parcelas.contrato_id',
																 'parcelas.galaxPayId',
																 'parcelas.boletobankNumber',
																 'parcelas.valor',
																 'parcelas.nparcela',
																 'parcelas.data_vencimento',
																 'parcelas.data_pagamento',
																 'parcelas.data_baixa',
																 'parcelas.valor_pago',
																 'parcelas.negociar',
																 'parcelas.statusDescription'
														)
														->whereIn('contratos.status', array('active','waitingPayment'))  
														->whereNull('parcelas.data_pagamento')        
														->whereNull('parcelas.data_baixa')  														
													    ->where('parcelas.data_vencimento', '<', DB::raw('CURDATE()'))
														->whereNotNull('p2.data_pagamento')              			 
														->whereColumn('p2.data_vencimento', '>', 'parcelas.data_vencimento')
														->distinct();
		}
		
		$query->orderBy($orderby,$direction);	
		$parcelas								= $query->paginate($limite);

        $parcelas->getCollection()->transform(function ($parcela) 
        {
			$parcela->psituacao 			= Cas::obterSituacaoParcela($parcela->data_vencimento,$parcela->data_pagamento,$parcela->data_baixa);
            $parcela->csituacao             = Cas::obterSituacaoContrato($parcela->status);
            $parcela->cpfcnpj				= Cas::formatCnpjCpf($parcela->cpfcnpj);
			$parcela->telefone 				= Cas::formatarTelefone($parcela->telefone);

            $parcela->podepagar   		    = Cas::podepagarParcela($parcela->psituacao);
			$parcela->podebaixar		    = Cas::podebaixarParcela($parcela->psituacao,$parcela->galaxPayId);
			$parcela->podeebaixa		    = Cas::podeebaixaParcela($parcela->psituacao,$parcela->parcela_id,$parcela->id);
			$parcela->podeepagar		    = Cas::podeepagarParcela($parcela->psituacao,$parcela->parcela_id,$parcela->id);
            $parcela->podeexcluir           = Cas::podeexcluirParcela($parcela->id,$parcela->parcela_id,$parcela->data_pagamento,$parcela->data_baixa,$parcela->galaxPayId,$parcela->nparcela);
            $parcela->podeeditar            = Cas::podeeditarParcela($parcela->psituacao,$parcela->galaxPayId);
            $parcela->podeboleto            = Cas::podeboletoParcela($parcela->psituacao,$parcela->galaxPayId);
            $parcela->podeeboleto           = Cas::podeeboletoParcela($parcela->psituacao,$parcela->galaxPayId,$parcela->contrato_id);
            $parcela->podenegociar          = 'N';
            $parcela->podefatura            = Cas::podefaturaContrato($parcela->status);
            $parcela->podecarne             = Cas::podecarneContrato($parcela->status,$parcela->id,$parcela->parcela_id);
            $parcela->podecancel            = Cas::podecancelCobranca($parcela->psituacao,$parcela->galaxPayId);
			$parcela->juros					= 0;
			
            if ((!is_null($parcela->data_pagamento)) and ($parcela->valor_pago > 0))
			{
				$parcela->valor 			= $parcela->valor_pago;
				$parcela->juros				= $parcela->valor_pago - $parcela->valor;
			} 
            if  ($parcela->psituacao == 'Paga')
            {
                $parcela->formapagamento    = $parcela->statusDescription;
            } 
			if  ($parcela->psituacao != 'Vencida')
            {
				$parcela->dias 					= "";
			} else {
				if ($parcela->dias > 0)
				{
					$parcela->podenegociar      = 'S';
					$calculo 					= cas::calcularJurosBoleto($parcela->valor, $parcela->dias, null, 5.0, 2.0);
					if ($calculo->erro =="")
					{
						$parcela->juros 		= number_format($calculo->multaJuros,2);
					}
				}
			}				
           
		    if (($parcela->psituacao == 'Baixada') and ($parcela->negociar =='S'))
            {
                $parcela->psituacao    			= 'Negociada';
            } 	
			
            return $parcela;
         });
                    
         return response()->json($parcelas, 200);

    }    
	
	public function excel(Request $request)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar parcelas.'], 403);
        }

		$pulou                                  	= $request->input('pulou','N');
		$hoje 										= Carbon::now()->format('Y-m-d');
		
        $payload									= (object) $request->all();
		
		if ($pulou !="S")
		{
			$query									= DB::connection('mysql')
															->table('parcelas')
															->select(
																	 'contratos.id',
																	 'contratos.galaxPayId',
																	 'contratos.status',
																	 'contratos.firstPayDayDate',
																	 'contratos.paymentLink',
																	 'contratos.observacao',
																	 'clientes.cpfcnpj',
																	 'clientes.nome as cliente',
																	 'clientes.telefone',
																	 'planos.nome as plano',
																	 'planos.formapagamento',
																	 'periodicidades.nome as periodicidade',
																	 'situacoes.nome as situacao',
																	 'parcelas.id as parcela_id',
																	 'parcelas.galaxPayId',
																	 'parcelas.boletobankNumber',
																	 'parcelas.valor',
																	 'parcelas.nparcela',
																	 'parcelas.data_vencimento',
																	 'parcelas.data_pagamento',
																	 'parcelas.data_baixa',
																	 'parcelas.valor_pago',
																	 'parcelas.negociar',
																	 'parcelas.statusDescription',
																	 DB::raw("DATEDIFF('$hoje', parcelas.data_vencimento) as dias")
																	);
				  
			if (isset($payload->campos))
			{
				$query                              = Cas::montar_filtro($query, $payload);
			}

			$query->leftJoin('contratos',       'parcelas.contrato_id',     '=', 'contratos.id')
				  ->leftJoin('clientes',        'contratos.cliente_id',    '=', 'clientes.id')
				  ->leftJoin('situacoes',	    'contratos.situacao_id',   '=', 'situacoes.id')
				  ->leftJoin('planos',	        'contratos.plano_id',      '=', 'planos.id')
				  ->leftJoin('periodicidades',  'planos.periodicidade_id',  '=', 'periodicidades.id');
		} else {
			$query									= DB::table('parcelas')
														->join('parcelas as p2',    'parcelas.contrato_id', 	'=', 'p2.contrato_id')
														->join('contratos',    		'parcelas.contrato_id', 	'=', 'contratos.id')  
													    ->leftJoin('clientes',      'contratos.cliente_id',    	'=', 'clientes.id')
													    ->leftJoin('situacoes',	    'contratos.situacao_id',   	'=', 'situacoes.id')
													    ->leftJoin('planos',	    'contratos.plano_id',      	'=', 'planos.id')
													    ->leftJoin('periodicidades','planos.periodicidade_id',  '=', 'periodicidades.id')
														->select('contratos.id',
																 'contratos.galaxPayId',
																 'contratos.status',
																 'contratos.firstPayDayDate',
																 'contratos.paymentLink',
																 'contratos.observacao',
																 'clientes.cpfcnpj',
																 'clientes.nome as cliente',
																 'clientes.telefone',
																 'planos.nome as plano',
																 'planos.formapagamento',
																 'periodicidades.nome as periodicidade',
																 'situacoes.nome as situacao',
																 'parcelas.id as parcela_id',
																 'parcelas.galaxPayId',
																 'parcelas.boletobankNumber',
																 'parcelas.valor',
																 'parcelas.nparcela',
																 'parcelas.data_vencimento',
																 'parcelas.data_pagamento',
																 'parcelas.data_baixa',
																 'parcelas.valor_pago',
																 'parcelas.negociar',
																 'parcelas.statusDescription'

														)
														->whereIn('contratos.status', array('active','waitingPayment'))  
														->whereNull('parcelas.data_pagamento')        
														->whereNull('parcelas.data_baixa')  														
													    ->where('parcelas.data_vencimento', '<', DB::raw('CURDATE()'))
														->whereNotNull('p2.data_pagamento')              			 
														->whereColumn('p2.data_vencimento', '>', 'parcelas.data_vencimento')
														->distinct();
		}
		
		$query->orderBy('contratos.id','asc');	
		$query->orderBy('parcelas.nparcela','asc');	
		$parcelas								= $query->get();
		
		return Excel::download(new \App\Exports\ParcelasExport($parcelas), 'parcelas.xlsx');
	}

    public function boleto_view(Request $request, $id)
    {
        $parcela                                = \App\Models\Parcela::find($id);

        if (!isset($parcela->id))
        {
            return response()->json(['error' => 'A parcela não foi encontrada', 'mensagem' => 'A parcela não foi encontrada'], 404);
        }

        if (empty($parcela->boletopdf))
        {
            return response()->json(['error' => 'O boleto desta parcela ainda não foi gerado', 'mensagem' => 'O boleto desta parcela ainda não foi gerado'], 422);
        }

        $response                               = Http::get($parcela->boletopdf);
        return  response($response->body(), 200)->header('Content-Type', 'application/pdf');
    }

    public function boletos_view(Request $request, $id)
    {
        $parcelas                               = \App\Models\Parcela::select('galaxPayId')
                                                                  ->where('contrato_id','=',$id)
                                                                  ->where('data_pagamento','=',null)
                                                                  ->where('data_baixa','=',null)
                                                                  ->where('galaxPayId','>',0)
                                                                  ->where('boletobankNumber','>',0)
                                                                  ->get();

       $galaxPayId                              = array();

       foreach ($parcelas as $parcela)
       {
            $galaxPayId[]                       = $parcela->galaxPayId;
       }

       $response                                = CelCash::obterBoletos($galaxPayId);
       if (isset($response->statcode))
       {
            if (($response->statcode == 200) and (isset($response->Boleto->pdf)))
            {
                //return response()->json($response, 200);
                $response                       = Http::get($response->Boleto->pdf);
                return  response($response->body(), 200)->header('Content-Type', 'application/pdf');
            }
        }

         //return response()->json($response, 200);

        if (isset($response->error))
        {
            if (isset($response->error->message))
            {
                return response()->json(['error' => $response->error->message], $response->statcode);
            }
        }

        return response()->json(['error' => 'ocorreu erro nao identificado'], 404);
    }
	
	public function enviar_parcelaWhatsapp(Request $request, $id)
    {
		
		$forma              						= $request->input('forma', 'B');
		$parcela                                	= \App\Models\Parcela::find($id);
		
		if (!isset($parcela->id))
        {
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		$contrato                                	= \App\Models\Contrato::with('cliente')->find($parcela->contrato_id);
		
		if (!isset($contrato->id))
        {
			return response()->json(['mensagem' => 'Contrato não encontrado.'], 404);
		}
		
		if (is_null($contrato->contractacceptedAt))
		{
			return response()->json(['mensagem' => 'O Contrato ainda não foi assinado.'], 404);
		}
		
		$token										= Cas::obterTokenVendedor($request->user()->id);	
		
		if ($token =="")
		{
		  return response()->json(['mensagem' => 'Token do Chat Hot não cadastrado. Favor solicitar o cadastro.'], 404);
		}
		
		if ($forma == 'B')
		{
			$mensagem 								= 'Olá, segue o link do boleto da parcela. ';
			$mensagem 								.= "\n" . $parcela->boletopdf;
		} else {
			$mensagem 								= 'Olá, segue o link do PIX para pagamento da parcela. ';
			$mensagem 								.= "\n" . $parcela->pixpage;
		}
		
		$envio 										= ChatHot::enviarMensagemChatHot($contrato->cliente->telefone, $mensagem, $token);
		
		if ($envio->ok =='S') 
		{
			 return response()->json(['mensagem' => 'Parcela enviada pelo whatsapp com sucesso!'], 200);
		}
		
		return response()->json(['mensagem' => 'Ocorreu erro no envio da mensagem no whatsapp. Entre em contato com o administrador do Chat Hot'], 404);
	}
	
	public function gerar_boleto(Request $request, $id)
    {
        $parcela                                	= \App\Models\Parcela::find($id);
        $responderErro                           = function ($mensagem, $status = 422) {
            return response()->json(['error' => $mensagem, 'mensagem' => $mensagem], $status);
        };

		if (!isset($parcela->id))
        {
			return response()->json(['error' => 'A parccela não foi encontrada'], 404);
		}
		
        $contrato 				                	= \App\Models\Contrato::find($parcela->contrato_id);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => 'O contrato não foi encontrado'], 404);
		}
		
		if (($contrato->tipo == 'J') or ($contrato->avulso == 'S'))
		{
			if ($parcela->galaxPayId ==0)
			{
				$charges								= CelCash::storeContratoCharges($parcela->id);	
				
				Log::info("Charge", ['Charge' => $charges ]);
				
				if (($charges->ok == 'S') and (isset($charges->Charge)))
				{
					$scharge 							= CelCash::updateContratoWithCharge($charges->Charge);
				} else {
                    $mensagem                          = $charges->mensagem ?? $charges->message ?? $charges->error ?? 'Ocorreu um erro não identificado na criação do boleto';
					return $responderErro($mensagem, 404);
				}
			}
		} else {
            if (empty($parcela->data_vencimento))
            {
                return $responderErro('A parcela está sem data de vencimento para gerar o boleto');
            }

			$body									= new stdClass();
			$body->myId								= $parcela->contrato_id . "#" . $parcela->id . "#" .  bin2hex(random_bytes(5));
			$body->value							= intval($parcela->valor * 100);
			$body->payday							= $parcela->data_vencimento;
			$body->payedOutsideGalaxPay				= false;
			$body->additionalInfo					= $parcela->additionalInfo;
			$adicionar 								= CelCash::adicionarTransaction($contrato->galaxPayId,$body,'galaxPayId');
			if (($adicionar->statcode != 200) or (!isset($adicionar->type)) or (!$adicionar->type))
			{
				return response()->json(['error' => "Ocorreu um erro não identificado na criação da parcela no CelCash"], 422);	
			}
            $parcela->chargeMyId                   = $body->myId;
			$parcela->galaxPayId					= $adicionar->Transaction->galaxPayId;
			$parcela->save();
			$transaction   							= CelCash::CelCashMigrarTransaction($adicionar->Transaction,1,'C');
            if ((!isset($transaction->ok)) or (!$transaction->ok))
            {
                return $responderErro('O boleto foi criado no CelCash, mas não foi possível sincronizar a parcela local');
            }
		}
			
        return response()->json($id, 200);
    }
	
	public function conciliar_parcela(Request $request, $id)
    {
		
        $parcela                                	= \App\Models\Parcela::with('contrato')->find($id);

		if (!isset($parcela->id))
        {
			$retorno								= new stdClass();
			$retorno->status 						= 404;
			$retorno->erro 							= true;
			$retorno->mudanca						= false;
			$retorno->dataPagamento					= "";
			$retorno->dataBaixa						= "";
			$retorno->situacao						= "";
			$retorno->mensagem						= 'A parcela não foi encontrada';
		} else {
			$retorno								= CelCash::CelCashParcelaConciliar($parcela->id);	
		}
        return response()->json($retorno, $retorno->status);
    }
	
    public function parcelas_excluir_duplicados(Request $request)
    {
		
		$idsParaExcluir = DB::table('parcelas as p')
			->join(DB::raw('(
				SELECT contrato_id, nparcela
				FROM parcelas
				GROUP BY contrato_id, nparcela
				HAVING COUNT(*) > 1
			) as dup'), function($join) {
				$join->on('p.contrato_id', '=', 'dup.contrato_id')
					 ->on('p.nparcela', '=', 'dup.nparcela');
			})
			->whereRaw('p.statusDate = (
				SELECT MIN(p2.statusDate)
				FROM parcelas p2
				WHERE p2.contrato_id = p.contrato_id AND p2.nparcela = p.nparcela
			)')
			->pluck('p.id');

		DB::table('parcelas')->whereIn('id', $idsParaExcluir)->delete();
		
		return response()->json($idsParaExcluir, 200);

	}
}
