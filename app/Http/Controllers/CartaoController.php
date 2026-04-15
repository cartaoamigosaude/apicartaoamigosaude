<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Auth;	
use Illuminate\Support\Facades\Http;	
use Illuminate\Http\Request;
use App\Helpers\Cartao;
use stdClass;
							   
class CartaoController extends Controller
{
	
	public function store_titular(Request $request)
	{
		$retorno 			= Cartao::storeTitularContrato();
		
		return \Response::json(true, 200);
	}
	
	public function obter_cartao(Request $request)
	{
		
		$Cpf 				= $request->input('Cpf',"");
		$CodOnix 			= $request->input('CodOnix',"");
		$retorno 			= Cartao::obterCartao($Cpf, $CodOnix);
		
		return \Response::json($retorno, 200);	 
	}
	
	public function obter_dependentes(Request $request)
	{
		$Cpf 				= $request->input('Cpf',"");
		$CodOnix 			= $request->input('CodOnix',"");
		$tokenzeus 			= $request->input('tokenzeus',"");
		
		$url      			= config('services.cartao_tem.api_url') . '/tem_dependente';
		$hresponse   		= Http::post($url, ["Cpf" => $Cpf, "CodOnix" => $CodOnix, "tokenzeus" => $tokenzeus]);
		$mensagem			= "";
		
		$response 			= $hresponse->object();
		if ((isset($response->result)) and (count($response->result) > 0))
		{
			$qtde 			= count($response->result);
			
			$mensagem		= "\n\n👤 ($qtde) dependentes";
			foreach ($response->result as $result)
			{
				list($ano,$mes,$dia) 		= explode("-",$result->DATA_NASCIMENTO);
				$mensagem  .= "\n\nCPF:     " . $result->CPF_DEPENDENTE;
				$mensagem  .= "\nNome:    " . $result->NOME_DEPENDENTE;
				$mensagem  .= "\nDt Nasc: " . "$dia/$mes/$ano";
			}
		} else {
			$mensagem		= "\n\nNenhum dependente";
		}
		
		$retorno				 = new stdClass();
		$retorno->dependentes	 = $mensagem;
		$retorno->status 		 = $hresponse->status();	
		return \Response::json($retorno, 200);	 
	}
	
}
