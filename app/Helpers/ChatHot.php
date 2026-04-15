<?php

namespace App\Helpers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use DB;

class ChatHot
{
	public static function nulltoSpace($value)
	{
		if ((!isset($value)) || ($value == null))
		{
			return "";
		}
			
		if (is_null($value) || ($value==null) || ($value=='null') || ($value=='undefined'))
		{
			return "";
		}
		
		if (strpos($value, 'null'))
		{
			return "";
		}
		
		return $value;
	}

	public static function formatarTelefoneChat($value) 
	{
		if (ChatHot::nulltoSpace($value) == "")
		{
			return "";
		}

		$value 			= preg_replace('/\D/', '', $value);
	
		if (in_array($value, ['00000000', '0000000000', '99999999999'])) 
		{
			return "";
		}
		$length 			= strlen($value);

		if ($length < 10) {
			//NUMERO INVALIDO 
			return "";
		}
	
		$telefone 			 = '55';
		$telefone 			.= substr($value,0, 2);

		if ($length == 10)
		{
			$telefone 		.= '9' . substr($value,2, 4);
			$telefone 		.= substr($value,6,4);
		} else
		{
			$telefone 		.= substr($value,2, 5);
			$telefone 		.= substr($value,7,4);
		}

		return $telefone;
	}

	public static function obterFilaToken($token)
    {
		$fila         						= \Cache::remember("token#" . $token, now()->addSeconds(30), function () use ($token)
		{
			$endpoint                       = config('services.chathot.api_url') . '/api/whatsapp-status/';
			$retorno 						= new stdClass();

			try {
				$hresponse                  = Http::withHeaders([
												'Authorization' => "Bearer $token",
												'Content-Type'  => 'application/json',
											])->post($endpoint, []);
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

			$retorno						= new stdClass();
			$retorno->ok					= "N";
			$status							= "";
			$fila							= "";

			if ((!isset($response->whatsapps)) or ($statcode != 200))
			{
				$retorno->status 			= "Número Desconectado";
				$retorno->mensagem 			= "Número Desconectado dentro do ChatHot, Gentileza conectar um novo Número e tentar novamente.";
				return $retorno;
			}

			foreach($response->whatsapps as $whatsapp)
			{
				if ($token == $whatsapp->token)
				{
					$status				= $whatsapp->status;

					if ((isset($whatsapp->queues[0])) and (isset($whatsapp->queues[0]->id)))
					{
						$fila			= $whatsapp->queues[0]->id;
					}
					break;
				}
			}

			if ($status != "CONNECTED" or $fila == "")
			{
				$retorno->status 			= "Número Desconectado";
				$retorno->mensagem 			= "Número Desconectado dentro do ChatHot, Gentileza conectar um novo Número e tentar novamente.";
				return $retorno;
			}
			
			$retorno->fila			    	= $statcode;
			$retorno->ok                   	= "S";

			return $retorno;
		});

		return $fila;
	}

	public static function enviarMensagemChatHot($numero, $mensagem, $token='5519998557120')
    {
		$retorno						= new stdClass();
		$retorno->ok                   	= "N";
		$retorno->statcode				= 404;
		$retorno->response              = "";
		return $retorno;
		
		$numero 						= preg_replace('/\D/', '', $numero);
		
		$fila							= ChatHot::obterFilaToken($token);

		//Log::info("fila", ['fila' => $fila ]);

		if ((!isset($fila->ok)) or ($fila->ok == "N"))
		{
			return $fila;
		}

		$mensagem 						= ChatHot::formatHtmlMessageForWhatsApp($mensagem);
		
		//Log::info("mensagem", ['mensagem' => $mensagem ]);

		//$endpoint                       = "https://api.chathot.com.br/api/messages/send";
		$endpoint                       = config('services.chathot.api_url') . '/api/messages/whatsmeow/sendTextPRO';
		$retorno 						= new stdClass();

		try {
			$hresponse                  = Http::withHeaders([
											'Authorization' => "Bearer $token",
											'Content-Type'  => 'application/json',
										])->post($endpoint, [
											'number' 		=> $numero,
											'openTicket' 	=> 1,
											'queueId' 		=> $fila->fila,
											'body' 			=> $mensagem
										]);
			$statcode					= $hresponse->status();
			$response 					= $hresponse->object();
			//Log::info("chathot", ['response' => $response ]);
			//Log::info("chathot", ['statcode' => $statcode ]);
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

		$retorno						= new stdClass();
		$retorno->ok					= "N";
		$status							= "";
		$fila							= "";

		if (($statcode != 200))
		{
			if ((isset($response->mensagem)) and ($response->mensagem == "Mensagem de TEXTO via WHATSMEOW PRO enviada SEM TICKET"))
			{
				$retorno->statcode		= '200';
				$retorno->ok            = "S";
				$retorno->response      = $response;
				return $retorno;
			}
		
			//Log::info("chathot", ['statcode' => $statcode ]);
			$retorno->statcode			= $statcode;
			$retorno->mensagem 			= "Erro não identificado";

			if (isset($response->error))
			{
				$retorno->mensagem 		= $response->error;
			}
			return $retorno;
		}
		
		$retorno->ok                   	= "S";
		$retorno->statcode				= $statcode;
		$retorno->response              = $response;
		return $retorno;
	}
	
	public static function enviarArquivoChatHot($numero, $arquivo, $token='5519998557120')
    {
		$retorno						= new stdClass();
		$retorno->ok                   	= "N";
		$retorno->statcode				= 404;
		$retorno->response              = "";
		return $retorno;
		
		$numero 						= preg_replace('/\D/', '', $numero);
		
		$fila							= ChatHot::obterFilaToken($token);

		if ((!isset($fila->ok)) or ($fila->ok == "N"))
		{
			return $fila;
		}

		//$endpoint                       = "https://api.chathot.com.br/api/messages/sendURLDocument";
		$endpoint                       = config('services.chathot.api_url') . '/api/messages/whatsmeow/sendUrlFilesWhatsmeowPRO';
		
		$retorno 						= new stdClass();

		try {
			$hresponse                  = Http::withHeaders([
											'Authorization' => "Bearer $token",
											'Content-Type'  => 'application/json',
										])->post($endpoint, [
											'number' 		=> $numero,
											'type'			=> 'document',
											'queueId' 		=> $fila->fila,
											'openTicket' 	=> 1,
											'caption' 		=> '',
											'fileName'		=> 'arquivo.pdf',
											'body' 			=> $arquivo
										]);
			$statcode					= $hresponse->status();
			$response 					= $hresponse->object();
			//Log::info("chathota", ['response' => $response ]);
			//Log::info("chathota", ['statcode' => $statcode ]);
			//Log::info("chathota", ['arquivo' => $arquivo ]);
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

		$retorno						= new stdClass();
		$retorno->ok					= "N";
		$status							= "";
		$fila							= "";

		if (($statcode != 200))
		{
			$retorno->mensagem 			= "Erro não identificado";

			if (isset($response->error))
			{
				$retorno->mensagem 		= $response->error;
			}
			return $retorno;
		}
		
		$retorno->ok                   	= "S";
		$retorno->response              = $response;
		return $retorno;
	}

	public static function formatHtmlMessageForWhatsApp($htmlMessage) {
		// Decodificar entidades HTML para evitar caracteres especiais
		//$message = html_entity_decode($htmlMessage);
		$message = $htmlMessage;

		// Aplicar negrito no WhatsApp para textos dentro de <strong>
		$message = preg_replace_callback('/<strong>(.*?)<\/strong>/', function($matches) {
			return "*" . $matches[1] . "*";
		}, $message);

		// Remover todas as outras tags HTML
		//$message = strip_tags($message);

		// Converter múltiplas quebras de linha em uma única quebra de linha
		$message = preg_replace('/\n+/', "\n", $message);

		// Substituir quebras de linha HTML por quebras de linha normais
		$message = str_replace(["<p>", "</p>"], ["", "\n"], $message);

		// Remove espaços extras e quebras de linha no início e fim da mensagem
		$message = trim($message);

		return $message;
	}


	public static function converterParaWhatsApp($html) {
		// Negrito: <strong> ou <b> para *
		$html = preg_replace('/<strong>(.*?)<\/strong>|<b>(.*?)<\/b>/', '*$1$2*', $html);
	
		// Itálico: <em> ou <i> para _
		$html = preg_replace('/<em>(.*?)<\/em>|<i>(.*?)<\/i>/', '_$1$2_', $html);
	
		// Monoespaçado: <code> para `
		$html = preg_replace('/<code>(.*?)<\/code>/', '`$1`', $html);
	
		// Links: <a href="url">texto</a> para texto (url)
		$html = preg_replace('/<a href="(.*?)">(.*?)<\/a>/', '$2 ($1)', $html);
	
		// Substituir <p> vazias por quebras de linha
		$html = preg_replace('/<p><\/p>/', "\n", $html);
		$html  = str_replace("{{brekline}}", "\n",  $html);

		// Remover quaisquer outras tags HTML
		$html = strip_tags($html);
	
		return $html;
	}
}