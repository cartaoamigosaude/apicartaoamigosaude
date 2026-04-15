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

class PdfService
{

	public static function gerarContratoPDF($id,$ip)
	{
		$contrato 				                    = \App\Models\Contrato::with('plano')->find($id);
        $paragrafos									= array();
        
        if (isset($contrato->id))
        {		
			$paragrafos 							= Cas::formatarContratoParaPDF(Cas::formatarTextoContrato($contrato->plano->clausulas,$contrato->id));
		}
		
		if (count($paragrafos) > 0)
		{
			 $data = [
				'paragrafos' 	=> $paragrafos,
				'dataHora' 		=> date('d/m/Y H:m:s'),
				'ip' 			=> $ip
			];
	
			$foldername								= '/uploads/contratos/' . $contrato->id . Str::random(10) .'.pdf';
			$destinationpath 						= public_path()  . $foldername;
			$url        							= url("/") . $foldername;

			$directoryPath 							= public_path('/uploads/contratos'); 
			if (!is_dir($directoryPath)) 
			{
				mkdir($directoryPath, 0755, true);
			}
			
			$pdf 									= PDF::loadView('contratopdf', $data)->save($destinationpath);
			$contrato->status 						= 'active';	
			$contrato->contractpdf 					= $url;
			$contrato->contractacceptedAt			= date('Y-m-d H:m:s');
			if ($contrato->save())
			{
				CelCashParcelasAvulsaJob::dispatch($contrato->id)->onQueue('default');
			}
			/*
			Assinado digitalmente por:
			Nome: EDILAINE IZABEL FERREIRA
			CPF: 352.878.208-01
			Data/Hora de aceitação: 23/07/2024 - 13:16
			IP: 45.160.2.75
			*/
			return true;
		}
		
		return false;
	}

	public static function gerarVoucherPDF($id)
	{
		$agendamento            			    = \App\Models\ClinicaBeneficiario::with('clinica','especialidade')->find($id);
		
		if (isset($agendamento->id))
        {
			$beneficiario              			= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
			if (isset($beneficiario->id))
			{
				$foldername						= '/uploads/voucher/' . $agendamento->id . Str::random(10) .'.pdf';
				$destinationpath 				= public_path()  . $foldername;
				$url        					= url("/") . $foldername;

				$directoryPath 					= public_path('/uploads/voucher'); 
				if (!is_dir($directoryPath)) 
				{
					mkdir($directoryPath, 0755, true);
				}
				
				$voucher                        = new stdClass();
				$voucher->numero_voucher		= 'VCH-' . str_pad($agendamento->id, 6, '0', STR_PAD_LEFT);
				$voucher->paciente				= $beneficiario->cliente->nome;
				list($ano,$mes,$dia) 			= explode("-",$beneficiario->cliente->data_nascimento);
				$voucher->data_nascimento		= $dia . "/" . $mes . "/" . $ano;
				$voucher->especialidade			= $agendamento->especialidade->nome;
				$voucher->clinica				= $agendamento->clinica->nome;
				
				$clinicaendereco 				= "CEP: " . $agendamento->clinica->cep . " | ";
				$clinicaendereco 				.= $agendamento->clinica->logradouro . ", " . $agendamento->clinica->numero;

				if (!empty(Cas::nulltoSpace($agendamento->clinica->complemento))) {
					$clinicaendereco 			.= " - " . $agendamento->clinica->complemento;
				}

				$clinicaendereco 				.= " - " . $agendamento->clinica->bairro;
				$clinicaendereco 				.= " | " . $agendamento->clinica->cidade . " - " . $agendamento->clinica->estado;

				$voucher->endereco				= $clinicaendereco;
				$voucher->telefone				= $agendamento->clinica->telefone;
				
				$data 							= substr($agendamento->agendamento_data_hora,0,10);
				list($ano,$mes,$dia) 			= explode("-",$data);
				$data_hora						= $dia . "/" . $mes . "/" . $ano . ' ás ' . substr($agendamento->agendamento_data_hora,11,5);
				
				if ($agendamento->valor > 0)
				{
					$voucher->valor 			= "R$ ". str_replace(".",",",$agendamento->valor);
				} else {
					$voucher->valor				= "";
				}
		
				$voucher->data_hora				= $data_hora;
				$voucher->mostrar_valor 		= $agendamento->clinica->mostrar_valor;
				$pdf 							= PDF::loadView('voucherpdf', ['voucher' => $voucher])->setPaper('A4', 'portrait')->save($destinationpath);
				
				$agendamento->url_voucher 		= $url;
				$agendamento->save();
				return $url;
			}
		}
	}

	public static function formatarTextoContrato($html,$id)
    {
        // Remove todas as tags HTML exceto <p> e <br>
        $texto = strip_tags($html, '<p><br>');
        
        // Remove atributos das tags
        $texto = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si', '<$1$2>', $texto);
        
        // Remove espaços extras e quebras de linha
        $texto = preg_replace('/\s+/', ' ', $texto);
        
        // Remove parágrafos vazios
        $texto = preg_replace('/<p>\s*<\/p>/', '', $texto);
        
        // Substitui tags <p> por quebras de linha duplas
        $texto = str_replace(['<p>', '</p>'], ['', "\n\n"], $texto);
        
        // Remove espaços no início e fim
        $texto = trim($texto);
        
        // Garante que parágrafos tenham espaçamento consistente
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
        
		$contrato 				                    = \App\Models\Contrato::with('cliente','plano')->find($id);
                
        if (isset($contrato->id))
        {	
	
			if (substr_count($texto, '[NRO_CONTRATO]') > 0)
			{
				$texto								= str_replace("[NRO_CONTRATO]",$contrato->id,$texto);
			}
			
			if (substr_count($texto, '[ENDERECO_CLIENTE]') > 0)
			{
				$endereco 							= $contrato->cliente->cep;
				$endereco 							.= " - " . $contrato->cliente->logradouro . ", " . $contrato->cliente->numero . " " . Cas::nulltoSpace($contrato->cliente->complemento);
				$endereco 							.= "\n" .  $contrato->cliente->bairro . " | " . $contrato->cliente->cidade . " | " . $contrato->cliente->estado;
				$texto								= str_replace("[ENDERECO_CLIENTE]",$endereco,$texto);
			}
		
			if (substr_count($texto, '[NOME_CLIENTE]') > 0)
			{
				$texto								= str_replace("[NOME_CLIENTE]",$contrato->cliente->nome,$texto);
			}
			
			if (substr_count($texto, '[DOCUMENTO_CLIENTE]') > 0)
			{
				$texto								= str_replace("[DOCUMENTO_CLIENTE]",$contrato->cliente->cpfcnpj,$texto);
			}
			
			if (substr_count($texto, '[TELEFONE_CLIENTE]') > 0)
			{
				$texto								= str_replace("[TELEFONE_CLIENTE]",$contrato->cliente->telefone,$texto);
			}
			
			
			if (substr_count($texto, '[EMAIL_CLIENTE]') > 0)
			{
				$texto								= str_replace("[EMAIL_CLIENTE]",$contrato->cliente->email,$texto);
			}
			
			if (substr_count($texto, '[NOME_PLANO]') > 0)
			{
				$texto								= str_replace("[NOME_PLANO]",$contrato->plano->nome,$texto);
			}
			
			if (substr_count($texto, '[VALOR_CONTRATO]') > 0)
			{
				$valor 								= "R$ " . str_replace(".",".",$contrato->valor);
				$texto								= str_replace("[VALOR_CONTRATO]",$valor,$texto);
			}
			
			if (substr_count($texto, '[QUANTIDADE_TRANSACOES_CONTRATO]') > 0)
			{
				
				$texto								= str_replace("[QUANTIDADE_TRANSACOES_CONTRATO]",$contrato->plano->parcelas,$texto);
			}
		}
		
        return $texto;
    }

	public static function formatarContratoParaPDF($texto)
    {
		
		 Log::info("formatarContratoParaPDF", ['entrada'	=> $texto]);
		 
        // Decodifica caracteres especiais
       // $texto = json_decode($texto, true);
        
        // Remove caracteres especiais Unicode
        $texto = str_replace(["\u00a0", "\u00ba", "\u00aa"], [" ", "º", "ª"], $texto);
        
        // Quebra o texto em parágrafos
        $paragrafos = preg_split('/\n\s*\n/', $texto);
        
        // Remove espaços extras e linhas vazias
        $paragrafos = array_map(function($p) {
            return trim(preg_replace('/\s+/', ' ', $p));
        }, $paragrafos);
        
        // Remove parágrafos vazios
        $paragrafos = array_filter($paragrafos);
		
		 Log::info("formatarContratoParaPDF", ['saida'	=> $paragrafos]);
        
        return $paragrafos;
    }
}
