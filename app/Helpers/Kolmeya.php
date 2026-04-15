<?php

namespace App\Helpers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use stdClass;

class Kolmeya
{
	public static function getToken()
    {
		$retorno 						= new stdClass();
		$retorno->token                 = '40xXKfJ8f258dOmhzOoWI8va7AfaW231ksiufahH';
		$retorno->tenant_segment_id		= 4467;
		return $retorno;
		
	}
	
	public static function tratarTelefone($telefone)
    {
		$telefone 						= preg_replace("/\D/", '', $telefone);
		$telefone						= str_replace(array('+55','+','-','_','.','(',')',' '),"",$telefone);
		
		if (substr($telefone,0,2) == '55')
		{
			$telefone					= substr($telefone,2);
		}
		
		$ddd                    		= substr($telefone,0,2);
		$tamanho 						= strlen($telefone);
		
		/* 31990809951 */
		if (($ddd > 28) and ($tamanho < 11))
		{
			$telefone					= $ddd . "9" . substr($telefone,2);
		}
		
		return $telefone;
	}
	
	public static function sendSMS($lote,$messages)
    {
		$token							= Kolmeya::getToken();
		$payload 						= new stdClass();
		$payload->tenant_segment_id		= $token->tenant_segment_id;
		$payload->reference				= $lote;
		$payload->messages				= $messages;
										  
		try {
			$hresponse 					= Http::timeout(2)->withToken($token->token)->post(config('services.kolmeya.api_url') . '/api/v1/sms/store', $payload);
			$statcode					= $hresponse->status();
			$response 					= $hresponse->object();
		} catch (\Illuminate\Http\Client\ConnectionException $e) {
			$retorno 					= new stdClass();
			$retorno->error 			= true;
			$retorno->statcode			= 500;
			$retorno->mensagem 			= $e;
			$retorno->payload			= $payload;
			return $retorno;
		}
		
		$retorno 						= new stdClass();
		$retorno->error 				= false;
		$retorno->statcode				= $statcode;
		$retorno->response				= $response;
		$retorno->payload				= $payload;
		return $retorno;								  
	}
	
}

