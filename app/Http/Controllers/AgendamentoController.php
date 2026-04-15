<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use App\Helpers\Cas;
use App\Helpers\CelCash;
use Carbon\Carbon;
use stdClass;
use DB;

class AgendamentoController extends Controller
{
    public function index(Request $request)
    {
		if ((!$request->user()->tokenCan('view.agendamentos')) and (!$request->user()->tokenCan('view.contratos')))
        {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }
 
		$payload								= (object) $request->all();
  
		$limite              					= $request->input('limite', 12);
		$orderby             					= $request->input('orderby', 'clinica_beneficiario.id');
		$direction          					= $request->input('direction', 'desc');
	
        $pesquisa            					= $request->input('pesquisa', '');
        $contrato_id                            = $request->input('contrato_id', 0);
		
		$query									= DB::connection('mysql')
														->table('clinica_beneficiario')
														->select('clinica_beneficiario.id',
                                                                 'clinicas.cnpj',
																 'clinicas.tipo',
																 'clinicas.nome as clinica',
																 'especialidades.nome as especialidade',
																 'clientes.cpfcnpj as cpf',
                                                                 'clientes.nome as beneficiario',
                                                                 'clientes.data_nascimento',
																 \DB::raw("TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade"),
                                                                 'beneficiarios.tipo',
                                                                 'beneficiarios.desc_status',
																 'clinica_beneficiario.solicitado_data_hora',
																 'clinica_beneficiario.agendamento_data_hora',
																 'clinica_beneficiario.preagendamento_data_hora',
																 'clinica_beneficiario.cancelado_data_hora',
																 'clinica_beneficiario.confirmado_data_hora',
																 'clinica_beneficiario.pagamento_data_hora',
																 'clinica_beneficiario.tipo as atipo',
																 'clinica_beneficiario.galaxPayId',
																 'clinica_beneficiario.status',
																 'clinica_beneficiario.forma',
																 'clinica_beneficiario.paymentLink',
																 'clinica_beneficiario.boletopdf',
																 'clinica_beneficiario.pixpage',
																 'clinica_beneficiario.piximage',
																 'clinica_beneficiario.pixqrCode',
																 'clinica_beneficiario.boletobankNumber',
																 'clinica_beneficiario.vencimento',
																 'clinica_beneficiario.pagamento',
																 'clinica_beneficiario.estado',
																 'clinica_beneficiario.cidade',
																 'clinica_beneficiario.asituacao_id',
																 'clinica_beneficiario.url_voucher',
																 'clinica_beneficiario.observacao',
																 'clinica_beneficiario.valor',
																 'asituacoes.nome as dsituacao',
																 'agendamento_cmotivos.nome as motivo');
		
		if (!isset($payload->campos))
		{
			$query->where('beneficiarios.contrato_id','=',$contrato_id);
			
			if ($pesquisa !="")
			{
				$query->where('clientes.nome','like',"$pesquisa%");
			}
		} else {
			$query                              = Cas::montar_filtro($query, $payload);
		}
		
        $query->leftJoin('clinicas','clinica_beneficiario.clinica_id','=','clinicas.id')
			  ->leftJoin('beneficiarios','clinica_beneficiario.beneficiario_id','=','beneficiarios.id')
			  ->leftJoin('especialidades','clinica_beneficiario.especialidade_id','=','especialidades.id')
			  ->leftJoin('asituacoes','clinica_beneficiario.asituacao_id','=','asituacoes.id')
			  ->leftJoin('agendamento_cmotivos','clinica_beneficiario.cmotivo_id','=','agendamento_cmotivos.id')
			  ->leftJoin('clientes','beneficiarios.cliente_id','=','clientes.id');
        $query->orderBy($orderby,$direction);
		
		$agendamentos								= $query->paginate($limite);

        $agendamentos->getCollection()->transform(function ($agendamento) 
        {   
			$agendamento->exames					= \App\Models\ExamePedido::select('id','nome','caminho')->where('clinica_beneficiario_id','=',$agendamento->id)->get();
			$agendamento->podecancel				= 'N';
			$agendamento->podeeboleto				= 'N';
			
			if (!is_null($agendamento->vencimento))
			{
				list($ano,$mes,$dia) 				= explode("-",$agendamento->vencimento);
				$agendamento->vencimento			= "$dia/$mes/$ano";
			}
			if (!is_null($agendamento->pagamento_data_hora))
			{
				list($ano,$mes,$dia) 				= explode("-",substr($agendamento->pagamento_data_hora,0,10));
				$hora 								= substr($agendamento->pagamento_data_hora,11,5);
				$agendamento->pagamento				= $dia . "/" . $mes ."/" .$ano . " " .$hora;
			}
			
			if ((is_null($agendamento->pagamento)) and ($agendamento->boletobankNumber >0) and (Cas::nulltoSpace($agendamento->galaxPayId) !="") and (Cas::nulltoSpace($agendamento->galaxPayId) !=0))
			{
				$agendamento->podeeboleto			= 'S';
			}
			
			if (($agendamento->valor > 0) and (is_numeric($agendamento->valor)))			
			{
				$agendamento->valor					= "R$ ". str_replace(".",",",$agendamento->valor);
			} else {
				$agendamento->valor					= "";
			}
			
			if (!is_null($agendamento->cancelado_data_hora))
			{
				list($ano,$mes,$dia) 				= explode("-",substr($agendamento->cancelado_data_hora,0,10));
				$hora 								= substr($agendamento->cancelado_data_hora,11,5);
				$agendamento->cancelado				= $dia . "/" . $mes ."/" .$ano . " " .$hora;
			}
			
			if (!is_null($agendamento->agendamento_data_hora))
			{
				list($ano,$mes,$dia) 				= explode("-",substr($agendamento->agendamento_data_hora,0,10));
				$hora 								= substr($agendamento->agendamento_data_hora,11,5);
				$agendamento->agendamento_data_hora		= $dia . "/" . $mes ."/" .$ano . " " .$hora;
			}
			if (!is_null($agendamento->solicitado_data_hora))
			{
				list($ano,$mes,$dia) 				= explode("-",substr($agendamento->solicitado_data_hora,0,10));
				$hora 								= substr($agendamento->solicitado_data_hora,11,5);
				$agendamento->solicitado_data_hora	= $dia . "/" . $mes ."/" .$ano . " " .$hora;
			}
			if (!is_null($agendamento->preagendamento_data_hora))
			{
				list($ano,$mes,$dia) 				= explode("-",substr($agendamento->preagendamento_data_hora,0,10));
				$hora 								= substr($agendamento->preagendamento_data_hora,11,5);
				$agendamento->preagendamento_data_hora	= $dia . "/" . $mes ."/" .$ano . " " .$hora;
			}
			if (($agendamento->galaxPayId ==0) and (($agendamento->asituacao_id !=6) and ($agendamento->asituacao_id !=7)))
			{
				$agendamento->podeexcluir			= 'S';
			} else {
				$agendamento->podeexcluir			= 'N';
			}
			if (Cas::nulltoSpace($agendamento->url_voucher) !="")
			{
				$agendamento->tvoucher				= 'S';
			} else {
				if (!is_null($agendamento->pagamento_data_hora))
				{
					$agendamento->tvoucher			= 'T';
				} else {
					$agendamento->tvoucher			= 'N';
				}
			}
			
			$agendamento->cobranca_url				= "";
			
			
			if ($agendamento->forma == 'B')
			{
				$agendamento->cobranca_url			= $agendamento->boletopdf;
			} else {
				if ($agendamento->forma == 'C')
				{
					$agendamento->cobranca_url		= $agendamento->paymentLink;
				}
			}
			
			$agendamento->url_voucher 				= Cas::nulltoSpace($agendamento->url_voucher);
			return $agendamento;
        });
		
        return response()->json($agendamentos, 200);
    }
	
	public function upload_imagem(Request $request)
	{
		$payload									= (object) $request->all();
		
		if ($request->hasFile('imagem') && $request->file('imagem')->isValid())
		{
			$file                       			= $request->imagem;
			$codigo 								= bin2hex(random_bytes(6));
			$folderName								= 'exame' . '/' . $payload->agendamento_id;
			$originalName 							= $file->getClientOriginalName();
			$extension 			        			= $file->getClientOriginalExtension();
			$fileName 								= $codigo . '-' . $originalName;
			$destinationPath 						= public_path() . '/' . $folderName;
			$file->move($destinationPath, $fileName);
			$caminho                    			= url("/") . '/' . $folderName . '/' . $fileName;
		
			$exame 									= \App\Models\ExamePedido::where('clinica_beneficiario_id','=',$payload->agendamento_id)
																				 ->where('nome','=',$originalName)
																				 ->first();
																			 
			if (!isset($exame->id))
			{
				$exame            					= new \App\Models\ExamePedido();
				$exame->clinica_beneficiario_id		= $payload->agendamento_id;	
			} 
			
			$exame->nome							= $originalName;
			$exame->caminho 						= $caminho;
			if ($exame->save())
			{
				$historico            			    = new \App\Models\AgendamentoHistorico();
				$historico->clinica_beneficiario_id	= $payload->agendamento_id;
				$historico->user_id 				= $request->user()->id;
				$historico->historico				= "Inserido a imagem: " . $exame->nome ?? "Sem nome";			
				$historico->save();
			}
		}
		
		return response()->json($payload, 200);
	}
	
	public function excluir_imagem(Request $request, $id)
	{
		$exame 										= \App\Models\ExamePedido::find($id);
																			 		
		if (isset($exame->id))
		{
			if ($exame->delete())
			{
				$historico            			    = new \App\Models\AgendamentoHistorico();
				$historico->clinica_beneficiario_id	= $exame->clinica_beneficiario_id;
				$historico->user_id 				= $request->user()->id;
				$historico->historico				= "Imagem excluida: " . $exame->nome ?? 'Sem nome';			
				$historico->save();
			}
		} 
		
		return response()->json(true, 200);
	}
	
	public function excel(Request $request)
    {
		if ((!$request->user()->tokenCan('view.agendamentos')) and (!$request->user()->tokenCan('view.contratos')))
        {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }
 
		$payload								= (object) $request->all();
  
		$orderby             					= $request->input('orderby', 'clinica_beneficiario.id');
		$direction          					= $request->input('direction', 'desc');
	
        $pesquisa            					= $request->input('pesquisa', '');
        $contrato_id                            = $request->input('contrato_id', 0);
		
		$query									= DB::connection('mysql')
														->table('clinica_beneficiario')
														->select('clinica_beneficiario.id',
                                                                 'clinicas.cnpj',
																 'clinicas.tipo',
																 'clinicas.nome as clinica',
																 'especialidades.nome as especialidade',
																 'clientes.cpfcnpj as cpf',
                                                                 'clientes.nome as beneficiario',
                                                                 'clientes.data_nascimento',
																 \DB::raw("TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade"),
                                                                 'beneficiarios.tipo',
                                                                 'beneficiarios.desc_status',
																 'clinica_beneficiario.created_at',
																 'clinica_beneficiario.solicitado_data_hora',
																 'clinica_beneficiario.agendamento_data_hora',
																 'clinica_beneficiario.preagendamento_data_hora',
																 'clinica_beneficiario.cancelado_data_hora',
																 'clinica_beneficiario.confirmado_data_hora',
																 'clinica_beneficiario.pagamento_data_hora',
																 'clinica_beneficiario.tipo as atipo',
																 'clinica_beneficiario.galaxPayId',
																 'clinica_beneficiario.status',
																 'clinica_beneficiario.paymentLink',
																 'clinica_beneficiario.boletopdf',
																 'clinica_beneficiario.boletobankNumber',
																 'clinica_beneficiario.vencimento',
																 'clinica_beneficiario.pagamento',
																 'clinica_beneficiario.estado',
																 'clinica_beneficiario.cidade',
																 'clinica_beneficiario.asituacao_id',
																 'clinica_beneficiario.url_voucher',
																 'clinica_beneficiario.observacao',
																 'clinica_beneficiario.valor',
																 'asituacoes.nome as dsituacao',
																 'agendamento_cmotivos.nome as motivo');
		
		if (!isset($payload->campos))
		{
			$query->where('beneficiarios.contrato_id','=',$contrato_id);
			
			if ($pesquisa !="")
			{
				$query->where('clientes.nome','like',"$pesquisa%");
			}
		} else {
			$query                              = Cas::montar_filtro($query, $payload);
		}
		
        $query->leftJoin('clinicas','clinica_beneficiario.clinica_id','=','clinicas.id')
			  ->leftJoin('beneficiarios','clinica_beneficiario.beneficiario_id','=','beneficiarios.id')
			  ->leftJoin('especialidades','clinica_beneficiario.especialidade_id','=','especialidades.id')
			  ->leftJoin('asituacoes','clinica_beneficiario.asituacao_id','=','asituacoes.id')
			  ->leftJoin('agendamento_cmotivos','clinica_beneficiario.cmotivo_id','=','agendamento_cmotivos.id')
			  ->leftJoin('clientes','beneficiarios.cliente_id','=','clientes.id');
        $query->orderBy($orderby,$direction);
		
		$agendamentos								= $query->get();

       return Excel::download(new \App\Exports\AgendamentosExport($agendamentos), 'agendamentos.xlsx');
    }
	
	public function conciliar_consulta(Request $request, $id)
    {
		if ((!$request->user()->tokenCan('view.agendamentos')) and (!$request->user()->tokenCan('view.contratos')))
		{
            return response()->json(['mensagem' => 'Não autorizado para visualizar situações.'], 403);
        }

		$agendamento                              	= \App\Models\ClinicaBeneficiario::with('clinica')->find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		
		$response 													= json_decode($agendamento->response);
		$galaxPayId 												= $response->galaxPayId;
			
		if ($galaxPayId ==0)
		{
			 return response()->json(['mensagem' => 'Agendamento não tem cobrança.'], 404);
		}
		
		$conciliar 													= CelCash::CelCashConsultaConciliar($galaxPayId);
		
		if ((isset($conciliar->transaction->chargeMyId)) and ($conciliar->transaction->chargeMyId == $agendamento->myId))
		{
			if (Cas::nulltoSpace($conciliar->dataPagamento) != "")
			{
				if (($agendamento->asituacao_id == 3) or ($agendamento->asituacao_id == 4) or ($agendamento->asituacao_id == 5) or ($agendamento->asituacao_id == 6))
				{
					$asituacao 		   								= \App\Models\Asituacao::find(6);

					if (isset($asituacao->id))
					{
						$observacao6								= str_replace(array("<p>","</p>"),"",$asituacao->orientacao);
						$whatsapp6									= $asituacao->whatsapp;
						$whatsappc6									= $asituacao->whatsappc;
					} else {
						$observacao6								= "";
						$whatsapp6									= "";
						$whatsappc6									= "";
					}
		
					$asituacao_id     								= $agendamento->asituacao_id;
					$agendamento->asituacao_id     					= 6;
					$agendamento->pagamento_data_hora				= $conciliar->dataPagamento;
					$agendamento->pagamento_por 					= 1;
					$agendamento->cmotivo_id 						= 0;
					$agendamento->pagamento							= substr($conciliar->dataPagamento,0,10);
					if ($observacao6 !="")
					{
						 $agendamento->observacao					= $observacao6;
					}
					if ($agendamento->save())
					{
						if ($asituacao_id != 6)
						{
							if (($whatsapp6 !="") or ($whatsappc6 !=""))
							{
								 $beneficiario                      = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
								 if (isset($beneficiario->id))
								 {
									Cas::gerarVoucherPDF($agendamento->id);
									Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$beneficiario->cliente->telefone,$whatsapp6,1);
									if ((Cas::nulltoSpace($whatsappc6) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
									{
										Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$whatsappc6,1);
									}
								}
							 }
						}
					}	
				}
				$historico            			    				= new \App\Models\AgendamentoHistorico();
				$historico->clinica_beneficiario_id					= $agendamento->id;
				$historico->user_id 								= $request->user()->id;
				$historico->historico								= "Resultado da conciliação: Pagamento realizado";			
				$historico->save();
				return response()->json(['mensagem' => 'Pagamento realizado'], 200);
			} else {
				if (Cas::nulltoSpace($conciliar->dataBaixa) != "")
				{
					$agendamento->baixa								= substr($conciliar->dataBaixa,0,10);
					if ($agendamento->save())
					{
						$historico            			    = new \App\Models\AgendamentoHistorico();
						$historico->clinica_beneficiario_id	= $agendamento->id;
						$historico->user_id 				= $request->user()->id;
						$historico->historico				= "Resultado da conciliação: Cancelamento realizado";			
						$historico->save();
					}
					return response()->json(['mensagem' => 'Cancelamento realizado'], 200);
				}
			}
			return response()->json(['mensagem' => 'Não houve mudança'], 200);
		} else {
			return response()->json(['mensagem' => 'Instabilidade no CelCash. Concilie novamente mais tarde!', 'conciliar' => $conciliar , 'agendamento' => $agendamento ], 404);
		}
		
	}

    public function show(Request $request, $id)
    {
		
		if ((!$request->user()->tokenCan('view.agendamentos')) and (!$request->user()->tokenCan('view.contratos')))
		{
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

		$agendamento                              = \App\Models\ClinicaBeneficiario::with('clinica','beneficiario','especialidade')->find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['error' => 'Agendamento não encontrado.'], 404);
        }
		
		$cliente 								= \App\Models\Cliente::find($agendamento->beneficiario->cliente_id);
		
		if (isset($cliente->id))
		{
			$beneficiario						= new stdClass();
			$beneficiario->id 					= $agendamento->beneficiario->id;
			$beneficiario->nome					= $cliente->nome;
			$beneficiario->celular				= $cliente->telefone;
			$beneficiario->cpf					= Cas::formatCnpjCpf($cliente->cpfcnpj);
			$beneficiario->idade 				= Carbon::createFromDate($cliente->data_nascimento)->age; 
			list($ano,$mes,$dia)                = explode("-",$cliente->data_nascimento);
			$beneficiario->nascimento 			= "$dia/$mes/$ano"; 			
			$beneficiario->tipo					= ucfirst(strtolower($agendamento->beneficiario->tipo_usuario));
			$beneficiario->cpf_titular 			= "";
			$beneficiario->mensagem 			= "";
			$beneficiario->saldo 				= $agendamento->saldo;
			
			if ($agendamento->beneficiario->tipo == 'D')
			{
				$cbeneficiario 								= \App\Models\Beneficiario::with('contrato')->find($agendamento->beneficiario_id);
				if (isset($cbeneficiario->id))
				{
					if ($cbeneficiario->contrato->tipo == 'J')
					{
						$tbeneficiario 						= \App\Models\Beneficiario::with('cliente')->find($cbeneficiario->parent_id);
						if (isset($tbeneficiario->id))
						{
							$beneficiario->cpf_titular		= $tbeneficiario->cliente->cpfcnpj;
							$contrato_id 					= $tbeneficiario->contrato_id;
						}
					} else {
						$tbeneficiario 						= \App\Models\Beneficiario::with('cliente')
																				 ->where('contrato_id','=',$agendamento->beneficiario->contrato_id)
																				->where('tipo','=','T')
																				->first();
						if (isset($tbeneficiario->id))
						{
							$beneficiario->cpf_titular		= $tbeneficiario->cliente->cpfcnpj;
							$contrato_id 					= $tbeneficiario->contrato_id;
						}
					}
				}
			} else {
				$contrato_id 							= $agendamento->beneficiario->contrato_id;
			}
			
			$pstatus									= 'A';
			$mensagem 									= "";							
				
			if ($contrato_id > 0)
			{
				
				$parcela 								= \App\Models\Parcela::where('contrato_id','=',$contrato_id)
																			 ->where('data_pagamento','=',null)
																			 ->where('data_baixa','=',null)
																			 ->where('data_vencimento','<',date('Y-m-d'))
																			 ->orderBy('data_vencimento','asc')
																			 ->first();
				if (isset($parcela->id))
				{
					$date 								= $parcela->data_vencimento. " 23:59:59";
					$vencimento 						= Carbon::createFromDate($date);
					$now 								= Carbon::now();
					$diferenca 							= $vencimento->diffInDays($now);
						
					if ($diferenca >= 2)
					{
						$pstatus						= 'I';
						list($ano,$mes,$dia) 			= explode("-",$parcela->data_vencimento);
						$mensagem 						= "Pendência de pagamento | vencido em: $dia/$mes/$ano";
					}
				}
			}
			$beneficiario->contrato_id 					= $contrato_id;
			$beneficiario->pstatus 						= $pstatus;
			$beneficiario->mensagem 					= $mensagem;
			$agendamento->cliente						= $beneficiario;
		}
		
		if (Cas::nulltoSpace($agendamento->cidade) == "")
		{
			$agendamento->cidade				= $cliente->cidade;
		}
		
		if (Cas::nulltoSpace($agendamento->estado) == "")
		{
			$agendamento->estado				= $cliente->estado;
		}
		
		if (!is_null($agendamento->pagamento))
		{
			list($ano,$mes,$dia) 				= explode("-",$agendamento->pagamento);
			$agendamento->pagamento				= "$dia/$mes/$ano";
		} else {
			$agendamento->pagamento				= "";
		}
		
		if (is_null($agendamento->vencimento))
		{
			$agendamento->vencimento			= "";
		} 
			
		if ($agendamento->clinica_id ==0)
		{
			$clinica							= new stdClass();
			$clinica->id						= 0;
			$clinica->nome						= "";
			$clinica->endereco        			= "";
			$clinica->telefone 					= "";
		} else {
			$clinica							= new stdClass();
			$clinica->id						= $agendamento->clinica->id;
			$clinica->nome						= $agendamento->clinica->nome;
			$clinica->endereco	    			= $agendamento->clinica->logradouro . ', '  . $agendamento->clinica->numero ." | cep " . $agendamento->clinica->cep . ' - ' . $agendamento->clinica->bairro . ' - ' . $agendamento->clinica->cidade . ' - ' . $agendamento->clinica->estado;
			$clinica->telefone 					= $agendamento->clinica->telefone;	
		}
		
		$agendamento->nclinica 					= $clinica;
		
		if (is_null($agendamento->agendamento_data_hora))
		{
			$agendamento->agendar_data			= "";
			$agendamento->agendar_hora			= "";
		} else {
			$agendamento->agendar_data			= substr($agendamento->agendamento_data_hora,0,10);
			$agendamento->agendar_hora			= substr($agendamento->agendamento_data_hora,11,8);
		}
		
		$predatas 								= array();
		
		$pdatas 								= \App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$id)->get();
		
		foreach ($pdatas as $pdata)
		{
			$reg								= new stdClass();
			$data 								= substr($pdata->data_hora,0,10);
			list($ano,$mes,$dia) 				= explode("-",$data);
			$reg->data							= "$dia/$mes/$ano";
			$reg->hora							= substr($pdata->data_hora,11,5);
			if (($pdata->escolhido) or ($pdata->escolhido==1))
			{
				$reg->selec 					= true;
			} else {
				$reg->selec 					= false;
			}
			$predatas[]							= $reg;
		}
		
		$agendamento->motivo 					= "";
		
		if ($agendamento->cmotivo_id > 0)
		{
			$motivo 							= \App\Models\AgendamentoCmotivo::find($agendamento->cmotivo_id);
			
			if (isset($motivo->id))
			{
				$agendamento->motivo 			= $motivo->nome;
			}
		}
		$agendamento->cobranca_url				= "";
			
			
		if ($agendamento->forma == 'B')
		{
			$agendamento->cobranca_url			= $agendamento->boletopdf;
		} else {
			if ($agendamento->forma == 'C')
			{
				$agendamento->cobranca_url		= $agendamento->paymentLink;
			}
		}
			
		$agendamento->predatas					= $predatas;
		$agendamento->exames					= \App\Models\ExamePedido::select('id','nome','caminho')->where('clinica_beneficiario_id','=',$id)->get();
		$agendamento->datas						= \App\Models\SugestaoData::select('id','data')->where('clinica_beneficiario_id','=',$id)->get();		
        return response()->json($agendamento,200);
    }
	
	public function historico(Request $request, $id)
    {
		
		if ((!$request->user()->tokenCan('view.agendamentos')) and (!$request->user()->tokenCan('view.contratos')))
		{
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

		$agendamento                              = \App\Models\ClinicaBeneficiario::with('clinica','beneficiario','especialidade')->find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['error' => 'Agendamento não encontrado.'], 404);
        }
		
		$beneficiario							= new stdClass();
		$historicos 							= array();
		
		$cliente 								= \App\Models\Cliente::find($agendamento->beneficiario->cliente_id);
		
		if (isset($cliente->id))
		{
			$beneficiario->id 					= $agendamento->beneficiario->id;
			$beneficiario->nome					= $cliente->nome;
			$beneficiario->celular				= $cliente->telefone;
			$beneficiario->cpf					= Cas::formatCnpjCpf($cliente->cpfcnpj);
		}
		
		if (isset($agendamento->especialidade->nome))
		{
			$beneficiario->especialidade 		= $agendamento->especialidade->nome;
		} else {
			$beneficiario->especialidade 		= "";
		}
		
		$beneficiario->historicos 				= array();
		
		if (!is_null($agendamento->solicitado_data_hora))
		{
			$historico											= new stdClass();
			$historico->status									= 'Solicitado';
			$historico->data									= substr($agendamento->solicitado_data_hora,0,10);
			$historico->hora									= substr($agendamento->solicitado_data_hora,11,5);
			$historico->quem									= Cas::obterQuemFez($agendamento->solicitado_por);
			$historicos[$agendamento->solicitado_data_hora]		= $historico;
		}
		
		if (!is_null($agendamento->preagendamento_data_hora))
		{
			$historico											= new stdClass();
			$historico->status									= 'Pré-agendado';
			$historico->data									= substr($agendamento->preagendamento_data_hora,0,10);
			$historico->hora									= substr($agendamento->preagendamento_data_hora,11,5);
			$historico->quem									= Cas::obterQuemFez($agendamento->preagendamento_por);
			$historicos[$agendamento->preagendamento_data_hora]	= $historico;
		}
		
		if (!is_null($agendamento->agendamento_data_hora))
		{
			$historico											= new stdClass();
			$historico->status									= 'Agendado';
			$historico->data									= substr($agendamento->agendamento_data_hora,0,10);
			$historico->hora									= substr($agendamento->agendamento_data_hora,11,5);
			$historico->quem									= Cas::obterQuemFez($agendamento->agendamento_por);
			$historicos[$agendamento->agendamento_data_hora]	= $historico;
		}
		
		if (!is_null($agendamento->confirmado_data_hora))
		{
			$historico											= new stdClass();
			$historico->status									= 'Confirmado';
			$historico->data									= substr($agendamento->confirmado_data_hora,0,10);
			$historico->hora									= substr($agendamento->confirmado_data_hora,11,5);
			$historico->quem									= Cas::obterQuemFez($agendamento->confirmado_por);
			$historicos[$agendamento->confirmado_data_hora]	= $historico;
		}
		
		if (!is_null($agendamento->cancelado_data_hora))
		{
			$historico											= new stdClass();
			$historico->status									= 'Cancelado';
			$historico->data									= substr($agendamento->cancelado_data_hora,0,10);
			$historico->hora									= substr($agendamento->cancelado_data_hora,11,5);
			$historico->quem									= Cas::obterQuemFez($agendamento->cancelado_por);
			$historicos[$agendamento->cancelado_data_hora]	= $historico;
		}
		
		if (!is_null($agendamento->pagamento_data_hora))
		{
			$historico											= new stdClass();
			$historico->status									= 'Pago';
			$historico->data									= substr($agendamento->pagamento_data_hora,0,10);
			$historico->hora									= substr($agendamento->pagamento_data_hora,11,5);
			$historico->quem									= Cas::obterQuemFez($agendamento->pagamento_por);
			$historicos[$agendamento->pagamento_data_hora]	= $historico;
		}
			
	    if (count($historicos) > 0)
		{
			ksort($historicos);
			
			foreach ($historicos as $historico)
			{
				$beneficiario->historicos[]						= $historico;
			}
		}
		
        return response()->json($beneficiario,200);
    }

    public function store(Request $request)
    {
		if (!$request->user()->tokenCan('edit.agendamentos'))
		{
            return response()->json(['error' => 'Não autorizado para criar situações.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
            'beneficiario_id' 	=> 'required|exists:beneficiarios,id',
            'clinica_id' 		=> 'required|exists:clinicas,id',
            'especialidade_id'	=> 'required|exists:especialidades,id',
			'asituacao_id'		=> 'required|integer|between:1,3|exists:asituacoes,id',
			'valor' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			'dmedico'			=> 'nullable',
			'altura'			=> 'nullable',
			'peso'				=> 'nullable',
			'medicamento'		=> 'nullable',
			'observacao'		=> 'nullable',
        ]);

        if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		$agendamentoAberta = \App\Models\ClinicaBeneficiario::where('beneficiario_id', $request->beneficiario_id)
			->where('especialidade_id', $request->especialidade_id)
			->whereNotIn('asituacao_id', [5, 6, 7, 8, 9, 10, 11, 12])
			->first();

		if (isset($agendamentoAberta->id)) {
			return response()->json(['error' => 'Já existe agendamento em aberto para aquele beneficiario e especialidade'], 422);
		}

		$solicitado_data_hora						= "";
		$preagendamento_data_hora					= "";
		$agendamento_data_hora 						= "";
		
		if ((!isset($request->saldo)) or (!is_numeric($request->saldo)))
		{
			$request->saldo 						= 0;
		}
		
		switch ($request->asituacao_id) 
	    {
			case 1: /* Solicitado */
				$solicitado_data_hora				= date('Y-m-d H:i:s');
				$solicitado_por						= $request->user()->id;
				break;
			case 2: /* Pré-agendamento */
				
				$validator = Validator::make($request->all(), [
					'predatas.*.data'    => 'required|date_format:Y-m-d',
					'predatas.*.hora'    => 'required',
				]);
				if ($validator->fails()) {
					return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
				}
				$preagendamento_data_hora			= date('Y-m-d H:i:s');
				$preagendamento_por					= $request->user()->id;
				break;
			case 3: /* Confirmação Pré-agendamento */
				if ($request->valor > 0)
				{
					$validator = Validator::make($request->all(), [
						'agendar_data' 		=> 'required|date_format:Y-m-d',
						'agendar_hora' 		=> 'required',
						'forma'         	=> 'required|string|max:1|in:C,B,P,S'
					]);
					
					if ($validator->fails()) {
						return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
					}	
					
					if ($request->agendar_data < date('Y-m-d'))
					{
						return response()->json(['error' => 'Data do agendamento não pode ser menor que hoje.'], 422);
					}
					
					$now 								= Carbon::now();
					$agendar_data 						= Carbon::createFromDate($request->agendar_data . " $request->agendar_hora" . ":00");
					$diferenca 							= intval($now->diffInDays($agendar_data));
					/*
					if ($diferenca < 2)
					{
						return response()->json(['error' => "Data do agendamento deve ser no mínimo 2 dias apos a data de hoje. Diferença: $diferenca"], 422);
					}
					*/
					
					
					//$vencimento 						= Carbon::createFromDate($request->vencimento);
					$agendar_data 						= Carbon::createFromDate($request->agendar_data);
					//$diferenca 							= intval($vencimento->diffInDays($agendar_data));
					/*
					if ($diferenca < 1)
					{
						return response()->json(['error' => "Data de vencimento deve ser pelo menos 1 dias antes da data do agendamento. Isto é necessário para que seja compensado o pagamento. Diferença: $diferenca"], 422);
					}
					*/
					if ($request->forma == 'C') 
					{
						$validator = Validator::make($request->all(), [
							'parcelas' 		=> 'required|numeric|between:1,9',
						]);
						if ($validator->fails()) {
							return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
						}
					}
				} else {
					$validator = Validator::make($request->all(), [
						'agendar_data' 		=> 'required|date_format:Y-m-d',
						'agendar_hora' 		=> 'required',
					]);
					if ($validator->fails()) {
						return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
					}	
				}
				$agendamento_data_hora					= $request->agendar_data . " " . $request->agendar_hora . ":00";
				$agendamento_por						= $request->user()->id;
				break;	
		}
			
		if ($agendamento_data_hora !="")
		{
			$agendamento 				             	= \App\Models\ClinicaBeneficiario::where('beneficiario_id','=',$request->beneficiario_id)  
                                                                                         ->where('clinica_id','=',$request->clinica_id) 
																				         ->where('especialidade_id','=',$request->especialidade_id)
																				         ->where('asituacao_id','=',$request->asituacao_id)
																				         ->where('agendamento_data_hora','=',$request->agendamento_data_hora)
																				         ->first();
		} else {
			$agendamento 				             	= \App\Models\ClinicaBeneficiario::where('beneficiario_id','=',$request->beneficiario_id)  
                                                                                         ->where('clinica_id','=',$request->clinica_id) 
																				         ->where('especialidade_id','=',$request->especialidade_id)
																				         ->where('asituacao_id','=',$request->asituacao_id)
																				         ->first();
		}
		
		if (isset($agendamento->id))
        {
			return response()->json(['error' => 'Já existe um agendamento com as informações solicitadas'], 422);
		}
		
		$especialidade 							= \App\Models\Especialidade::find($request->especialidade_id);
		
		if (isset($especialidade->id))
		{
			$tipo 								= $especialidade->tipo;
		} else {
			$tipo 								= "C";
		}
		
		$agendamento            			    = new \App\Models\ClinicaBeneficiario();
		$agendamento->beneficiario_id           = $request->beneficiario_id;
		$agendamento->clinica_id            	= $request->clinica_id;
		$agendamento->especialidade_id          = $request->especialidade_id;
		
		if ($solicitado_data_hora !="")
		{
			$agendamento->solicitado_data_hora	= $solicitado_data_hora;
			$agendamento->solicitado_por		= $solicitado_por;
		} else {
			$agendamento->solicitado_data_hora	= date('Y-m-d H:i:s');
			$agendamento->solicitado_por		= $request->user()->id;
			
		}
		if ($preagendamento_data_hora !="")
		{
			$agendamento->preagendamento_data_hora 	= $preagendamento_data_hora;
			$agendamento->preagendamento_por		= $preagendamento_por;
		}
		if ($agendamento_data_hora !="")
		{
			$agendamento->agendamento_data_hora = $agendamento_data_hora;
			$agendamento->agendamento_por		= $agendamento_por;
		}
		
		$valor 									= (float) (str_replace(",",".",$request->valor) ?? 0);
		$saldo 									= max((float) (str_replace(",",".",$request->saldo) ?? 0), 0); // ignora saldo negativo
		$agendamento->desconto      			= min($saldo, $valor);
		$agendamento->valor_a_pagar   			= max($valor - $saldo, 0.0);
		
		if (($agendamento->valor_a_pagar > 0) and ($request->asituacao_id ==3))
		{
			$validator = Validator::make($request->all(), [
						'vencimento' 		=> 'required|date_format:Y-m-d',
			]);
					
			if ($validator->fails()) {
				return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
			}	
			
			if ($request->vencimento < date('Y-m-d'))
			{
				return response()->json(['error' => 'Data de vencimento não pode ser menor que hoje.'], 422);
			}
			if ($request->vencimento > $request->agendar_data)
			{
				return response()->json(['error' => 'Data de vencimento não pode maior que a data do agendamento.'], 422);
			}
		}
		
		$agendamento->saldo           			= $saldo;
		$agendamento->valor           			= str_replace(",",".",$request->valor);
		$agendamento->cobranca          		= 1;
		$agendamento->confirma          		= 1;
		$agendamento->dmedico					= $request->dmedico;
		$agendamento->tipo 						= $tipo;
		
		if ((!isset($request->dmedico)) or (is_null($request->dmedico)))
		{
			$request->dmedico					= "";
		}
		if ((!isset($request->forma)) or (is_null($request->forma)))
		{
			$request->forma						= "B";
		}
		if ((!isset($request->observacao)) or (is_null($request->observacao)))
		{
			$request->observacao				= "";
		}
		
		if ((!isset($request->altura)) or (!is_numeric($request->altura)))
		{
			$request->altura					= 0;
		}
		
		if ((!isset($request->peso)) or (!is_numeric($request->peso)))
		{
			$request->peso						= 0;
		}
		
		if (!isset($request->medicamento))
		{
			$request->medicamento				= "";
		}
		
		$agendamento->altura					= $request->altura;
		$agendamento->peso						= $request->peso;
		$agendamento->medicamento				= $request->medicamento;
		
		$agendamento->forma          			= $request->forma;
		$agendamento->user_id 					= $request->user()->id;
		$agendamento->observacao          		= $request->observacao;
		
		$cbeneficiario 							= \App\Models\Beneficiario::with('cliente')->find($request->beneficiario_id);
		
		if (isset($cbeneficiario->id))
		{
			$agendamento->cidade 				= $cbeneficiario->cliente->cidade;
			$agendamento->estado 				= $cbeneficiario->cliente->estado;
		}
		
		if ($request->asituacao_id == 3) 
		{
			if ($agendamento->valor_a_pagar > 0)
			{
			
				$agendamento->vencimento 		= $request->vencimento;
				if ($request->forma == 'C') 
				{
					$agendamento->parcelas      = $request->parcelas;
				}
			} else {
				$agendamento->galaxPayId		= -1;
				$request->asituacao_id			= 6;
			}
			if ($agendamento->desconto > 0)
			{
				$cliente 						= \App\Models\Cliente::find($cbeneficiario->cliente->id);
				if (isset($cliente->id))
				{
					$cliente->saldo 			= $cliente->saldo - $agendamento->desconto;
					$cliente->save();
				}
			}
		}
		
		$cespecialidade                   		= \App\Models\ClinicaEspecialidade::where('clinica_id','=',$agendamento->clinica_id)
																				  ->where('especialidade_id','=',$agendamento->especialidade_id)
																				  ->first();
																				  
																				  
		$agendamento->valor_clinica				= $cespecialidade->valor_clinica ?? 0;																		  
		$agendamento->asituacao_id          	= $request->asituacao_id;
		
		if ($agendamento->save())
		{
			if (isset($request->predatas))
			{
				foreach($request->predatas as $predata)
				{
					$data_hora								= $predata->data . " " . $predata->hora . ":00";
					if (\App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$agendamento->id)
														   ->where('data_hora','=',$data_hora)
													       ->count() == 0)
					{
						$cdata            					= new \App\Models\ClinicaBeneficiarioData();
						$cdata->clinica_beneficiario_id		= $agendamento->id;
						$cdata->data_hora					= $data_hora;
						$cdata->escolhido					= 0;
						$cdata->save();
					}
				}
			}
			
			if ($agendamento->asituacao_id == 3)
			{
				if ($agendamento->valor_a_pagar > 0)
				{
					$agendamento->cobranca					= CelCash::storeAgendamentoCharges($agendamento->id);
					if ((isset($agendamento->cobranca->ok)) and ($agendamento->cobranca->ok == 'S'))
					{
						$agendamento->asituacao_id  		= $agendamento->cobranca->asituacao_id;
						$agendamento->galaxPayId			= $agendamento->cobranca->galaxPayId;
						$agendamento->cobranca_url			= $agendamento->cobranca->paymentLink;
						$agendamento->pixpage				= $agendamento->cobranca->pixpage;
						$agendamento->pixqrCode				= $agendamento->cobranca->pixqrCode;
					} else {
						return response()->json(['id'=> $agendamento->id, 'asituacao_id' => $agendamento->asituacao_id, 'mensagem' => "Atenção a cobrança não foi gerado. Motivo: " . substr($agendamento->cobranca->mensagem,0,100)], 200);
					}
				}
			}
			$historico            			    			= new \App\Models\AgendamentoHistorico();
			$historico->clinica_beneficiario_id				= $agendamento->id;
			$historico->user_id 							= $request->user()->id;
			$historico->historico							= "Solicitado pelo CRM em: " . date('d/m/Y H:i:s');			
			$historico->save();
		}
		
		$beneficiario                   					= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
		
		if (isset($beneficiario->id))
		{
			$asituacao 		   								= \App\Models\Asituacao::find($agendamento->asituacao_id);
			if (isset($asituacao->id))
			{
				Cas::enviarMensagemAgendamento($agendamento->id,$agendamento->beneficiario_id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
				if (Cas::nulltoSpace($asituacao->whatsappc) !="") 
				{
					$agendamento                       		= \App\Models\ClinicaBeneficiario::with('clinica')->find($agendamento->id);
					if ((isset($agendamento->id)) and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
					{
						Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$asituacao->whatsappc,$request->user()->id);
					}
				}
			}
		}
		
		$agendamento->mensagem 								= '';
		
	    return response()->json($agendamento, 200);
			
    }

    public function update(Request $request, $id)
    {
		if (!$request->user()->tokenCan('edit.agendamentos')) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar situações.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'beneficiario_id' 	=> 'required|exists:beneficiarios,id',
            'clinica_id' 		=> 'required|exists:clinicas,id',
            'especialidade_id'	=> 'required|exists:especialidades,id',
			'asituacao_id'		=> 'required|exists:asituacoes,id',
			'valor' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			'dmedico'			=> 'nullable',
			'observacao'		=> 'nullable',
			'altura'			=> 'nullable',
			'peso'				=> 'nullable',
			'medicamento'		=> 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		if ((!isset($request->saldo)) or (!is_numeric($request->saldo)))
		{
			$request->saldo 				= 0;
		}
		
		$solicitado_data_hora				= "";
		$preagendamento_data_hora			= "";
		$agendamento_data_hora 				= "";
		$confirmado_data_hora				= "";
		$cancelado_data_hora				= "";
		$alterado							= "";

		$excluir_predatas 					= false;
		
		switch ($request->asituacao_id) 
	    {
			case 1: /* Solicitado */
				$solicitado_data_hora		= date('Y-m-d H:i:s');
				$solicitado_por				= $request->user()->id;
				$excluir_predatas			= true;
				break;
			case 2: /* Pré-agendamento */
				
				$validator = Validator::make($request->all(), [
					'predatas.*.data'    => 'required|date_format:Y-m-d',
					'predatas.*.hora'    => 'required',
				]);
				if ($validator->fails()) {
					return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
				}
				$preagendamento_data_hora	= date('Y-m-d H:i:s');
				$preagendamento_por			= $request->user()->id;
				$excluir_predatas			= true;
				break;
			case 3: /* Confirmação Pré-agendamento */
				if ($request->valor > 0)
				{
					$validator = Validator::make($request->all(), [
						'agendar_data' 		=> 'required|date_format:Y-m-d',
						'agendar_hora' 		=> 'required',
						'forma'         	=> 'required|string|max:1|in:C,B,P',
						'vencimento' 		=> 'required|date_format:Y-m-d',
					]);
					if ($validator->fails()) {
						return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
					}	
					
					if ($request->agendar_data < date('Y-m-d'))
					{
						return response()->json(['error' => 'Data do agendamento não pode ser menor que hoje.'], 422);
					}
					
					$now 								= Carbon::now();
					$agendar_data 						= Carbon::createFromDate($request->agendar_data . " $request->agendar_hora" . ":00");
					$diferenca 							= intval($now->diffInDays($agendar_data));
					/*
					if ($diferenca < 1)
					{
						return response()->json(['error' => "Data do agendamento deve ser no mínimo 1 dias apos a data de hoje. Diferença: $diferenca"], 422);
					}
					*/
					if ($request->vencimento < date('Y-m-d'))
					{
						return response()->json(['error' => 'Data de vencimento não pode ser menor que hoje.'], 422);
					}

					/*
					if ($request->vencimento > $request->agendar_data)
					{
						return response()->json(['error' => 'Data de vencimento não pode ser maior que a data do agendamento.'], 422);
					}
					*/
					
					$vencimento 						= Carbon::createFromDate($request->vencimento);
					$agendar_data 						= Carbon::createFromDate($request->agendar_data);
					$diferenca 							= intval($vencimento->diffInDays($agendar_data));
					/*
					if ($diferenca < 1)
					{
						return response()->json(['error' => "Data de vencimento deve ser pelo menos 1 dias antes da data do agendamento. Isto é necessário para que seja compensado o pagamento. Diferença: $diferenca"], 422);
					}
					*/
					if ($request->forma == 'C') 
					{
						$validator = Validator::make($request->all(), [
							'parcelas' 		=> 'required|numeric|between:1,9',
						]);
						if ($validator->fails()) {
							return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
						}
					}
				} else {
					$validator = Validator::make($request->all(), [
						'agendar_data' 		=> 'required|date_format:Y-m-d',
						'agendar_hora' 		=> 'required',
					]);
					if ($validator->fails()) {
						return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
					}	
				}
				$agendamento_data_hora				= $request->agendar_data . " " . $request->agendar_hora . ":00";
				$agendamento_por					= $request->user()->id;
				$excluir_predatas					= true;
				break;	
			case 4: /* Aguardando Confirmação/Pagto */
				$validator = Validator::make($request->all(), [
						'agendar_data' 		=> 'required|date_format:Y-m-d',
						'agendar_hora' 		=> 'required',
					]);
				if ($validator->fails()) {
					return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
				}	
				$agendamento_data_hora				= $request->agendar_data . " " . $request->agendar_hora . ":00";
				break;
			case 5: /* Cancelado / Não pagou */
			case 8: /* Cancelado */ 
			
				 $validator = Validator::make($request->all(), [
						'cmotivo_id' 	=> 'required|exists:agendamento_cmotivos,id',
				]);

				if ($validator->fails()) {
					return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
				}
		
				$cancelado_data_hora				= date('Y-m-d H:i:s');
				$cancelado_por						= $request->user()->id;
				break;
			case 6: /* Confirmado Agendamento/Pago */
			case 7: /* Confirmado Agendamento */
			
				$validator = Validator::make($request->all(), [
						'agendar_data' 		=> 'required|date_format:Y-m-d',
						'agendar_hora' 		=> 'required',
				]);
				if ($validator->fails()) {
					return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
				}	
					
				if ($request->agendar_data < date('Y-m-d'))
				{
					return response()->json(['error' => 'Data do agendamento não pode ser menor  que hoje.'], 422);
				}
				/*	
				$now 								= Carbon::now();
				$agendar_data 						= Carbon::createFromDate($request->agendar_data . " $request->agendar_hora" . ":00");
				$diferenca 							= intval($now->diffInDays($agendar_data));
			
				if ($diferenca < 1)
				{
					return response()->json(['error' => "Data do agendamento deve ser no mínimo 1 dias apos a data de hoje. Diferença: $diferenca"], 422);
				}
				*/
				$agendamento_data_hora				= $request->agendar_data . " " . $request->agendar_hora . ":00";
				$confirmado_data_hora				= date('Y-m-d H:i:s');
				$confirmado_por						= $request->user()->id;
				break;
			case 9: /* Reagendado */
				$validator = Validator::make($request->all(), [
						'agendar_data' 		=> 'required|date_format:Y-m-d',
						'agendar_hora' 		=> 'required',
				]);
				if ($validator->fails()) {
					return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
				}	
					
				if ($request->agendar_data < date('Y-m-d'))
				{
					return response()->json(['error' => 'Data do agendamento não pode ser menor que hoje.'], 422);
				}
				$agendamento_data_hora				= $request->agendar_data . " " . $request->agendar_hora . ":00";
				break;
			case 10: /* Concluído*/
				break;	
		}
		
		$agendamento 				             = \App\Models\ClinicaBeneficiario::where('beneficiario_id','=',$request->beneficiario_id)  
                                                                                  ->where('clinica_id','=',$request->clinica_id) 
																				  ->where('especialidade_id','=',$request->especialidade_id)
																				  ->where('agendamento_data_hora','=',$request->agendamento_data_hora)
																				  ->where('id','<>',$id)
																				  ->first();
		
		if (isset($agendamento->id))
        {
			return response()->json(['error' => 'Já existe um agendamento com as informações solicitadas'], 422);
		}
		
		if ($excluir_predatas)
		{
			if (\App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$id)->where('escolhido','=',1)->count() > 0)
			{
				return response()->json(['error' => 'Já existe uma data escolhida pelo cliente. Confirme o pre-agendamento, ou reinicie o processo'], 422);
			}
			
			$datas 								= DB::table('clinica_beneficiario_datas')->where('clinica_beneficiario_id','=',$id)->delete();
		}
														
		$agendamento            			    = \App\Models\ClinicaBeneficiario::find($id);
		
		if (!isset($agendamento->id))
        {
			return response()->json(['error' => 'Agendamento não encontrado'], 422);
		}
		
		$agendamentoa							= $agendamento->replicate();
		$asituacao_id 							= $agendamento->asituacao_id;
		
		$especialidade 							= \App\Models\Especialidade::find($request->especialidade_id);
		
		if (isset($especialidade->id))
		{
			$tipo 								= $especialidade->tipo;
		} else {
			$tipo 								= "C";
		}
		
		$agendamento->beneficiario_id           = $request->beneficiario_id;
		$agendamento->clinica_id            	= $request->clinica_id;
		$agendamento->especialidade_id          = $request->especialidade_id;
		
		if ($agendamento_data_hora !="")
		{
			$agendamento->agendamento_data_hora = $agendamento_data_hora;
			$agendamento->agendamento_por		= $request->user()->id;
		}
		
		$valor 									= (float) (str_replace(",",".",$request->valor) ?? 0);
		$saldo 									= max((float) (str_replace(",",".",$request->saldo) ?? 0), 0); // ignora saldo negativo
		$agendamento->saldo           			= $saldo;
		$agendamento->desconto      			= min($saldo, $valor);
		$agendamento->valor_a_pagar   			= max($valor - $saldo, 0.0);
		
		$agendamento->valor           			= str_replace(",",".",$request->valor);
		if ((!isset($request->dmedico)) or (is_null($request->dmedico)))
		{
			$request->dmedico					= "";
		}
		if ((!isset($request->forma)) or (is_null($request->forma)))
		{
			$request->forma						= "B";
		}
		if ((!isset($request->observacao)) or (is_null($request->observacao)))
		{
			$request->observacao				= "";
		}
		if ((!isset($request->parcelas)) or (is_null($request->parcelas)) or ($request->parcelas==0))
		{
			$request->parcelas					= 1;
		}
		
		if ((!isset($request->altura)) or (!is_numeric($request->altura)))
		{
			$request->altura								= 0;
		}
		
		if ((!isset($request->peso)) or (!is_numeric($request->peso)))
		{
			$request->peso									= 0;
		}
		
		if (!isset($request->medicamento))
		{
			$request->medicamento							= "";
		}
		
		$agendamento->altura								= $request->altura;
		$agendamento->peso									= $request->peso;
		$agendamento->medicamento							= $request->medicamento;
		$agendamento->dmedico								= $request->dmedico;
		$agendamento->forma          						= $request->forma;
		$agendamento->observacao          					= $request->observacao;
		$agendamento->parcelas								= $request->parcelas;
		$agendamento->tipo 									= $tipo;
		
		if ($request->asituacao_id == 3) 
		{
			if ($agendamento->valor_a_pagar > 0)
			{
				$agendamento->vencimento 					= $request->vencimento;
				if ($request->forma == 'C') 
				{
					$agendamento->parcelas      			= $request->parcelas;
				}
			} else {
				$agendamento->galaxPayId  					= -1;
				$request->asituacao_id						= 6;
			}
			if ($agendamento->desconto > 0)
			{
				$cbeneficiario                   			= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
				if (isset($cbeneficiario->id))
				{
					$cliente 								= \App\Models\Cliente::find($cbeneficiario->cliente->id);
					if (isset($cliente->id))
					{
						$cliente->saldo 					= $cliente->saldo - $agendamento->desconto;
						$cliente->save();
					}
				}
			}
		}
		
		if (!isset($request->cmotivo_id))
		{
			$request->cmotivo_id							= 0;
		}
		
		if (($request->asituacao_id == 5) or ($request->asituacao_id ==8))
		{
			$agendamento->url_voucher 						= "";
		}
		
		if (!is_numeric($request->cmotivo_id))
		{
			$request->cmotivo_id							= 0;
		}
		
		if ($agendamento->asituacao_id <> $request->asituacao_id)
		{
			if ($preagendamento_data_hora !="")
			{
				$agendamento->preagendamento_data_hora		= $preagendamento_data_hora;
				$agendamento->preagendamento_por			= $request->user()->id;
			}
			
			if ($confirmado_data_hora !="")
			{
				$agendamento->confirmado_data_hora			= $confirmado_data_hora;
				$agendamento->confirmado_por				= $request->user()->id;
			}
			
			if ($cancelado_data_hora !="")
			{
				$agendamento->cmotivo_id					= $request->cmotivo_id;
				$agendamento->cancelado_data_hora			= $cancelado_data_hora;
				$agendamento->cancelado_por					= $request->user()->id;
			}
			
		}
		
		$agendamento->cmotivo_id							= $request->cmotivo_id;
		
		$asituacao 		   									= \App\Models\Asituacao::find($request->asituacao_id);

		if (isset($asituacao->id))
		{
			$agendamento->observacao						= str_replace(array("<p>","</p>"),"",$asituacao->orientacao);
		} else {
			$agendamento->observacao						= "";
		}
	
		$agendamento->asituacao_id          				= $request->asituacao_id;
		
		if (($agendamentoa->clinica_id != $agendamento->clinica_id) or ($agendamentoa->especialidade_id != $agendamento->especialidade_id))
		{
			$cespecialidade                   				= \App\Models\ClinicaEspecialidade::where('clinica_id','=',$agendamento->clinica_id)
																							  ->where('especialidade_id','=',$agendamento->especialidade_id)
																							  ->first();
																					  
																					  
			$agendamento->valor_clinica						= $cespecialidade->valor_clinica ?? 0;	
		}
		
		if ($agendamento->save())
		{
			if (isset($request->predatas))
			{
				foreach($request->predatas as $predata)
				{
					$data_hora								= $predata['data'] . " " . $predata['hora'] . ":00";
					if (\App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$id)
														   ->where('data_hora','=',$data_hora)
													       ->count() == 0)
					{
						$cdata            					= new \App\Models\ClinicaBeneficiarioData();
						$cdata->clinica_beneficiario_id		= $id;
						$cdata->data_hora					= $data_hora;
						$cdata->escolhido					= false;
						$cdata->save();
					}
				}
			}
			if ((($agendamento->asituacao_id == 3) or ($agendamento->asituacao_id == 4)) and ($agendamento->valor_a_pagar > 0) and 
				  ((Cas::nulltoSpace($agendamento->galaxPayId) =="") or (Cas::nulltoSpace($agendamento->galaxPayId) ==0)))
			{
				$agendamento->cobranca						= CelCash::storeAgendamentoCharges($id);
				if ((isset($agendamento->cobranca->ok)) and ($agendamento->cobranca->ok == 'S'))
				{
					$agendamento->asituacao_id  			= $agendamento->cobranca->asituacao_id;
					$agendamento->galaxPayId				= $agendamento->cobranca->galaxPayId;
					$agendamento->cobranca_url				= $agendamento->cobranca->paymentLink;
					$agendamento->pixpage					= $agendamento->cobranca->pixpage;
					$agendamento->pixqrCode					= $agendamento->cobranca->pixqrCode;
				} else {
					return response()->json(['error' => "Atenção a cobrança não foi gerado. Motivo: " . $agendamento->cobranca->mensagem], 422);
				}
			}
		
			if ($agendamento->asituacao_id != $asituacao_id)
			{
				if (($agendamento->asituacao_id == 6) or ($agendamento->asituacao_id ==7) or (($agendamento->asituacao_id ==9) and (!is_null($agendamento->pagamento))))
				{
					Cas::gerarVoucherPDF($agendamento->id);
				}
				
				$beneficiario                   				= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
				
				if (isset($beneficiario->id))
				{
					$asituacao 		   							= \App\Models\Asituacao::find($agendamento->asituacao_id);
					if (isset($asituacao->id))
					{
						
						Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
						if ((Cas::nulltoSpace($asituacao->whatsappc) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
						{
							$clinica 		   					= \App\Models\Clinica::find($agendamento->clinica_id);
							if (isset($asituacao->id))
							{
								Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$clinica->telefone,$asituacao->whatsappc,$request->user()->id);
							}
						}
					}
				}
				
				if (($agendamento->asituacao_id == 5) or ($agendamento->asituacao_id ==8) or ($agendamento->asituacao_id==11))
				{
					if ((Cas::nulltoSpace($agendamento->galaxPayId) !="") and (Cas::nulltoSpace($agendamento->galaxPayId) !=0))
					{
						$response 								= json_decode($agendamento->response);
						if (isset($response->galaxPayId))
						{
							$galaxPayId 						= $response->galaxPayId;
							$cancelar 							= CelCash::cancelCharges($galaxPayId,2);
						}
					}
				
					if (($agendamento->asituacao_id==11) and ($asituacao_id !=11))
					{
						$cliente 								= \App\Models\Cliente::find($beneficiario->cliente->id);
						if (isset($cliente->id))
						{
							$cliente->saldo 					= $cliente->saldo + $agendamento->desconto;
							$cliente->save();
						}
					}
				}
			}
			$historico            			    			= new \App\Models\AgendamentoHistorico();
			$historico->clinica_beneficiario_id				= $agendamento->id;
			$historico->user_id 							= $request->user()->id;
			$historico->historico							= Cas::compararAgendamentos($agendamentoa, $agendamento);			
			$historico->save();
			return response()->json($agendamento, 200);
		}
		
	    return response()->json(['error' => 'Ocorreu erro na tentativa da atualização do agendamento'], 422);
    }

    public function destroy(Request $request, $id)
    {
		if (!$request->user()->tokenCan('delete.agendamentos'))
		{
            return response()->json(['error' => 'Não autorizado para excluir situações.'], 403);
        }

		$agendamento            			    = \App\Models\ClinicaBeneficiario::find($id);
		
		if (!isset($agendamento->id))
        {
			return response()->json(['error' => 'Agendamento não encontrado'], 422);
		}
		
		if ($agendamento->delete())
		{
			$sugestao 							= DB::table('sugestao_datas')
														->where('clinica_beneficiario_id','=',$id)
														->delete();
			$exame 							    = DB::table('exame_pedidos')
														->where('clinica_beneficiario_id','=',$id)
														->delete();
			$datas 							    = DB::table('clinica_beneficiario_datas')
														->where('clinica_beneficiario_id','=',$id)
														->delete();											
		}
		
        return response()->json($id, 200);
    }
	
	public function clinica_search(Request $request)
    {
        $search 							= $request->input('search', '');

        // Busca clínicas com base no nome ou CNPJ
        $clinicas 							= \App\Models\Clinica::with('especialidades')
																 ->where('ativo','=',1)
																 ->where(function ($query) use ($search) {
																			$query->where('nome', 'LIKE', "%{$search}%")
																				  ->orWhere('cnpj', 'LIKE', "%{$search}%");
																		})
																 ->select('id', 'tipo','nome', 'cnpj', 'telefone','logradouro','numero','cep','bairro','cidade','estado')
															     ->get();

        return response()->json($clinicas);
    }
	
	public function beneficiario_search(Request $request)
    {
        $search 							= $request->input('search', '');
        $contrato_id                        = $request->input('contrato_id', 0);
		$beneficiarios						= array();
        // Busca beneficiários com base no nome do cliente
		$lbeneficiarios 						= \App\Models\Beneficiario::join('clientes', 'beneficiarios.cliente_id', '=', 'clientes.id')
																	  ->join('contratos', 'beneficiarios.contrato_id', '=', 'contratos.id')
																	  ->whereIn('contratos.status', ['active','waitingPayment']) // Apenas contratos ativos
																	  ->where('beneficiarios.desc_status', '=', 'ATIVO') // Apenas beneficiarios ativos
																	  ->where(function ($query) use ($contrato_id) {
																			if ($contrato_id > 0)
																			{
																				$query->where('contratos.id', '=', $contrato_id);
																			}
																		})
																	  ->where(function ($query) use ($search) {
																			$query->where('clientes.nome', 'LIKE', "%{$search}%")
																				  ->orWhere('clientes.cpfcnpj', 'LIKE', "%{$search}%");
																		})
																	  ->select(
																			'contratos.tipo as tipo_contrato',
																			'beneficiarios.tipo as tipo_beneficiario',
																			'beneficiarios.parent_id',
																			'beneficiarios.id', 
																			'beneficiarios.contrato_id', 
																			'clientes.nome as nome', 
																			'clientes.data_nascimento',
																			'clientes.telefone as celular', 
																			'clientes.cidade',
																			'clientes.estado',
																			'clientes.saldo',
																			\DB::raw("CONCAT(SUBSTRING(clientes.cpfcnpj, 1, 3), '.', SUBSTRING(clientes.cpfcnpj, 4, 3), '.', SUBSTRING(clientes.cpfcnpj, 7, 3), '-', SUBSTRING(clientes.cpfcnpj, 10, 2)) as cpf"),
																			\DB::raw("TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade"),
																			\DB::raw("CASE 
																							WHEN beneficiarios.tipo = 'T' THEN 'Titular'
																							WHEN beneficiarios.tipo = 'D' THEN 'Dependente'
																							ELSE 'Desconhecido'
																					   END as tipo
																					")
																		)
																	  ->get();
																	  
																	  
		foreach ($lbeneficiarios as $beneficiario)
		{
			list($ano,$mes,$dia)                	= explode("-",$beneficiario->data_nascimento);
			$beneficiario->nascimento 				= "$dia/$mes/$ano"; 			
			$beneficiario->cpf_titular 				= "";
			$contrato_id							= 0;
			
			if ($beneficiario->tipo_beneficiario == 'D')
			{
				if ($beneficiario->tipo_contrato == 'J')
				{
					$tbeneficiario 						= \App\Models\Beneficiario::with('cliente')->find($beneficiario->parent_id);
					if (isset($tbeneficiario->id))
					{
						$beneficiario->cpf_titular		= $tbeneficiario->cliente->cpfcnpj;
						$contrato_id					= $tbeneficiario->contrato_id;
					}
				} else {
					$tbeneficiario 						= \App\Models\Beneficiario::with('cliente')
																					 ->where('contrato_id','=',$beneficiario->contrato_id)
																					 ->where('tipo','=','T')
																					 ->first();
					if (isset($tbeneficiario->id))
					{
						$beneficiario->cpf_titular		= $tbeneficiario->cliente->cpfcnpj;
						$contrato_id					= $tbeneficiario->contrato_id;
					}
				}
			} else {
				$contrato_id							= $beneficiario->contrato_id;
			}
			
			$pstatus									= 'A';
			$mensagem 									= "";							
				
			if ($contrato_id > 0)
			{
				
				$parcela 								= \App\Models\Parcela::where('contrato_id','=',$contrato_id)
																			 ->where('data_pagamento','=',null)
																			 ->where('data_baixa','=',null)
																			 ->where('data_vencimento','<',date('Y-m-d'))
																			 ->orderBy('data_vencimento','asc')
																			 ->first();
				if (isset($parcela->id))
				{
					$date 								= $parcela->data_vencimento. " 23:59:59";
					$vencimento 						= Carbon::createFromDate($date);
					$now 								= Carbon::now();
					$diferenca 							= $vencimento->diffInDays($now);
						
					if ($diferenca >= 2)
					{
						$pstatus						= 'I';
						list($ano,$mes,$dia) 			= explode("-",$parcela->data_vencimento);
						$mensagem 						= "Pendência de pagamento | vencido em: $dia/$mes/$ano";
					}
				}
			}
			$beneficiario->contrato_id 					= $contrato_id;
			$beneficiario->pstatus 						= $pstatus;
			$beneficiario->mensagem 					= $mensagem;
			$beneficiarios[]							= $beneficiario;
		}

        return response()->json($beneficiarios);
    }
	
	public function boleto_view(Request $request, $id)
    {
        $agendamento                            = \App\Models\ClinicaBeneficiario::find($id);

        $response                               = Http::get($agendamento->boletopdf);
        return  response($response->body(), 200)->header('Content-Type', 'application/pdf');
    }
	
	public function voucher_view(Request $request, $id)
    {
        $agendamento                            = \App\Models\ClinicaBeneficiario::find($id);

		if (isset($agendamento->id))
		{
			if (Cas::nulltoSpace($agendamento->url_voucher) =="")
			{
				$url 							= Cas::gerarVoucherPDF($id);
				$response                       = Http::get($url);
				return  response($response->body(), 200)->header('Content-Type', 'application/pdf');
			}
		} 
			
        $response                               = Http::get($agendamento->url_voucher);
        return  response($response->body(), 200)->header('Content-Type', 'application/pdf');
    }
	
	public function preagendamento_expirados(Request $request)
	{
		$hours									= $request->input('hours',48);
		
		$timeLimit 								= Carbon::now()->subHours($hours)->format('Y-m-d H:i:s');
		
		$preagendamentos 						= DB::table('clinica_beneficiario')
													->where('asituacao_id', '=',2)
													->where('preagendamento_data_hora', '<', $timeLimit)
													->get();
		if (count($preagendamentos) ==0)
		{
			return response()->json($timeLimit, 200);
		}
		
		foreach ($preagendamentos as $preagendamento)
		{
			 $agendamento                       	= \App\Models\ClinicaBeneficiario::find($preagendamento->id);
			 if (isset($agendamento->id))
			 {
				 $agendamento->asituacao_id     	= 8;
				 $agendamento->cmotivo_id			= 1;
				 $agendamento->cancelado_data_hora	= date('Y-m-d H:i:s');
				 $agendamento->cancelado_por 		= 1;
				 $agendamento->save();
			 }
		}
		
		return response()->json($preagendamentos, 200);
	}
	
	public function aguardando_confirmacao_pagamento(Request $request)
	{
		$galaxId							= $request->input('galaxId',2); /* Consultas */  
		$consultas 							= array();
		$observacao5						= "";
		$observacao6						= "";

		$asituacao 		   					= \App\Models\Asituacao::find(5);

		if (isset($asituacao->id))
		{
			$observacao5					= str_replace(array("<p>","</p>"),"",$asituacao->orientacao);
			$whatsapp5						= $asituacao->whatsapp;
			$whatsappc5						= $asituacao->whatsappc;
		} 
		
		$asituacao 		   					= \App\Models\Asituacao::find(6);

		if (isset($asituacao->id))
		{
			$observacao6					= str_replace(array("<p>","</p>"),"",$asituacao->orientacao);
			$whatsapp6						= $asituacao->whatsapp;
			$whatsappc6						= $asituacao->whatsappc;
		} 
		
		$pagamentos 						= DB::table('clinica_beneficiario')
												->whereIn('asituacao_id',array(4))
												->where('galaxPayId','>',0)
												->get();
		
		foreach ($pagamentos as $pagamento)
		{
			$response 													= json_decode($pagamento->response);
			$galaxPayId 												= $response->galaxPayId;
			$query                          							= "startAt=0&limit=100&galaxPayIds=". $galaxPayId;
			$consulta 													= CelCash::GetCharges($query,$galaxId);
			
			if ((isset($consulta->Charges)) and (isset($consulta->Charges[0])))
			{
				$Charge													= $consulta->Charges[0];
				if ((isset($Charge->Transactions)) and (isset($Charge->Transactions[0])))
				{
					$Transactions										= $Charge->Transactions[0];
					if (isset($Transactions->paydayDate))
					{
						$agendamento                       				= \App\Models\ClinicaBeneficiario::with('clinica')->find($pagamento->id);
						if (isset($agendamento->id))
						{
							 $agendamento->asituacao_id     			= 6;
							 $agendamento->pagamento_data_hora			= $Transactions->paydayDate;
							 $agendamento->pagamento_por 				= 1;
							 $agendamento->cmotivo_id 					= 0;
							 $agendamento->pagamento					= substr($Transactions->paydayDate,0,10);
							 if ($observacao6 !="")
							 {
								 $agendamento->observacao				= $observacao6;
							 }
							 if ($agendamento->save())
							 {
								 if (($whatsapp6 !="") or ($whatsappc6 !=""))
								 {
									 $beneficiario                      = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
									 if (isset($beneficiario->id))
									 {
										Cas::gerarVoucherPDF($agendamento->id);
										Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$beneficiario->cliente->telefone,$whatsapp6,1);
										if ((Cas::nulltoSpace($whatsappc6) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
										{
											Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$whatsappc6,1);
										}
									}
								 }
							 }
							 $consultas[] 								= $Charge;
						}
					} else {
						if (isset($Transactions->payday))
						{
							$hora 										= date('H');
							
							if ((date('Y-m-d') > $Transactions->payday) and ($hora > '09'))
							{
								$agendamento                       		= \App\Models\ClinicaBeneficiario::with('clinica')->find($pagamento->id);
								if (isset($agendamento->id))
								{
									/* aqui */
									//$agendamento->asituacao_id 			= 5;  (*)
									$agendamento->cmotivo_id 			= 2;
									//$agendamento->cancelado_data_hora	= date('Y-m-d H:i:s');
									//$agendamento->cancelado_por 		= 1;
									//$agendamento->galaxPayId			= "";
									//$agendamento->boletobankNumber		= 0;
									//$agendamento->paymentLink			= "";
									//$agendamento->boletopdf				= "";
									//$agendamento->pixpage				= "";
									//$agendamento->piximage				= "";
									//$agendamento->pixqrCode				= "";
									/*
									14
									15 (*) (Sabado e domingo)
									*/
									if ($observacao5 !="")
									{
										 $agendamento->observacao		= $observacao5;
									}
									if ($agendamento->save())
									{
										if (($whatsapp5 !="") or ($whatsappc5 !=""))
										{
											 $beneficiario              = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
											 if (isset($beneficiario->id))
											 {
												Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$beneficiario->cliente->telefone,$whatsapp5,1);
												if ((Cas::nulltoSpace($whatsappc5) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
												{
													Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$whatsappc5,1);
												}
											 }
										}
									}
									$consultas[] 						= $Charge;
								}
							}
						}
					}
				}
			}
		}
		
		return response()->json($consultas, 200);
	}
	
	public function sincronizar_pagamento_empresas(Request $request)
	{
		$galaxId							= $request->input('galaxId',1); /* Consultas */  
		$consultas 							= array();
		
		$pagamentos 						= DB::table('contratos')
												->where('status','=','active')
												->where('tipo','=','J')
												->get();
		
		foreach ($pagamentos as $pagamento)
		{
			if (\App\Models\Beneficiario::where('contrato_id','=',$pagamento->id)->where('ativo','=',1)->count() > 0)
			{ 
				$galaxPayId 												= $pagamento->galaxPayId;
				$query                          							= "startAt=0&limit=100&galaxPayIds=". $galaxPayId;
				$consulta 													= CelCash::GetCharges($query,$galaxId);
				
				if ((isset($consulta->Charges)) and (isset($consulta->Charges[0])))
				{
					$Charge													= $consulta->Charges[0];
					if ((isset($Charge->Transactions)) and (isset($Charge->Transactions[0])))
					{
						$Transactions										= $Charge->Transactions[0];
						if (isset($Transactions->paydayDate))
						{
							list($contrato_id,$parcela_id,$aleatorio)       = explode("#",$Transactions->chargeMyId);
							$parcela              							= \App\Models\Parcela::find($parcela_id);
							if (isset($parcela->id))
							{
								if ($parcela->galaxPayId == $Transactions->galaxPayId)
								{
									if (is_null($parcela->data_pagamento))
									{
										$parcela->data_pagamento			= substr($Transactions->paydayDate,0,10);
										if ($parcela->save())
										{
											$consultas[]					= $Transactions->chargeMyId;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		
		return response()->json($consultas, 200);
	}
	
	public function gerar_voucher(Request $request, $id)
	{
		$voucher 						= Cas::gerarVoucherPDF($id);
		return response()->json($voucher, 200);
	}
	
	public function enviar_mensagem_whatsapp(Request $request, $id)
	{
		
		if (!isset($request->tipo))
		{
			$request->tipo				= "";
		}
		$agendamento                    = \App\Models\ClinicaBeneficiario::find($id);
		
		if (isset($agendamento->id))
		{
			$situacao 		   			= \App\Models\Asituacao::find($agendamento->asituacao_id);
			if (isset($situacao->id))
			{
				$beneficiario           = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
				if (isset($beneficiario->id))
				{
					$payload               					= new stdClass;
					$payload->beneficiario_id 				= $agendamento->beneficiario_id;
					$payload->numero						= preg_replace('/\D/', '', $beneficiario->cliente->telefone);
					if (Cas::nulltoSpace($request->tipo) == "")
					{
						$harmonizar							= Cas::harmonizarMensagemAgendamento($agendamento->id,$situacao->whatsapp);
						if (isset($harmonizar->mensagem))
						{
							$payload->mensagem				= $harmonizar->mensagem;
							$payload->arquivo				= $harmonizar->arquivo;
						} else {
							$payload->mensagem				= $harmonizar;
							$payload->arquivo				= "";
						}
					} else {
						$payload->arquivo					= "";
						if ($request->tipo == "url_cobranca")
						{
							$payload->mensagem			   	= "Segue o link da cobrança:\n" . $agendamento->paymentLink;
						} else {
							$payload->mensagem			   	= "Abaixo o código do PIX:\n" . $agendamento->pixqrCode;
						}
					}
					$payload->enviado_por 					= $request->user()->id;
					$payload->token 						= '5519998557120';
		 
					$enviar 								= Cas::chatHotMensagem($payload);
					if ($enviar->ok =='S') 
					{
						return response()->json(['mensagem' => 'Mensagem enviada pelo whatsapp com sucesso!'], 200);
					} else {
						return response()->json(['mensagem' => 'Problema na comunicação com o Chat Hot. Mensagem não enviada. Tente novamente!'], 404);
					}
				}
			}
		}
		
		return response()->json(['mensagem' => 'Ocorreu erro na tentativa de enviar a mensagem. Mensagem não enviada'], 404);
	}
	
	public function enviar_cobrancaWhatsapp(Request $request, $id)
    {
		
		$forma              					= $request->input('forma', 'B');
		
		$agendamento                    		= \App\Models\ClinicaBeneficiario::find($id);
		
		if (isset($agendamento->id))
		{
			return response()->json(['mensagem' => 'Parcela não encontrada.'], 404);
		}
		
		if ($forma == 'B')
		{
			$mensagem 							= 'Olá, segue o link do boleto da parcela. ';
			$mensagem 							.= "\n" . $parcela->boletopdf;
		} else {
			$mensagem 							= 'Olá, segue o link do PIX para pagamento da parcela. ';
			$mensagem 							.= "\n" . $parcela->pixpage;
		}
		
		$beneficiario           				= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
		if (isset($beneficiario->id))
		{
			$payload               				= new stdClass;
			$payload->beneficiario_id 			= $agendamento->beneficiario_id;
			$payload->numero					= preg_replace('/\D/', '', $beneficiario->cliente->telefone);
			
			$harmonizar							= Cas::harmonizarMensagemAgendamento($agendamento->id,$mensagem);
			if (isset($harmonizar->mensagem))
			{
				$payload->mensagem				= $harmonizar->mensagem;
				$payload->arquivo				= $harmonizar->arquivo;
			} else {
				$payload->mensagem				= $harmonizar;
				$payload->arquivo				= "";
			}
			
			$payload->enviado_por 				= $request->user()->id;
			$payload->token 					= '5519998557120';
		 
			$enviar 							= Cas::chatHotMensagem($payload);
			if ($enviar->ok =='S') 
			{
				return response()->json(['mensagem' => 'Mensagem enviada pelo whatsapp com sucesso!'], 200);
			} else {
				return response()->json(['mensagem' => 'Problema na comunicação com o Chat Hot. ensagem não enviada'], 404);
			}
		}
			
		return response()->json(['mensagem' => 'Ocorreu erro no envio da mensagem no whatsapp. Entre em contato com o administrador do Chat Hot'], 404);
	}
	
	public function validar_token_otp (Request $request) 
	{
		$validator = Validator::make($request->all(), [
			'token' => 'required'
		]);
		
		if ($validator->fails()) 
		{
			return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
		}
		
		$token 									= \App\Models\Token::where('token','=',$request->token)->first();

        if (!isset($token->id))
        {
            return response()->json(['error' => 'Token não encontrado. Verifique o token recebido da coordenação!'], 404);
        }
		
		 if ($token->user_id > 0)
        {
            return response()->json(['error' => 'Token já foi utilizado. Solicite outro para a coordenação!'], 404);
        }
		
		return response()->json(true, 200);
	}
	
	public function cancelar_cobranca(Request $request, $id)
    {
		if (!$request->user()->tokenCan('edit.agendamentos')) 
		{
            return response()->json(['mensagem' => 'Não autorizado para atualizar situações.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'token' => 'required'
		]);
		
		if ($validator->fails()) 
		{
			return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
		}
		
		$token 									= \App\Models\Token::where('token','=',$request->token)->first();

        if (!isset($token->id))
        {
            return response()->json(['mensagem' => 'Token não encontrado. Verifique o token recebido da coordenação!'], 404);
        }
		
		 if ($token->user_id > 0)
        {
            return response()->json(['mensagem' => 'Token já foi utilizado. Solicite outro para a coordenação!'], 404);
        }
		
		$agendamento 				             = \App\Models\ClinicaBeneficiario::find($id);
		
		if (!isset($agendamento->id))
        {
			return response()->json(['mensagem' => 'Agendamento não encontrado'], 422);
		}
		
		if ($agendamento->galaxPayId ==0)
		{
			return response()->json(['mensagem' => 'Cobrançao não encontrada'], 422);
		}
		
		$response 								= json_decode($agendamento->response);
		if (isset($response->galaxPayId))
		{
			$galaxPayId 						= $response->galaxPayId;
			$cancelar 							= CelCash::cancelCharges($galaxPayId,2);
			if ((isset($cancelar->statcode)) and ($cancelar->statcode ==200))
			{
				$agendamento->galaxPayId		= 0;
				$agendamento->boletobankNumber	= 0;
				$agendamento->paymentLink		= "";
				$agendamento->boletopdf			= "";
				$agendamento->pixpage			= "";
				$agendamento->piximage			= "";
				$agendamento->pixqrCode			= "";
				$agendamento->asituacao_id 		= 3;
				if ($agendamento->save())
				{
					$beneficiario               = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
					if (isset($beneficiario->id))
					{
						$cpf 					= $beneficiario->cliente->cpfcnpj;
					} else {
						$cpf 					= "";
					}
					$token->user_id 			= $request->user()->id;
					$token->observacao 			= "Cancelou o pagamento do agendamento do CPF: " . $cpf;
					$token->save();
					
					$historico            			    				= new \App\Models\AgendamentoHistorico();
					$historico->clinica_beneficiario_id					= $agendamento->id;
					$historico->user_id 								= $request->user()->id;
					$historico->historico								= "Cancelou o pagamento do agendamento do CPF: " . $cpf;	
					$historico->save();
				}
				return response()->json(['ok' => 'S', 'asituacao_id' => $agendamento->asituacao_id], 200);
			} else {
				return response()->json(['mensagem' => 'Cell Cash com instabilidade. Tente novamente mais tarde!'], 422);
			}
		} else {
			return response()->json(['mensagem' => 'Cobrançao não encontrada'], 422);
		}
	}
	
	public function confirmar_pagamento (Request $request) 
	{
		$validator = Validator::make($request->all(), [
			'agendamento_id' 	=> 'required',
			'forma_pagamento' 	=> 'required|string|max:1|in:D,M',
			'data_pagamento' 	=> 'required|date_format:Y-m-d',
		]);
		
		if ($validator->fails()) 
		{
			return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
		}
		
		$agendamento                         	= \App\Models\ClinicaBeneficiario::with('clinica')->find($request->agendamento_id);

        if (!isset($agendamento->id))
        {
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		
		if ($agendamento->galaxPayId ==0)
		{
			 return response()->json(['mensagem' => 'Não existe cobrança'], 404);
		}
		
		if (!is_null($agendamento->pagamento))
		{
			 return response()->json(['mensagem' => 'A cobarnça já foi paga em '. $agendamento->pagamento], 404);
		}
		
		$response 								= json_decode($agendamento->response);
		$galaxPayId 							= $response->galaxPayId;
			
		$payload               					= new stdClass;
		$payload->payedOutsideGalaxPay			= true;
		$payload->additionalInfo				= 'Paga fora do sistema | Forma: ' . $request->forma_pagamento;
			
		$alterar 								= CelCash::alterarCharges($galaxPayId,$payload,"galaxPayId",2);
		
		if (!isset($alterar->error))
		{
			$agendamento->pagamento_data_hora	= date('Y-m-d H:i:s');
			$agendamento->pagamento_por			= $request->user()->id;
			$agendamento->pagamento				= $request->data_pagamento;
			$agendamento->forma 				= $request->forma_pagamento;
			$agendamento->asituacao_id 			= 6;

			if ($agendamento->save())
			{
				$beneficiario                   = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
				if (isset($beneficiario->id))
				{
					Cas::gerarVoucherPDF($agendamento->id);
					$asituacao 		   			= \App\Models\Asituacao::find(6);

					if (isset($asituacao->id))
					{
						$observacao6			= str_replace(array("<p>","</p>"),"",$asituacao->orientacao);
						$whatsapp6				= $asituacao->whatsapp;
						$whatsappc6				= $asituacao->whatsappc;
						Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$beneficiario->cliente->telefone,$whatsapp6,1);
						if ((Cas::nulltoSpace($whatsappc6) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
						{
							Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$whatsappc6,1);
						}
					}
				}
				$historico            			    				= new \App\Models\AgendamentoHistorico();
				$historico->clinica_beneficiario_id					= $agendamento->id;
				$historico->user_id 								= $request->user()->id;
				$historico->historico								= $payload->additionalInfo;	
				$historico->save();
			}
			$alterar->ok 					= 'S';
			return response()->json($alterar, 200);
		} else {
			return response()->json(['mensagem' => $alterar->error->message], 404);
		}
		
	}
	
	public function cancelar_pagamento_saldo ($id) 
	{
		
		$agendamento                         	= \App\Models\ClinicaBeneficiario::find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		
		if ($agendamento->desconto ==0)
		{
			 return response()->json(['mensagem' => 'Não saldo para cancelar'], 404);
		}
		
		$beneficiario                   			= \App\Models\Beneficiario::find($agendamento->beneficiario_id);
		
		
		if (!isset($beneficiario->id))
        {
            return response()->json(['mensagem' => 'Beneficiario/Agendamento não encontrado.'], 404);
        }
		
		$cliente 									= \App\Models\Cliente::find($beneficiario->cliente_id);
		
		if (!isset($cliente->id))
		{
			 return response()->json(['mensagem' => 'Cliente/Beneficiario/Agendamento não encontrado.'], 404);
		}
		
		$cliente->saldo 							= $cliente->saldo + $agendamento->desconto;
		$saldo 										= $agendamento->saldo;
		
		if ($cliente->save())
		{
			$agendamento->asituacao_id 				= 4;
			//$agendamento->saldo 					= 0;
			$agendamento->desconto 					= 0;
			$agendamento->valor_a_pagar 			= 0;
			$agendamento->pagamento					= null;
			if ($agendamento->save())
			{
				$historico            			    = new \App\Models\AgendamentoHistorico();
				$historico->clinica_beneficiario_id	= $agendamento->id;
				$historico->user_id 				= $request->user()->id;
				$historico->historico				= "Saldo retornado para o cliente: R$" . str_replace(",",".",$saldo);	
				$historico->save();
			}
		}
		
		return response()->json(['ok' => 'S', 'saldo' => $saldo , 'asituacao_id' => 3], 200);
		
	}
	
	public function agendamentos_historico ($id) 
	{
		$agendamento                         		= \App\Models\ClinicaBeneficiario::with('especialidade')->find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		
		$beneficiario                   			= \App\Models\Beneficiario::find($agendamento->beneficiario_id);
		
		
		if (!isset($beneficiario->id))
        {
            return response()->json(['mensagem' => 'Beneficiario/Agendamento não encontrado.'], 404);
        }
		
		$cliente 									= \App\Models\Cliente::find($beneficiario->cliente_id);
		
		if (!isset($cliente->id))
		{
			 return response()->json(['mensagem' => 'Cliente/Beneficiario/Agendamento não encontrado.'], 404);
		}
		
		$beneficiario								= new stdClass;
		$beneficiario->cpf							= $cliente->cpfcnpj;
		$beneficiario->nome							= $cliente->nome;
		$beneficiario->celular						= $cliente->telefone;
		$beneficiario->especialidade				= $agendamento->especialidade->nome ?? "";
		
		$response 									= new stdClass;
		$response->beneficiario 					= $beneficiario;
		
		$rhistoricos                   				= \App\Models\AgendamentoHistorico::with('usuario')->where('clinica_beneficiario_id','=',$id)->get();
		$historicos 								= array();
		
		foreach($rhistoricos  as $rhistorico)
		{
			$historico 								= new stdClass;
			$historico->id							= $rhistorico->id;
			$historico->agendamento_id				= $id;
			$historico->usuario_id					= $rhistorico->user_id;
			$historico->usuario_nome				= $rhistorico->usuario->name ?? "";
			$historico->campos_alterados			= $rhistorico->historico;
			$historico->created_at					= $rhistorico->created_at;
			$historico->updated_at					= $rhistorico->updated_at;
			$historicos[]							= $historico;
		}
		
		$response->historicos 						= $historicos;
		
		return response()->json($response, 200);
		
	}
	
	public function atendimentos_clinicas(Request $request)
    {
		
		$sql 										= "SELECT id, nome,cnpj FROM clinicas where ativo=1";
		if ($request->user()->clinica_id > 0)
		{
			$sql                                   .= " and id=" .  $request->user()->clinica_id;
		} else {
			$sql                                   .= " order by nome";
		}
		
		$clinicas									= DB::select($sql);
		
		return response()->json($clinicas, 200);
	
	}
	
	public function atendimentos_index(Request $request)
    {
		
		// Recebe as datas opcionais (assumindo que vêm de request, parâmetros de função, etc.)
		$data_inicio_param 							= $request->input('dataInicial'); // ou parâmetro da função
		$data_fim_param 							= $request->input('dataFinal');       // ou parâmetro da função
		$clinica_id 								= $request->input('clinicaId',-1);
		$situacao 									= $request->input('situacao','A');
		
		
		 Log::info("data_inicio_param", ['data_inicio_param' => $data_inicio_param ]);
		  Log::info("data_fim_param", ['data_fim_param' => $data_fim_param ]);
		   Log::info("clinica_id", ['clinica_id' => $clinica_id ]);
		     Log::info("situacao", ['situacao' => $situacao ]);
		 
		if ($situacao == 'A')
		{
			$asituacao_id							= array('6,','7','9');
		} else {
			if ($situacao == 'R')
			{
				$asituacao_id						= array('10');
			} else {
				$asituacao_id						= array('12');
			}
		}
		
		if ($request->user()->clinica_id > 0)
		{
			$clinica_id								= $request->user()->clinica_id;
		} 
		
		// Define as datas: usa as informadas ou padrão (hoje)
		if ($data_inicio_param) {
			$data_inicio 							= Carbon::createFromFormat('Y-m-d', $data_inicio_param, 'America/Sao_Paulo')->startOfDay();
		} else {
			$data_inicio 							= Carbon::now('America/Sao_Paulo')->startOfDay();
		}

		if ($data_fim_param) {
			$data_fim 								= Carbon::createFromFormat('Y-m-d', $data_fim_param, 'America/Sao_Paulo')->endOfDay();
		} else {
			$data_fim 								= Carbon::now('America/Sao_Paulo')->endOfDay();
		}

		$data_inicio								= $data_inicio 	. " 00:00:00";
		$data_fim									= $data_fim 	. " 23:59:59";
		
		$atendimentos								= array();
		
		if ($clinica_id > 0)
		{
			$latendimentos 								= \App\Models\ClinicaBeneficiario::with('clinica','beneficiario','especialidade')
																						->where('clinica_id', '=', $clinica_id)
																						->whereBetween('agendamento_data_hora', [$data_inicio, $data_fim])
																						->whereIn('asituacao_id',$asituacao_id)
																						->orderBy('agendamento_data_hora')
																						->get();
																						
			foreach($latendimentos  as $atendimento)
			{
				
				$beneficiario                   		= \App\Models\Beneficiario::with('cliente')->find($atendimento->beneficiario_id);
				
				$reg									= new stdClass;
				$reg->id								= $atendimento->id;
				$reg->paciente							= $beneficiario->cliente->nome ?? "";
				$reg->cpf								= $beneficiario->cliente->cpfcnpj ?? "";
				$reg->telefone							= $beneficiario->cliente->telefone ?? "";
				$reg->dataAgendamento					= substr($atendimento->agendamento_data_hora,0,10);
				$reg->horaAgendamento					= substr($atendimento->agendamento_data_hora,11,5);
				$reg->especialidade						= $atendimento->especialidade->nome ?? "";
				$numeroVoucher							= 'VCH-' . str_pad($atendimento->id, 6, '0', STR_PAD_LEFT);
				$reg->numeroVoucher						= Crypt::encryptString($numeroVoucher);

				if (($atendimento->asituacao_id == 6) or 
					($atendimento->asituacao_id == 7) or 
					($atendimento->asituacao_id == 9))
				{
					$reg->status						= "Agendado";
				} else {
					if ($atendimento->asituacao_id == 10)
					{
						$reg->status					= "Realizado";
					} else {
						if ($atendimento->asituacao_id == 12)
						{
							$reg->status				= "Faltou";
						} else {
							$reg->status				= "";
						}
					}
				}
				$reg->clinica							= $atendimento->clinica->nome ?? "";
				$reg->horaChegada						= null;
				$reg->horaInicio						= null;
				$reg->horaFim							= null;
				$reg->valor_cliente 					= $atendimento->valor;
				$reg->valor_clinica 					= $atendimento->valor_clinica;
				
				if ($atendimento->valor_clinica ==0)
				{
					$cespecialidade               		= \App\Models\ClinicaEspecialidade::where('clinica_id','=',$atendimento->clinica_id)
																						  ->where('especialidade_id','=',$atendimento->especialidade_id)
																						  ->first();
							
					$reg->valor_clinica 				= $cespecialidade->valor_clinica ?? 0;
				}
				$reg->observacoes						= "";	
				$atendimentos[]							= $reg;
			}
		}
		
		return response()->json($atendimentos, 200);
	}
	
	public function atendimentos_status(Request $request,$id)
    {
		$status 									= $request->input('status','');
		
		
		$agendamento                   				= \App\Models\ClinicaBeneficiario::find($id);
		
		 if (!isset($agendamento->id))
        {
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		
		$asituacao_id 								= 0;
		$hhistorico 									= "";
		
		if ($status=='agendado')   
		{
			$asituacao_id 							= 6;
			$hhistorico								= 'Voltou a situação para agendado';
		} else { 
			if ($status=='cancelado')
			{ 
				$asituacao_id  						= 12;
				$hhistorico							= 'Faltou';
			} else {
				$asituacao_id  						= 10;
				$hhistorico							= 'Confirmou o atendimento pela clinica';
			}				
		}		
		
		if ($asituacao_id  > 0)
		{
			$agendamento->asituacao_id 				= $asituacao_id;
			if ($agendamento ->save())
			{
				$historico            			    = new \App\Models\AgendamentoHistorico();
				$historico->clinica_beneficiario_id	= $agendamento->id;
				$historico->user_id 				= $request->user()->id;
				$historico->historico				= $hhistorico;	
				$historico->save();
			}
		}
		
		return response()->json(true, 200);
	}
	
	public function atendimentos_voucher(Request $request)
    {
		
         try {
			$voucherDigitado 						= $request->input('voucher_digitado');
			
			$voucherDigitado 						= preg_replace('/[^0-9]/', '', $voucherDigitado);
			$voucherDigitado 						= str_pad($voucherDigitado, 6, "0", STR_PAD_LEFT);	
			
			$voucherDigitado						= 'VCH-' . $voucherDigitado;
		
			$voucherCriptografado 					= $request->input('voucher_criptografado');
			
			// Descriptografar o voucher do banco
			$voucherDescriptografado 				= Crypt::decryptString($voucherCriptografado);
			
			// Comparar os vouchers
			$valido 								= ($voucherDigitado === $voucherDescriptografado);
			
			// Retornar true/false diretamente
			return response()->json($valido);
			
			// OU retornar objeto (ambos são suportados)
			// return response()->json(['valido' => $valido]);
			
		} catch (\Exception $e) {
			return response()->json(false, 400);
		}
	}
	
}
