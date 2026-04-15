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

class CelCashWebhookService
{

    public static function celcashWebhook($celcash)
    {
		
	   if ((isset($celcash->origem)) and ($celcash->origem == 'CON'))
	   {
		    return $celcash;
	   }
       /*
       transaction.updateStatus:	
       Será enviado quando uma transação tiver seu status de pagamento alterado. 
       Vai conter todos os dados da entidade Transaction.

       subscription.addTransaction	
       Será enviado quando uma transação for adicionada em uma assinatura. 
       Vai conter todos os dados da entidade Transaction.

       company.cashOut	
       Será enviado quando um pagamento Pix ou um Saque/Transferência for realizado no sistema.

       company.verifyDocuments	
       Informações de atualização do status de aprovação de conta

       chargeback.update	
       Será enviado quando uma abertura de disputa for criada no sistema. 
       Vai conter todos os dados da entidade Chargeback, além de conter alguns dados da entidade Transaction e PaymentBill.
       */

       //"confirmHash": "bc2abccdfb2fc8824520195266241576",
       $galaxId                                 = 1;
    
       if (isset($celcash->event))
       {
            switch ($celcash->event) 
            {
                case 'contract.accepted':
					 if ((isset($celcash->Subscription)) or (isset($celcash->Charge)) or (isset($celcash->Transaction)))
					 {
						$retorno 				    = new stdClass();
                        if (isset($celcash->Subscription))
                        {
                            $payload			    = json_decode(json_encode($celcash->Subscription));
							$payload->event 		= $celcash->event;
                            $retorno->contrato      = CelCash::CelCashMigrarContrato($payload,$galaxId);
                        } 
						return $retorno;
					 }
                     break;
                case 'transaction.status':
                    return 'transaction.status';
                case 'transaction.updateStatus': 
                    if ((isset($celcash->Subscription)) or (isset($celcash->Charge)) or (isset($celcash->Transaction)))
                    {
                        $retorno 				    = new stdClass();
                        if (isset($celcash->Subscription))
                        {
                            $payload			    = json_decode(json_encode($celcash->Subscription));
							$payload->event 		= $celcash->event;
                            $retorno->contrato      = CelCash::CelCashMigrarContrato($payload,$galaxId);
							//return $retorno;
                        }
						if (isset($celcash->Charge))
                        {
                            $payload			    = json_decode(json_encode($celcash->Charge));
							Log::info("transaction.updateStatus-Charge", ['charge' => $payload ]);
                            $retorno->contrato      = CelCash::updateParcelaWithChargeTransaction($payload,$celcash);
							return $retorno;
                        }
                        if (isset($celcash->Transaction))
                        {
                            $payload			    = json_decode(json_encode($celcash->Transaction));
							$payload->event 		= $celcash->event;
                            $retorno->transaction   = CelCash::CelCashMigrarTransaction($payload,$galaxId,'C');
                        }
                        return $retorno;
                    } else {
                        Log::info("transaction.updateStatus", ['celcash' => $celcash ]);
                    }
                case 'subscription.addTransaction':
					/*
					 if ((isset($celcash->Subscription)) or (isset($celcash->Charge)) or (isset($celcash->Transaction)))
					 {
						$retorno 				    = new stdClass();
                        if (isset($celcash->Subscription))
                        {
                            $payload			    = json_decode(json_encode($celcash->Subscription));
                            $retorno->contrato      = CelCash::CelCashMigrarContrato($payload,$galaxId);
                        } 
						return $retorno;
					 }
					 */
                    return 'subscription.addTransaction';
            }
        }

        return $celcash;
    }
}
