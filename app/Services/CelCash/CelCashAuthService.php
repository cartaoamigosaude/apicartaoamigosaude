<?php

namespace App\Services\CelCash;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use DB;
use stdClass;

class CelCashAuthService
{

	public static function AuthorizationBasic($id)
	{
        $authorization      = "";
        $celcash 	        = DB::connection('mysql')
                                ->table('celcash')
                                ->select('galaxId','galaxHash')
                                ->where('id','=',$id)
                                ->first();

        if  (!isset($celcash->id))
        {
            $authorization  = base64_encode( $celcash->galaxId . ':' . $celcash->galaxHash);
        }

        return  $authorization;

	}

    public static function AuthorizationPadrao()
	{
        $galaxId            = 0;
        $celcash 	        = DB::connection('mysql')
                                ->table('celcash')
                                ->select('galaxId','galaxHash')
                                ->where('padrao','=',1)
                                ->first();

        if  (!isset($celcash->id))
        {
            $galaxId        = $celcash->galaxId;
        }

        return   $galaxId;

	}

	public static function Token($id=1)
    {
        $authorization                          = self::AuthorizationBasic($id);

        if ($authorization =="")
        {
            $retorno 						    = new stdClass();
            $retorno->error 		            = true;
            $retorno->message		            = "Nao foi encontrato autorizaçao para o Cel Cash ID: $id";
            return $retorno;
        }
  
        $cache 							    = \Cache::get($authorization);
			
		if (isset($cache->expires_in))
		{
        	if (date("Y-m-d H:i:s") >= $cache->expires_in)
			{
				\Cache::forget($authorization);
			} else {
                return $cache;
            }
        } else {
            \Cache::forget($authorization);
        }

        $url                                    = config('services.celcash.api_url');
        $token         						    = \Cache::rememberForever($authorization, function () use ($url,$authorization)
        {
                $endpoint                       = $url . "/token";
                $retorno 						= new stdClass();

                try {
                    $hresponse                  = Http::withHeaders([
                                                    'Authorization' => "Basic $authorization",
                                                    'Content-Type'  => 'application/json'
                                                ])->post($endpoint, [
                                                    'grant_type'    => 'authorization_code',
                                                    'scope'         => 'customers.read customers.write plans.read plans.write transactions.read transactions.write webhooks.write balance.read balance.write cards.read cards.write card-brands.read subscriptions.read subscriptions.write charges.read charges.write boletos.read carnes.read',
                                                ]);
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
                
                switch ($statcode)
                {
                    case 200:
                        $retorno->token			= $response->access_token;
                        $agora					= date("Y-m-d H:i:s");
                        $seconds                = $response->expires_in -1;
                        $expires_in				= Carbon::parse($agora)->addSeconds($seconds);		
                        $retorno->expires_in    = $expires_in->format('Y-m-d H:i:s');					
                        break;
                    case 400:
                        $retorno->error 		= true;
                        $retorno->message		= "Erro não identificado";
                        $retorno->response 		= $response;
                        break;
                    case 403:
                        $retorno->error 		= true;
                        $retorno->message		= "Usuário sem permissão. Verifique se o IP do servidor de API foi liberado pelo Banco.";
                        $retorno->response      = $response;
                        break;
                    default:
                        $retorno->error 		= true;
                        $retorno->message		= "Erro não identificado";
                        $retorno->response      = $response;
                        break;
                }
                $retorno->statcode			    = $statcode;
                $retorno->url                   = $url;
			    return $retorno;
            
        });		

        return $token;
	}
	
	/**
	 * TokenAgendamento é idêntico a Token() mas com $id=2 como padrão.
	 * Mantido para compatibilidade — delega para Token().
	 */
	public static function TokenAgendamento($id=2)
    {
        return self::Token($id);
	}
}
