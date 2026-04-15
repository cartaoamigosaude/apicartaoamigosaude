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
use App\Helpers\Epharma;
use App\Jobs\ChatHotJob;
use App\Jobs\CelCashParcelasAvulsaJob;
use Carbon\Carbon;
use PDF;
use DB;
use stdClass;

class FormatacaoService
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

	public static function temData($value)
	{
	
		if (substr_count($value, '-') > 0)
		{
			return true;
		}

		return false;
	}

	public static function validarCpf($cpf) 
	{
		// Remove caracteres não numéricos
		$cpf = preg_replace('/[^0-9]/', '', $cpf);
		$cpf = str_pad($cpf, 11, "0", STR_PAD_LEFT);	
		
		// Verifica se o CPF tem 11 dígitos e não é uma sequência repetitiva (ex: 111.111.111-11)
		if (strlen($cpf) != 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
			return false;
		}

		// Calcula o primeiro dígito verificador
		$soma = 0;
		for ($i = 0; $i < 9; $i++) {
			$soma += (int)$cpf[$i] * (10 - $i);
		}
		$resto = $soma % 11;
		$digito1 = ($resto < 2) ? 0 : 11 - $resto;

		// Verifica o primeiro dígito
		if ((int)$cpf[9] !== $digito1) {
			return false;
		}

		// Calcula o segundo dígito verificador
		$soma = 0;
		for ($i = 0; $i < 10; $i++) {
			$soma += (int)$cpf[$i] * (11 - $i);
		}
		$resto = $soma % 11;
		$digito2 = ($resto < 2) ? 0 : 11 - $resto;

		// Verifica o segundo dígito
		if ((int)$cpf[10] !== $digito2) {
			return false;
		}

		return true;
	}

	public static function limparCpf($value)
    {
		$value 				= preg_replace('/\D/', '', $value);
		$lixo 				= array(".", "-", "_", " ",",");
		$value				= str_replace($lixo, "", $value);
		return $value;
	}

	public static function removerAcentosEMaiusculo($texto) {
		// Substitui caracteres acentuados por equivalentes não acentuados
		$textoSemAcentos = preg_replace(
			[
				'/[áàâãäå]/u', '/[ÁÀÂÃÄÅ]/u',
				'/[éèêë]/u', '/[ÉÈÊË]/u',
				'/[íìîï]/u', '/[ÍÌÎÏ]/u',
				'/[óòôõöø]/u', '/[ÓÒÔÕÖØ]/u',
				'/[úùûü]/u', '/[ÚÙÛÜ]/u',
				'/[ç]/u', '/[Ç]/u',
				'/[ñ]/u', '/[Ñ]/u'
			],
			[
				'a', 'A',
				'e', 'E',
				'i', 'I',
				'o', 'O',
				'u', 'U',
				'c', 'C',
				'n', 'N'
			],
			$texto
		);

		// Converte a string para letras maiúsculas
		return mb_strtoupper($textoSemAcentos, 'UTF-8');
	}

	public static function formatCnpjCpf($value)
	{
		$CPF_LENGTH = 11;
		$cnpj_cpf = preg_replace("/\D/", '', $value);
		
		if (strlen($cnpj_cpf) === $CPF_LENGTH) {
			return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
		} 
		
		return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
	}

	public static function formatarTelefone($value) 
	{
		$value 				= preg_replace("/\D/", '', $value);

		if ($value == '')
		{
			return '';
		}

		$telefone 			 = '(';
		$telefone 			.= substr($value,0, 2);
		$telefone 			.= ') ';
		if (strlen($value) == 10)
		{
			$telefone 		.= '9' . substr($value,2, 4);
			$telefone 		.= '-';
			$telefone 		.= substr($value,6,4);
		} else
		{
			$telefone 		.= substr($value,2, 5);
			$telefone 		.= '-';
			$telefone 		.= substr($value,7,4);
	
		}
		
		return $telefone;
	}

	public static function formatarCPFCNPJ($value,$tipo)
    {
		$value 				= preg_replace("/\D/", '', $value);
		
        if ($tipo === 'F') {
            // Formatar CPF (000.000.000-00)
            return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "$1.$2.$3-$4", $value);
        } elseif ($tipo === 'J') {
            // Formatar CNPJ (00.000.000/0000-00)
            return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "$1.$2.$3/$4-$5", $value);
        }

        // Se o tipo não for definido, retornar o valor original
        return $value;
    }

	public static function getMessageValidTexto($message,$caracter="\n")
	{
		$mensagem											= "";
		$quebra												= "";
		if (is_object($message))
		{
			foreach ($message->toArray() as $key => $value) { 
				$value[0]									= str_replace("validation.cpf_valido","CPF Inválido",$value[0]);
				$mensagem									.= $quebra . $value[0];
				$quebra										= $caracter;
			}
		} else {
			$message										= str_replace("validation.cpf_valido","CPF Inválido",$message);
			$mensagem										= utf8_encode($message);
		}
		
		return $mensagem;
	}

	public static function isValidEmail($email)
	{
		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
	}

	public static function stripPrefix(string $value, string $prefix): string
    {
        return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }
}
