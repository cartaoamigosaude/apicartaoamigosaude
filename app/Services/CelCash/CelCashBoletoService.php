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

class CelCashBoletoService
{

    public static function obterCarne($galaxPayId,$galaxId=1,$tipo='onePDFSubscription')
    {
        $token                           = CelCash::Token($galaxId);

       // Log::info("galaxPayId", ['galaxPayId' => $galaxPayId ]);
        
        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/carnes/$tipo/none";
        $retorno 						= new stdClass();
		
		$json 							= '{"galaxPayIds": [' . $galaxPayId . ']}';

		$payload 						= json_decode($json, true); 

		Log::info("payload", ['payload' => $payload]);
		Log::info("endpoint", ['endpoint' => $endpoint]);
		Log::info("token", ['token' => $token->token]);
 
        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->post($endpoint,$payload);
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
		
		Log::info("response", ['response' => $response]);
        return $response;
    }

    public static function obterBoletos($galaxPayId,$galaxId=1)
    {
        $token                           = CelCash::Token($galaxId);

        //Log::info("celcash", ['ctoken' => $token ]);
        
        if (!isset($token->token))
        {
            return                      $token;
        }

        $endpoint                       = $token->url . "/boletos/transactions";
        $retorno 						= new stdClass();

        $payload                        = [
                                                "galaxPayIds"   => $galaxPayId,
                                                "order"         => "transactionPayday.asc"
                                          ];

        try {
            $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Bearer $token->token",
                                                    'Content-Type'  => 'application/json'
                                                ])->post($endpoint,$payload);
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

	public static function CelCashParcelasAvulsa($contrato_id)
	{ 
	
		$parcelas				                						= \App\Models\Parcela::where('contrato_id','=',$contrato_id)  
																							 ->where('galaxPayId','=',0)  
																							 ->orderBy('nparcela')
																							 ->get();
																	 
		foreach ($parcelas as $parcela)
		{
			if ($parcela->galaxPayId ==0)
			{
				CelCashParcelaAvulsaJob::dispatch($parcela->id)->onQueue('default');
			}
		}
		
	}

	public static function CelCashParcelaAvulsa($parcela_id)
	{ 
	
		$charges														= CelCash::storeContratoCharges($parcela_id);
				
		if (($charges->ok == 'S') and (isset($charges->Charge)))
		{
				$scharge 												= CelCash::updateContratoWithCharge($charges->Charge);
		}
		
	}

	public static function celcashPagamentoAvulso($galaxId=1)
	{ 
		$retornos 							= array();
		
		$sql                                = 'SELECT p.id FROM parcelas p JOIN contratos c ON p.contrato_id = c.id';
		$sql								.=	' WHERE p.data_pagamento IS NULL';
		$sql								.=	' 	AND p.data_baixa IS NULL';
		$sql								.=	' 	AND p.data_vencimento < CURDATE()';
		$sql								.=	" 	AND c.tipo = 'F'";
		$sql								.=	" 	AND c.avulso = 'S'";
		$sql								.=	" 	AND c.status in ('active','waitingPayment')";
		$sql								.=	' ORDER BY p.data_vencimento ASC';

		$vencidas 							= DB::connection('mysql')->select($sql);
		
		foreach ($vencidas as $vencida)
		{
			$retorno						= CelCash::CelCashParcelaConciliar($vencida->id);	
			$retornos[] 					= $retorno;
		}
		
		
		$sql                                = 'SELECT p.id FROM parcelas p JOIN contratos c ON p.contrato_id = c.id';
		$sql								.=	' WHERE p.data_pagamento IS NULL';
		$sql								.=	' 	AND p.data_baixa IS NULL';
		$sql								.=	' 	AND p.data_vencimento < CURDATE()';
		$sql								.=	" 	AND c.tipo = 'J'";
		$sql								.=	" 	AND c.status in ('active','waitingPayment')";
		$sql								.=	' ORDER BY p.data_vencimento ASC';

		$vencidas 							= DB::connection('mysql')->select($sql);
		
		foreach ($vencidas as $vencida)
		{
			$retorno						= CelCash::CelCashParcelaConciliar($vencida->id);	
			$retornos[] 					= $retorno;
		}
		
		return $retornos;
	}

	public static function celcashPagamentoEmpresa($galaxId=1)
	{ 
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
		
		return $consultas;
	}

	public static function celcashPagamentoConsulta($galaxId=2)
	{ 
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
												->where('asituacao_id','=',4)
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
								 $historico            			    	= new \App\Models\AgendamentoHistorico();
								 $historico->clinica_beneficiario_id	= $agendamento->id;
								 $historico->user_id 					= 1;
								 $historico->historico					= "Pagamento realizado em: " . date('d/m/Y H:i:s');	
								 $historico->save();
							 }
							 $consultas[] 								= $Charge;
						}
					} else {
						if (isset($Transactions->payday))
						{
							$hora 										= date('H');
							$agendamento                       			= \App\Models\ClinicaBeneficiario::with('clinica')->find($pagamento->id);
							if (isset($agendamento->id))
							{
								//continue;
								if (($agendamento->vencimento < date('Y-m-d')) and ($agendamento->cmotivo_id <> 2) and (Cas::nulltoSpace($agendamento->galaxPayId) <> "") and ($hora > '12'))
								{
									
									$agendamento->cmotivo_id 			= 2;
									/*
									$agendamento->asituacao_id 			= 5;
									$agendamento->cancelado_data_hora	= date('Y-m-d H:i:s');
									$agendamento->cancelado_por 		= 1;
									$agendamento->galaxPayId			= "";
									$agendamento->boletobankNumber		= 0;
									$agendamento->paymentLink			= "";
									$agendamento->boletopdf				= "";
									$agendamento->pixpage				= "";
									$agendamento->piximage				= "";
									$agendamento->pixqrCode				= "";
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
										$historico            			    	= new \App\Models\AgendamentoHistorico();
										$historico->clinica_beneficiario_id		= $agendamento->id;
										$historico->user_id 					= 1;
										$historico->historico					= "Expirou prazo para pagamento em: " . date('d/m/Y H:i:s');	
										$historico->save();
									}
									$consultas[] 						= $Charge;
								}
							}
						}
					}
				}
			}
		}
		
		$pagamentos 						= DB::table('clinica_beneficiario')
												->where('asituacao_id','=',5)
												->where('galaxPayId','>',0)
												->where('agendamento_data_hora','>',date('Y-m-d H:i:s'))
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
								 $historico            			    	= new \App\Models\AgendamentoHistorico();
								 $historico->clinica_beneficiario_id	= $agendamento->id;
								 $historico->user_id 					= 1;
								 $historico->historico					= "Pagamento realizado em: " . date('d/m/Y H:i:s');	
								 $historico->save();
							 }
							 $consultas[] 								= $Charge;
						}
					}
				}
			}
		}
		
		return $consultas;
	}
}
