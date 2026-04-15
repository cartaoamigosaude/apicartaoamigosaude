<?php

namespace App\Helpers;
use Illuminate\Support\Facades\Http;
use stdClass;
 
class BrasilApi
{
	
	public static function Municipios ($uf)
    {

        try {
            $hresponse                  = Http::withHeaders([
                                                    'Content-Type'  => 'application/json'
                                                ])->get("https://brasilapi.com.br/api/ibge/municipios/v1/$uf?providers=dados-abertos-br,gov,wikipedia");
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

        $retorno              			= $response;
       // $retorno->error 			    = false;
        return $retorno;
	}	
	
}