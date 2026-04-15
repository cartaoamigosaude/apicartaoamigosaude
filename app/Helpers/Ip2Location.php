<?php

namespace App\Helpers;
use Illuminate\Support\Facades\Http;
use stdClass;
 
class Ip2Location
{
	
	public static function IpGeolocation ($ip)
    {

        //$ip									= preg_replace('/\D/', '', $ip);

        try {
            $hresponse                  = Http::withHeaders([
                                                    'Content-Type'  => 'application/json'
                                                ])->get("http://ip-api.com/json/$ip");
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
        $retorno->error 			    = false;
        return $retorno;
	}	
	
}