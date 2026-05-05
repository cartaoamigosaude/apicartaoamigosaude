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

class CelCashMigrationService
{

    public static function CelCashMigrarClientes($galaxId)
    {
        if (\Cache::has('cel_cash_startAt'))
        {
            $cel_cash_startAt           = \Cache::get('cel_cash_startAt');
        } else {
            $cel_cash_startAt           = 0;
            \Cache::forever('cel_cash_startAt',$cel_cash_startAt);
        }

        $query                           = "startAt=" . $cel_cash_startAt . "&limit=100";

       // Log::info("celcash", ['query' => $query ]);

        $customers                       = CelCash::ListCustomers($query,$galaxId);

        if (isset($customers->Customers))
        {
            foreach ($customers->Customers as $customer)
            {
                $cliente                = CelCash::CelCashMigrarCliente($customer,$galaxId);
                if ($cliente->ok)
                {
                    $cel_cash_startAt   = $cel_cash_startAt + 1;
                    \Cache::forever('cel_cash_startAt', $cel_cash_startAt);
                } else {
                    break;
                }
            }
            //Log::info("celcash", ['totalQtdFoundInPage' =>  $customers->totalQtdFoundInPage ]);
			if ($customers->totalQtdFoundInPage ==0)
			{
				$cel_cash_startAt       = 0;
				\Cache::forever('cel_cash_startAt',$cel_cash_startAt);
			}
            return $customers->totalQtdFoundInPage;
        } else {
            Log::info("celcash", ['customers' => $customers ]);
        }

        return 0;

    }

    public static function CelCashMigrarCliente($celcash,$galaxId=1)
    {

        $retorno 				    = new stdClass();
        $retorno->ok 		        = true;
        $retorno->cliente_id        = 0;
        $retorno->tipo              = "";
        $retorno->galaxPayId        = $celcash->galaxPayId;

        $data_nascimento            = "1900-01-01";
        $sexo                       = "F";

        if (isset($celcash->ExtraFields))
        {
            foreach ($celcash->ExtraFields as $extrafield)
            {
                switch ($extrafield->tagName) {
                    case 'CP_DATA_NASCIMENTO':
                        list($dia,$mes,$ano)        = explode("/", $extrafield->tagValue);
                        $data_nascimento            = $ano . "-" . $mes . "-" . $dia;
                        break;
                    case 'CP_SEXO':
                        $sexo                       = substr($extrafield->tagValue,0,1);
                        break;
                }
            }
        }

        $cliente                    = \App\Models\Cliente::where('cpfcnpj','=',$celcash->document)->first();

        if (isset($cliente->id))     
        {
            $retorno->cliente_id   = $cliente->id;
            $retorno->tipo         = $cliente->tipo;
            return $retorno;
        }                               

        $cliente            		= new \App\Models\Cliente();

        if (strlen($celcash->document) <= 11)
        {
            $cliente->tipo          = "F";
        } else {
            $cliente->tipo          = "J";
        }
        $cliente->cpfcnpj           = $celcash->document;
        $cliente->nome              = $celcash->name;
        if ((isset($celcash->phones)) and (is_array($celcash->phones)) and (!empty($celcash->phones)))
        { 
            $cliente->telefone      = $celcash->phones[0];
        } else {
            $cliente->telefone      = "";
        }
        if ((isset($celcash->emails)) and (is_array($celcash->emails)) and (!empty($celcash->emails)))
        { 
            $cliente->email         = $celcash->emails[0];
        } else {
            $cliente->email         = "";
        }

        $cliente->data_nascimento   = $data_nascimento;
        $cliente->sexo              = $sexo;

        if (isset($celcash->Address->zipCode))
        {
            $cliente->cep           = Cas::nulltoSpace($celcash->Address->zipCode);
        } else {
            $cliente->cep           = "";
        }
        if (isset($celcash->Address->street))
        {
            $cliente->logradouro    = Cas::nulltoSpace($celcash->Address->street);
        } else {
            $cliente->logradouro    = "";
        }
        if (isset($celcash->Address->number))
        {
            if (strlen($celcash->Address->number) > 20)
            {
                $cliente->numero    = Cas::nulltoSpace(substr($celcash->Address->number,0,20));
            } else  {
                $cliente->numero    = Cas::nulltoSpace($celcash->Address->number);
            }
        } else {
            $cliente->numero        = "";
        }
        if (isset($celcash->Address->complement))
        {
            $cliente->complemento   = Cas::nulltoSpace($celcash->Address->complement);
        } else {
            $cliente->complemento   = "";
        }
        if (isset($celcash->Address->neighborhood))
        {
            $cliente->bairro        = Cas::nulltoSpace($celcash->Address->neighborhood);
        } else {
            $cliente->bairro        = "";
        }
        if (isset($celcash->Address->city))
        {
            $cliente->cidade        = Cas::nulltoSpace($celcash->Address->city);
        } else {
            $cliente->cidade        = "";
        }
        if (isset($celcash->Address->state))
        {
            $cliente->estado        = Cas::nulltoSpace($celcash->Address->state);
        } else {
            $cliente->estado        = "";
        }

        $cliente->galaxPayId        = $celcash->galaxPayId;
        $cliente->ativo             = true;

        if (isset($celcash->status))
        {
            $cliente->observacao    = $celcash->status;
        } else {
            $cliente->observacao    = "";
        }

        try {
            $cliente->save();
        } catch (QueryException $e) {
           Log::info("celcash", ['cliente' => $celcash ]);
           $retorno->ok 		   = false;
        }	

        $retorno->cliente_id        = $cliente->id;
        $retorno->tipo              = $cliente->tipo;
        return $retorno;
        
    }

    public static function CelCashMigrarVendedor($celcash,$galaxId=1)
    {
        $retorno 				        = new stdClass();
        $retorno->ok 		            = true;
        $retorno->vendedor_id           = 0;

        $nome                           = "";

        if (isset($celcash->ExtraFields))
        {
            foreach ($celcash->ExtraFields as $extrafield)
            {
                switch ($extrafield->tagName) {
                    case 'CP_VENDEDOR':
                        $nome          = $extrafield->tagValue;
                        break;
                }
            }
        }

        if ($nome == "")
        {
            $retorno->ok 		        = false;
            return $retorno;
        }

        $vendedor                       = \App\Models\Vendedor::where('nome','=',$nome)->first();

        if (isset($vendedor->id))     
        {
            $retorno->ok 		        = true;
            $retorno->vendedor_id       = $vendedor->id;
            return $retorno;
        }

        $vendedor            		    = new \App\Models\Vendedor();
        $vendedor->nome                 = $nome;
        $vendedor->ativo                = true;
        $vendedor->user_id              = 1;

        try {
            $vendedor->save();
        } catch (QueryException $e) {
           Log::info("celcash", ['vendedor' => $vendedor]);
           $retorno->ok 		        = false;
           return $retorno;
        }	

        $retorno->ok 		        = true;
        $retorno->vendedor_id       = $vendedor->id;
        return $retorno;
    }

    public static function CelCashMigrarContratos($galaxId)
    {
        if (\Cache::has('cel_cash_cstartAt'))
        {
            $cel_cash_startAt           = \Cache::get('cel_cash_cstartAt');
        } else {
            $cel_cash_startAt           = 3000;
            \Cache::forever('cel_cash_cstartAt',$cel_cash_startAt);
        }

		//$cel_cash_startAt           = 0;

        $query                           = "startAt=" . $cel_cash_startAt . "&limit=100";

        //Log::info("ccelcash", ['cquery' => $query ]);

        $subscriptions                  = CelCash::GetSubscriptions($query,$galaxId);

        if (isset($subscriptions->Subscriptions))
        {
            foreach ($subscriptions->Subscriptions as $subscription)
            {
                $contrato                = CelCash::CelCashMigrarContrato($subscription,$galaxId,'C');
                if ($contrato->ok)
                {
                    $cel_cash_startAt   = $cel_cash_startAt + 1;
                    \Cache::forever('cel_cash_cstartAt', $cel_cash_startAt);
                } else {
                    break;
                }
            }
			if ($subscriptions->totalQtdFoundInPage ==0)
			{
				$cel_cash_startAt       = 0;
				\Cache::forever('cel_cash_cstartAt',$cel_cash_startAt);
			}
            return $subscriptions->totalQtdFoundInPage;
        } 

       // Log::info("ccelcash", ['subscriptions' => $subscriptions ]);

        return 0;

    }

    public static function CelCashMigrarContrato($celcash,$galaxId=1,$tipocontrato='C')
    {
       
	   // Log::info("CelCashMigrarContrato", ['CelCashMigrarContrato' => $celcash ]);

        $retorno 				                    = new stdClass();
        $retorno->ok 		                        = true;
        $retorno->contrato_id                       = 0;
        $retorno->galaxPayId                        = $celcash->galaxPayId;

		if ($celcash->status == "closed")
		{
			$contrato                               = \App\Models\Contrato::where('galaxPayId','=',$celcash->galaxPayId)
																		  ->where('tipocontrato','=',$tipocontrato)
                                                                          ->first();
      
			if (isset($contrato->id))
			{
				$contrato->status				    = $celcash->status;
				$contrato->save();
			}
			Log::info("closed", ['Cliente' => $celcash->Customer ]);
            $retorno->ok 		                    = false;
            return $retorno;
		}
		
        $cliente                                    = CelCash::CelCashMigrarCliente($celcash->Customer,$galaxId);
        
        if (!$cliente->ok)
        {
            Log::info("CelCashMigrarContrato", ['Cliente' => $celcash->Customer ]);
            $retorno->ok 		                    = false;
            return $retorno;
        }

        $plano_id                                   = 6;

        if (isset($celcash->planGalaxPayId))
        {
            $plano                                  = \App\Models\Plano::where('galaxPayId','=',$celcash->planGalaxPayId)
                                                                            ->first();
            if (isset($plano->id))
            {
                $plano_id                          = $plano->id;
            }
        }

        $situacao_id                                = 7;
        $situacao                                   = \App\Models\Situacao::where('status','=',$celcash->status)
                                                                            ->first();
        if (!isset($situacao->id))
        {
            $situacao            		            = new \App\Models\Situacao();
            $situacao->status                       = $celcash->status;
            $situacao->nome                         = $celcash->status;
            $situacao->ativo                        = true;
            $situacao->save();
        }

        $situacao_id                                = $situacao->id;
        $vendedor                                   = CelCash::CelCashMigrarVendedor($celcash,$galaxId=1);
        $contrato_tipo 								= "";
		
        $contrato                                   = \App\Models\Contrato::where('galaxPayId','=',$celcash->galaxPayId)
																		  ->where('tipocontrato','=',$tipocontrato)
                                                                          ->first();
        $novo                                       = false;

        if (!isset($contrato->id))
        {
            $contrato            		            = new \App\Models\Contrato();
            $novo                                   = true;
        } else {
			$contrato_tipo							= $contrato->tipo;
		}

		if ($contrato_tipo != "J")
		{
			$contrato->tipo				                = $cliente->tipo;			
			$contrato->cliente_id		                = $cliente->cliente_id;
			$contrato->plano_id			                = $plano_id;
			if (isset($celcash->firstPayDayDate))
			{
				$contrato->vigencia_inicio		        = $celcash->firstPayDayDate;
			} else {
				$celcash->firstPayDayDate				= date('Y-m-d h:i:s');
				if ($novo)
				{
					$contrato->vigencia_inicio		    = "2024-01-01";
				}
			}
			
			if ($novo)
			{
				$contrato->vigencia_fim		            = "2099-12-31";
			}
			if ($vendedor->vendedor_id ==0)
			{
				$vendedor->vendedor_id                  = 1;
			}
			$contrato->vendedor_id			            = $vendedor->vendedor_id;
			$contrato->valor				            = ($celcash->value / 100);
			$contrato->galaxPayId			            = $celcash->galaxPayId;
			$contrato->status				            = $celcash->status;
		}
        $contrato->paymentLink			            	= $celcash->paymentLink;
        $contrato->mainPaymentMethodId              	= Cas::nulltoSpace($celcash->mainPaymentMethodId);
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
		
        $contrato->situacao_id			            = $situacao_id;

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
        } else {
            if ($novo)
            {
                $contrato->paymentMethodBoletofine           = 0;
                $contrato->paymentMethodBoletointerest       = 0;
                $contrato->paymentMethodBoletoinstructions   = "";
                $contrato->paymentMethodBoletodeadlineDays   = 0;
                $contrato->paymentMethodBoletodocumentNumber = "";
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
        } else {
           if ($novo)
           {
                $contrato->contractname             = "";
                $contrato->contractdocument         = "";
                $contrato->contractip               = "";
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
		
		$contrato->tipocontrato						= $tipocontrato;
		
        try {
			//if (!$novo)
			//{
				if ($contrato->save())
				{
					Log::info("ccelcash", ['contrato_id' => $contrato->id ]);
				} else {
					Log::info("ccelcash", ['celcash' => $celcash ]);
				}
			//} else {
			//	Log::info("ccelcash", ['celcash' => $celcash ]);
			//}
        } catch (QueryException $e) {
           Log::info("ccelcash", ['error' => $e ]);
           Log::info("ccelcash", ['transaction' => $celcash ]);
           Log::info("ccelcash", ['contrato' => $contrato ]);
           $retorno->ok 		        = false;
           return $retorno;
        }	

        $retorno->ok 		            		= true;
        $retorno->contrato_id           		= $contrato->id;
        $retorno->parcelas              		= array();

        if (isset($celcash->Transactions))
        {
            if (is_array($celcash->Transactions))
            {
                foreach ($celcash->Transactions as $transaction)
                {
					//$transaction->event 		= $celcash->event;
                    $retorno->parcelas[]		= CelCash::CelCashMigrarTransaction($transaction,$galaxId,$tipocontrato);
                }
            } else {
				$transaction					= $celcash->Transactions;
				//$transaction->event 			= $celcash->event;
                $retorno->parcelas[]    		= CelCash::CelCashMigrarTransaction($transaction,$galaxId,$tipocontrato);
            }
        }

        return $retorno;

    }

	public static function CelCashMigrarVendas($galaxId)
    {
 
		if (\Cache::has('cel_cash_vstartAt'))
        {
            $cel_cash_startAt           = \Cache::get('cel_cash_vstartAt');
        } else {
            $cel_cash_startAt           = 0;
            \Cache::forever('cel_cash_vstartAt',$cel_cash_startAt);
        }

        $query                           = "startAt=" . $cel_cash_startAt . "&limit=100";

        //Log::info("vcelcash", ['cquery' => $query ]);

        $subscriptions                  = CelCash::GetCharges($query,$galaxId);

		//Log::info("charges", ['charges' => $subscriptions ]);
 
        if (isset($subscriptions->Charges))
        {
            foreach ($subscriptions->Charges as $subscription)
            {
                $contrato               = CelCash::CelCashMigrarContrato($subscription,$galaxId,'V');
                if ($contrato->ok)
                {
                    $cel_cash_startAt   = $cel_cash_startAt + 1;
                    \Cache::forever('cel_cash_vstartAt', $cel_cash_startAt);
                } else {
                    break;
                }
            }
			if ($subscriptions->totalQtdFoundInPage ==0)
			{
				$cel_cash_startAt       = 0;
				\Cache::forever('cel_cash_vstartAt',$cel_cash_startAt);
			}
            return $subscriptions->totalQtdFoundInPage;
        } 

        //Log::info("ccelcash", ['subscriptions' => $subscriptions ]);

        return 0;
	}

    private static function extrairIdentificadorParcela($celcash)
    {
        if ((isset($celcash->chargeMyId)) and (substr_count($celcash->chargeMyId, '#') == 2))
        {
            return $celcash->chargeMyId;
        }

        if ((isset($celcash->myId)) and (substr_count($celcash->myId, '#') == 2))
        {
            return $celcash->myId;
        }

        return "";
    }

    private static function localizarParcelaPorIdentificador($contrato, $identificador)
    {
        if (($identificador == "") or (substr_count($identificador, '#') != 2))
        {
            return null;
        }

        list($contrato_id, $parcela_id, $hash) = explode("#", $identificador);

        if (intval($contrato_id) != intval($contrato->id))
        {
            return null;
        }

        $parcela = \App\Models\Parcela::find($parcela_id);

        if ((!isset($parcela->id)) or (intval($parcela->contrato_id) != intval($contrato->id)))
        {
            return null;
        }

        return $parcela;
    }

    public static function CelCashMigrarTransaction($celcash,$galaxId=1,$tipocontrato='C')
    {
       //Log::info("CelCashMigrarTransaction", ['celcash' => $celcash ]);
	   
        $retorno 				                    = new stdClass();
        $retorno->ok 		                        = true;
        $retorno->galaxPayId                        = $celcash->galaxPayId;
		
		if (isset($celcash->subscriptionGalaxPayId))
		{
			$galaxPayId								= $celcash->subscriptionGalaxPayId;
			$tipocontrato							= 'C';
		} else {
			if (isset($celcash->chargeGalaxPayId))
			{
				$galaxPayId							= $celcash->chargeGalaxPayId;
				$tipocontrato						= 'V';
			} else {
				$galaxPayId							= $celcash->galaxPayId;
				$tipocontrato						= 'C';
			}
		}

        $contrato                                   = \App\Models\Contrato::where('galaxPayId','=',$galaxPayId)
		                                                                  ->where('tipocontrato','=',$tipocontrato)
                                                                          ->first();

        if (!isset($contrato->id))
        {
			Log::info("ncontrato", ['celcash' => $celcash ]);
            $retorno->ok 		                    = false;
            return $retorno;
        }

        $identificadorParcela                      = self::extrairIdentificadorParcela($celcash);
        $parcela                                   = self::localizarParcelaPorIdentificador($contrato, $identificadorParcela);

        if (!isset($parcela->id))
        {
            $parcela                               = \App\Models\Parcela::where('contrato_id','=',$contrato->id)
																		->where('galaxPayId','=',intval($celcash->galaxPayId))
                                                                        ->first();
        }

        $novo                                       = false;
        
		if (!isset($parcela->id))
        {
			 $parcela                               = \App\Models\Parcela::where('contrato_id','=',$contrato->id)
																		 ->where('nparcela','=',intval($celcash->installment))
																		 ->first();
		}

		if (!isset($parcela->id))
        {
            $parcela            		            = new \App\Models\Parcela();
			$parcela->desconto						= 0;
			$parcela->juros							= 0;
            $novo                                   = true;
        }

        $parcela->contrato_id			            = $contrato->id;
        
        $parcela->data_vencimento		            = $celcash->payday;

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
		if ($novo)
		{
			$parcela->valor					        = $contrato->valor;
		}
		$parcela->desconto							= 0;
		$parcela->juros								= 0;
		$valorBaseParcela							= $novo ? $contrato->valor : $parcela->valor;
		if ($celcash->installment !=1)
		{
			if ($valorBaseParcela > $value) 
			{
				$parcela->desconto				    = $valorBaseParcela - $value;
			}
			if ($valorBaseParcela < $value) 
			{
				$parcela->juros					    = $value - $valorBaseParcela;
			}
		}
        $parcela->valor_pago			            = $value;
		
        if ($novo)
        {
            $parcela->nparcela				        = $celcash->installment;
        }
        if ($identificadorParcela != "")
        {
            $parcela->chargeMyId                    = $identificadorParcela;
        }
        $parcela->galaxPayId			            = $celcash->galaxPayId;
        $parcela->status				            = Cas::nulltoSpace($celcash->status);
        $parcela->statusDescription		            = Cas::nulltoSpace($celcash->statusDescription);
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
            $situacao                               = \App\Models\Situacao::where('status','=',$parcela->status)->first();
            if (!isset($situacao->id))
            {
                $situacao            		        = new \App\Models\Situacao();
                $situacao->status                   = $parcela->status;
                $situacao->nome                     = $parcela->statusDescription;
                $situacao->ativo                    = true;
                $situacao->save();
            } else {
                if (($situacao->nome != $parcela->statusDescription) and ($parcela->statusDescription !=""))
                {
                    $situacao->nome                = $parcela->statusDescription;
                    $situacao->save();
                }
            }
        } 
        if (isset($celcash->additionalInfo))
        {
            $parcela->additionalInfo		        = Cas::nulltoSpace($celcash->additionalInfo);
        } else {
            $parcela->additionalInfo		        = "";
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
        } else {
            if ($novo)
            {
                $parcela->boletopdf				    = "";
                $parcela->boletobankLine		    = "";
                $parcela->boletobankNumber		    = 0;
                $parcela->boletobarCode			    = "";
                $parcela->boletobankEmissor		    = "";
                $parcela->boletobankAgency		    = "";
                $parcela->boletobankAccount		    = "";
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
        } else {
            if ($novo)
            {
                $parcela->pixreference			     = "";
                $parcela->pixqrCode				     = "";
                $parcela->piximage				     = "";
                $parcela->pixpage				     = "";
            }
        }

        try {
			//if (!$novo)
			//{
				if ($parcela->save())
				{
					Log::info("ccelcash", ['parcela_id' => $parcela->id ]);
				} else {
					Log::info("ccelcash", ['parcela' => $celcash ]);
				}
			//} else {
			//	Log::info("ccelcash", ['parcela' => $celcash ]);
			//}
        } catch (QueryException $e) {
           Log::info("ccelcash", ['error' => $e ]);
           Log::info("ccelcash", ['transaction' => $celcash ]);
           Log::info("ccelcash", ['parcela' => $parcela ]);
           $retorno->ok 		        = false;
           return $retorno;
        }	

        $retorno->ok 		                = true;
        $retorno->parcela_id                = $parcela->id;
        $retorno->contrato_id               = $parcela->contrato_id;
        return $retorno;
    }
}
