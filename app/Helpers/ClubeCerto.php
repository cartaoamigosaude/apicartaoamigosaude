<?php

namespace App\Helpers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Helpers\Cas;
use Carbon\Carbon;
use DB;
use stdClass;
 
class ClubeCerto
{
	
	public static function Token()
    {
		
		$authorization							= 'token_clube_certo';
		
		$cache 								    = \Cache::get($authorization);
			
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

        $url                                    = config('services.clubecerto.api_url') . '/superapp/companyAPI';
        $token         						    = \Cache::rememberForever($authorization, function () use ($url,$authorization)
        {
                $endpoint                       = $url . "/login";
                $retorno 						= new stdClass();

                try {
                    $hresponse                  = Http::withHeaders([
                                                    'Content-Type'  => 'application/json'
                                                ])->post($endpoint, [
                                                    'cnpj'    => '52416202000112',
                                                    'password'=> 'cartaoamigo@2829',
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
                        $retorno->token			= $response->token;
                        $agora					= date("Y-m-d H:i:s");
                        $seconds                = 10;
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
	
	public static function ativarDesativarBeneficiario($beneficiario_id,$produto_id,$ativar=true)
	{
		if ($ativar)
		{
			$associate 					= ClubeCerto::associateBeneficiario($beneficiario_id,$produto_id);
		} else {
            
            $beneficiario               = \App\Models\Beneficiario::find($beneficiario_id);

            if (isset($beneficiario->id))
            {
                $associate				= ClubeCerto::inactivate($beneficiario->cliente->cpfcnpj);
            }
		}
		
		if ($associate->ok == 'S')
		{
			$associate					= Cas::ativarDesativarProduto($beneficiario_id,$produto_id,$ativar,$associate->id);
		}
		return $associate;
	}
	
	public static function associateBeneficiario($id,$produto_id=2)
    {
		$retorno 						= new stdClass();
        $retorno->ok                    = "N";

		$beneficiario                   = \App\Models\Beneficiario::find($id);

        if (!isset($beneficiario->id))
        {
            $retorno->mensagem 			= 'Beneficiário não encontrado';
			return $retorno;
        }
		
		$permite						= Cas::permiteProdutoBeneficio($id,$produto_id);
		if ($permite->ok=='N')
		{
			$retorno->mensagem 			= $permite->mensagem; "Produto $produto_id não é permitido para o Beneficiario";
			return $retorno;
		}
		
		$payload 						= new stdClass();
		$payload->name					= $beneficiario->cliente->nome;
		$payload->cpf 					= $beneficiario->cliente->cpfcnpj;
		$payload->discount				= true;
		$associate						= ClubeCerto::associate($payload);
		
		if ($associate->ok == 'S')
		{
			return $associate;
		}
		
		$retorno->mensagem 				= $associate->mensagem;
		return $retorno;
		
	}
	
	public static function associate($payload)
    {
		$retorno 						 = new stdClass();
        $retorno->ok                     = "N";

        $token                           = ClubeCerto::Token();

        if (!isset($token->token))
        {
			Log::info("clubecerto", ['token-erro'	=> $token]);
            $retorno->mensagem           = "Erro ao buscar token #$token";
            return $retorno;
        }

        $endpoint                       = $token->url . "/associate";


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

		Log::info("clubecerto", ['associate'	=> $response]);

        $retorno              			= $response;
		
		if ($statcode == 200) 
        {
            $retorno->ok                = "S";
        } else {
		    $retorno->ok                = "N";
			$retorno->mensagem 			= "Ocorreu um erro não identificado";
		}

        return $retorno;
	}	
	
    public static function inactivate($cpf)
    {
		$retorno 						 = new stdClass();
        $retorno->ok                     = "N";

        $token                           = ClubeCerto::Token();

        if (!isset($token->token))
        {
			Log::info("clubecerto", ['token-erro'	=> $token]);
            $retorno->mensagem           = "Erro ao buscar token #$token";
            return $retorno;
        }

        $endpoint                       = $token->url . "/associate/" . $cpf;


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

		Log::info("clubecerto", ['inactivate'	=> $response]);

        $retorno              			= $response;
		
		if ($statcode == 200) 
        {
            $retorno->ok                = "S";
        } else {
		    $retorno->ok                = "N";
			$retorno->mensagem 			= "Ocorreu um erro não identificado";
		}

        return $retorno;
	}	
}