<?php

namespace App\Services\Cas;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use App\Helpers\CelCash;
use App\Helpers\ChatHot;
use App\Helpers\Cas;
use App\Helpers\Epharma;
use App\Jobs\ChatHotJob;
use App\Jobs\CelCashParcelasAvulsaJob;
use Carbon\Carbon;
use PDF;
use DB;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MensagemService
{

	public static function chatHotJob($payload)
	{
		ChatHotJob::dispatch($payload)->onQueue('chat');
	}

	public static function chatHotMensagem($payload)
	{
		
		if (substr($payload->numero, 0, 2) !== '55') 
		{
			$payload->numero						= '55' . $payload->numero;
		}
		
		$envio 										= ChatHot::enviarMensagemChatHot($payload->numero, $payload->mensagem, $payload->token);
		
		if ($envio->ok =='S') 
		{
			if (isset($payload->beneficiario_id))
			{
				$whatsapp 							= new \App\Models\WhatsappMensagem();
				$whatsapp->beneficiario_id 			= $payload->beneficiario_id;
				$whatsapp->mensagem 				= $payload->mensagem;
				$whatsapp->token 					= $payload->token;
				$whatsapp->enviado_por 				= $payload->enviado_por;
				$whatsapp->arquivo					= $payload->arquivo ?? "";
				$whatsapp->statcode					= $envio->statcode;
				$whatsapp->save();
			}
			if ($payload->arquivo !="")
			{
				$envio 								= ChatHot::enviarArquivoChatHot($payload->numero, $payload->arquivo, $payload->token);
			}
		} else {
			$whatsapp 								= new \App\Models\WhatsappMensagem();
			$whatsapp->beneficiario_id 				= $payload->beneficiario_id;
			$whatsapp->mensagem 					= $payload->mensagem;
			$whatsapp->token 						= $payload->token;
			$whatsapp->arquivo						= $payload->arquivo ?? "";
			$whatsapp->enviado_por 					= $payload->enviado_por;
			if (isset($envio->statcode))
			{
				$whatsapp->statcode					= $envio->statcode;
			} else {
				$whatsapp->statcode					= 400;
			}
			$whatsapp->save();
		}
		
		return $envio;
	}

	public static function exportCsv($payload, $beneficiarios)
	{
		$data 						= [];

		// Determina a ação com base no payload
		$acao 						= ($payload->ativarbloquear == 'A') ? 'I' : 'A';

		$colunas 								= [];
		$colunas[]                  			= "Tipo de Registro (FIXO)";	
		$colunas[]                      		= "Acao";	
		$colunas[]                      		= "Codigo do Beneficio";	
		$colunas[]                      		= "Carteira Beneficiario Titular";	
		$colunas[]                      		= "Carteira Beneficiario ou dependente";	
		$colunas[]                      		= "Nome Completo";	
		$colunas[]                      		= "Nome Cartao"	;
		$colunas[]                      		= "Data de Nascimento";	
		$colunas[]                      		= "Sexo";	
		$colunas[]                      		= "Numero do Documento"	;
		$colunas[]                      		= "Orgao Emissor";	
		$colunas[]                      		= "Numero CPF";
		$colunas[]                      		= "Endereco";	
		$colunas[]                      		= "Numero";	
		$colunas[]                      		= "Complemento"	;
		$colunas[]                      		= "Bairro";
		$colunas[]                      		= "Cidade";	
		$colunas[]                      		= "Estado";	
		$colunas[]                      		= "CEP"	;
		$colunas[]                      		= "Inicio de Vigencia";	
		$colunas[]                      		= "Termino de Vigencia";
		$colunas[]                      		= "Codigo Processamento";	
		$colunas[]                      		= "Matricula";	
		$colunas[]                      		= "Filler";	
		$colunas[]                      		= "Numero de Registro";
		$data[]                     			= $colunas;
		$registro 								= 0;

		foreach ($beneficiarios as $beneficiario) {
			
			$registro ++;

			$colunas 				= [];
			$colunas[] 				= '01'; // Código fixo
			$colunas[] 				= $acao; // Ação
			$colunas[] 				= '244926'; // Código fixo

			$cpf_dependente = $beneficiario->cpfcnpj;
			$cpf_titular = "";

			// Determina o CPF do titular
			if ($beneficiario->tipo == 'T') 
			{
				$cpf_titular 		= $beneficiario->cpfcnpj;
			} else {
				if ($beneficiario->tipo_contrato == 'F') {
					$titular = DB::connection('mysql')
						->table('beneficiarios')
						->select('clientes.cpfcnpj')
						->join('clientes', 'beneficiarios.cliente_id', '=', 'clientes.id')
						->where('beneficiarios.contrato_id', '=', $beneficiario->contrato_id)
						->where('beneficiarios.tipo', '=', 'T')
						->first();

					if (isset($titular->cpfcnpj)) {
						$cpf_titular = $titular->cpfcnpj;
					}
				} else {
					$titular = DB::connection('mysql')
						->table('beneficiarios')
						->select('clientes.cpfcnpj')
						->join('clientes', 'beneficiarios.cliente_id', '=', 'clientes.id')
						->where('beneficiarios.contrato_id', '=', $beneficiario->contrato_id)
						->where('beneficiarios.id', '=', $beneficiario->parent_id)
						->first();

					if (isset($titular->cpfcnpj)) {
						$cpf_titular = $titular->cpfcnpj;
					}
				}
			}

			$cpf_titular		= preg_replace("/\D/", '', $cpf_titular);
			$cpf_dependente		= preg_replace("/\D/", '', $cpf_dependente);
			
			$cpf_titular		= str_pad($cpf_titular, 11, "0", STR_PAD_LEFT);
			$cpf_dependente		= str_pad($cpf_dependente, 11, "0", STR_PAD_LEFT);

			$colunas[] 			= $cpf_titular;
			$colunas[] 			= $cpf_dependente;
			$colunas[] 			= trim(Cas::removerAcentosEMaiusculo($beneficiario->cliente));
			$colunas[] 			= trim(Cas::removerAcentosEMaiusculo($beneficiario->cliente));

			// Formata a data de nascimento
			list($ano, $mes, $dia) = explode("-", $beneficiario->data_nascimento);
			$colunas[] 			= "$dia/$mes/$ano";

			$colunas[] 			= $beneficiario->sexo;
			$colunas 			= array_merge($colunas, array_fill(0, 2, "")); // Campos adicionais vazios
			$colunas[] 			= $cpf_dependente;
			$colunas 			= array_merge($colunas, array_fill(0, 7, "")); // Campos adicionais vazios

			if ($acao == 'I') {
				$colunas[] 		= date('d/m/Y'); // Data de inclusão
				$colunas[] 		= ""; // Data de alteração vazia
			} else {
				$colunas[] 		= ""; // Data de inclusão vazia
				$colunas[] 		= date('d/m/Y'); // Data de alteração
			}

			$colunas[] 			= "2"; // Código fixo
			$colunas[] 			= $cpf_dependente;
			$colunas[] 			= ""; // Campo vazio
			$colunas[] 			= str_pad($registro, 6, '0', STR_PAD_LEFT); // Código fixo

			$data[] 			= $colunas; // Adiciona a linha ao array de dados
			
			if ($beneficiario->tipo == 'T') 
			{
				foreach ($beneficiario->expansoes as $dependente) 
				{
					$registro ++;

					$colunas 			= [];
					$colunas[] 			= '01'; // Código fixo
					$colunas[] 			= $acao; // Ação
					$colunas[] 			= '244926'; // Código fixo
					
					$cpf_dependente		= preg_replace("/\D/", '', $dependente->cpfcnpj);
					$cpf_dependente		= str_pad($cpf_dependente, 11, "0", STR_PAD_LEFT);

					$colunas[] 			= $cpf_titular;
					$colunas[] 			= $cpf_dependente;
					$colunas[] 			= trim(Cas::removerAcentosEMaiusculo($dependente->cliente));
					$colunas[] 			= trim(Cas::removerAcentosEMaiusculo($dependente->cliente));

					// Formata a data de nascimento
					list($ano, $mes, $dia) = explode("-", $dependente->data_nascimento);
					$colunas[] 			= "$dia/$mes/$ano";

					$colunas[] 			= $dependente->sexo;
					$colunas 			= array_merge($colunas, array_fill(0, 2, "")); // Campos adicionais vazios
					$colunas[] 			= $cpf_dependente;
					$colunas 			= array_merge($colunas, array_fill(0, 7, "")); // Campos adicionais vazios

					if ($acao == 'I') {
						$colunas[] 		= date('d/m/Y'); // Data de inclusão
						$colunas[] 		= ""; // Data de alteração vazia
					} else {
						$colunas[] 		= ""; // Data de inclusão vazia
						$colunas[] 		= date('d/m/Y'); // Data de alteração
					}

					$colunas[] 			= "2"; // Código fixo
					$colunas[] 			= $cpf_dependente;
					$colunas[] 			= ""; // Campo vazio
					$colunas[] 			= str_pad($registro, 6, '0', STR_PAD_LEFT); // Código fixo
					$data[] 			= $colunas; // Adiciona a linha ao array de dados
				}
			}
		}

		$colunas 								= [];
		$colunas[]                  			= "99";	
		$colunas[]                      		= $registro;
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "";
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "";
		$colunas[]                      		= "";	
		$colunas[]                      		= "";
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "";
		$colunas[]                      		= "";
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "";
		$colunas[]                      		= "";	
		$colunas[]                      		= "";
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "";	
		$colunas[]                      		= "000001";
		$data[]                     			= $colunas;
		
		// Configura o nome do arquivo
		$filename = 'dados_exportados.csv';

		// Gera o arquivo CSV e envia para o download
		$response = new StreamedResponse(function () use ($data) {
			$handle = fopen('php://output', 'w');

			// Adiciona os dados no arquivo CSV com separador ";"
			foreach ($data as $row) {
				fputcsv($handle, $row, ';');
			}

			fclose($handle);
		});

		$response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
		$response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");

		return $response;
	}
}
