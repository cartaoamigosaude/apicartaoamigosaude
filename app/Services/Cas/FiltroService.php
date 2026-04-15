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

class FiltroService
{

	public static function montar_filtro($query, $payload)
	{		
		foreach ($payload->campos as $filtro)
		{
			$filtro									= (object) $filtro;
			$condicoes								= (object) $filtro->condicoes;

			foreach ($condicoes as $condicao)
			{
				$condicao							= (object) $condicao;

				switch ($filtro->tipo) {
					case "dec":
						$condicao->conteudos[0]	    = str_replace(",",".",$condicao->conteudos[0]);
						break;
					case "cpfcnpj":
						$condicao->conteudos[0]	    = preg_replace("/\D/", '', $condicao->conteudos[0]);
						break;
				}

				switch ($condicao->condicao) {
					case "equals":
						if ($filtro->campo != 'beneficiario_produto.ativacao')
						{
							if ($condicao->conteudos[0] === null)
							{
								$query->whereNull($filtro->campo);
							} else {
								$conteudo                      = $condicao->conteudos[0];
								$query->where($filtro->campo, '=' , "$conteudo");
							}
						}
						break;
					case "ranger":
						if ($filtro->tipo == "data_hora")
						{
							$condicao->conteudos[0]	= ($condicao->conteudos[0] . ' 00:00:00');
							$condicao->conteudos[1]	= ($condicao->conteudos[1] . ' 23:59:59');
						}
						$query->where($filtro->campo, '>=' , $condicao->conteudos[0])
							  ->where($filtro->campo, '<=' , $condicao->conteudos[1]);
						break;
					case "in":
						if (!empty($condicao->conteudos[0]) && is_array($condicao->conteudos[0])) {
							$query->whereIn($filtro->campo, $condicao->conteudos[0]);
						}
						break;
					case "contains":
						$query->where($filtro->campo, 'like' , "%".$condicao->conteudos[0]."%" );
						break;
					case "lcontains":
						$query->where($filtro->campo, 'like' ,$condicao->conteudos[0]."%" );
						break;
					case "gt":
						if ($filtro->campo != 'parcelas.dias')
						{
							$query->where($filtro->campo, '>' ,$condicao->conteudos[0]);
						} else {
							$query->where('parcelas.data_vencimento', '<=' ,Carbon::now()->subDays($condicao->conteudos[0]));
							$query->whereNull('parcelas.data_pagamento');
							$query->whereNull('parcelas.data_baixa');
						}
						break;
					case "lt":
						$query->where($filtro->campo, '<=' ,$condicao->conteudos[0]);
						break;
					case "equalsNull":
						$query->where($filtro->campo, '=',null);
						break;
					case "notEqualsNull":
						$query->where($filtro->campo, '!=',null);
						break;
					case "notEquals":
					    $conteudo                      = $condicao->conteudos[0];
						$query->where($filtro->campo, '!=',"$conteudo");
						break;
					case "isNull":
						$query->whereNull($filtro->campo);
						break;
					case "isNotNull":
						$query->whereNotNull($filtro->campo);
						break;
					case "equalsColumn":
						$query->whereColumn($filtro->campo, '=', $condicao->conteudos[0]);
						break;
					case "gtColumn":
						$query->whereColumn($filtro->campo, '>', $condicao->conteudos[0]);
						break;
					case "ltColumn":
						$query->whereColumn($filtro->campo, '<', $condicao->conteudos[0]);
						break;
					case "notEqualsColumn":
						$query->whereColumn($filtro->campo, '!=', $condicao->conteudos[0]);
						break;
				}
			}
		};

		return $query;
	}

	public static function obterCombo($value,$id=0)
	{
		$tabelas					= array(); 	
		if (!is_array($value))
		{
			if (strpos($value, ',') > 0)
			{
				$tabelas 			= explode(",", $value);
			} else {
				$tabelas[]			= $value;
			}
		} else {
			$tabelas				= $value;
		}

		$combo               		= new stdClass;

		foreach ($tabelas as $tabela)
		{
			switch ($tabela) 
			{ 
				case 'users':
					$sql 						= "SELECT id, name as nome FROM users order by name";
					$combo->users				= DB::select($sql);
					break;
				case 'situacoes':
					$sql 						= "SELECT id, nome, cobranca FROM situacoes where ativo=1 order by nome";
					$combo->situacoes			= DB::select($sql);
					break;
				case 'motivos':
					$sql 						= "SELECT id, nome, ativo FROM motivos where ativo=1 order by nome";
					$combo->motivos				= DB::select($sql);
					break;
				case 'asituacoes':
					$sql 						= "SELECT id, nome, orientacao, whatsapp FROM asituacoes where ativo=1 order by nome";
					$combo->asituacoes			= DB::select($sql);
					break;
				case 'cmotivos':
					$sql 						= "SELECT id, nome  FROM agendamento_cmotivos order by ordem";
					$combo->cmotivos			= DB::select($sql);
					break;
				case 'periodicidades':
					$sql 						= "SELECT id, nome FROM periodicidades where ativo=1 order by nome";
					$combo->periodicidades		= DB::select($sql);
					break;
				case 'vendedores':
					if (($id > 0) and (!isset($vendedor)))
					{
						$vendedor				= Cas::obterIDVendedor($id);	
					}
					if ((isset($vendedor->vendedor_id)) and ($vendedor->vendedor_id > 0))
					{
						$sql 					= "SELECT id, nome FROM vendedores where id=" . $vendedor->vendedor_id . " order by nome";
					} else {
						$sql 					= "SELECT id, nome FROM vendedores where ativo=1 order by nome";
					}
					$combo->vendedores			= DB::select($sql);
					$combo->sql 				= $sql;
					break;
				case 'planos':
					if (($id > 0) and (!isset($vendedor)))
					{
						$vendedor				= Cas::obterIDVendedor($id);	
					}
					if ((isset($vendedor->planos)) and (count($vendedor->planos) > 0))
					{
						$sql 					= "SELECT id, nome, formapagamento, parcelas, preco FROM planos where id in(" . implode(",", $vendedor->planos) . ") and ativo=1 order by nome";
					} else {
						$sql 					= "SELECT id, nome, formapagamento, parcelas, preco FROM planos where ativo=1 order by nome";
					}
					$combo->planos				= DB::select($sql);
					break;
				case 'vplanos':
					$sql 						= "SELECT id, nome, 0 as incluso FROM planos where ativo=1 and galaxPayId> 0 order by nome";
					$combo->vplanos				= DB::select($sql);
					break;
				case 'produtos':
					$sql 						= "SELECT id, nome FROM produtos where ativo=1 order by nome";
					$combo->produtos			= DB::select($sql);
					break;
				case 'especialidades':
					$sql 						= "SELECT id, nome FROM especialidades where ativo=1 order by nome";
					$combo->especialidades		= DB::select($sql);
					break;
				case 'perfis':
					$sql 						= "SELECT id, nome FROM perfis order by nome";
					$combo->perfis				= DB::select($sql);
					break;
				case 'menus_pais':
					$sql 						= "SELECT id, nome FROM menus WHERE programa_id IS NULL AND rota IS NULL ORDER BY ordem";
					$combo->menus_pais			= DB::select($sql);
					break;
				case 'programas':
					$sql 						= "SELECT id,nome FROM programas";
					$combo->programas			= DB::select($sql);
					break;
				case 'parentescos':
					$sql 						= "SELECT id,nome FROM parentescos order by nome";
					$combo->parentescos			= DB::select($sql);
					break;
			}
		}
		return $combo;										
	}

	public static function obterCep($cep)
    {
		
		$retorno				= new stdClass();
		$retorno->ok 			= "";
		$retorno->lcep 			= $cep;
		$retorno->endereco		= "";
		$retorno->bairro		= "";
		$retorno->cidade		= "";
		$retorno->estado		= "";
		
		$lixo 					= array(".","-","_"," ",",");
		$cep 					= preg_replace('/\D/', '', $cep);
		$cep					= str_replace($lixo,"",$cep);
		$cep					= str_pad($cep, 8, "0", STR_PAD_LEFT);
		
		if ($cep ==0)
		{
			$retorno->ok 		= "N";
			$retorno->lcep 		= "";
			return $retorno;	
		}
		
		$endereco = null;
		try {
			$enderecoJson 		= file_get_contents("http://viacep.com.br/ws/$cep/json/");
			if ($enderecoJson !== false) {
				$endereco 		= json_decode($enderecoJson);
			}
		} catch (\Exception $e) {
			// Em caso de erro, $endereco permanece null
			$endereco = null;
		}
		  
		if ($cep !== $retorno->lcep)
		{
			$retorno->lcep		= $cep;
		} else {
			$retorno->lcep		= "";
		}
		
		// Verificar se $endereco é um objeto válido antes de acessar suas propriedades
		if ($endereco && is_object($endereco)) {
			if ((isset($endereco->logradouro)) and ($endereco->logradouro !=""))
			{	
				$retorno->endereco	= Cas::nulltoSpace($endereco->logradouro);
			}
			
			if ((isset($endereco->bairro)) and ($endereco->bairro !=""))
			{	
				$retorno->bairro	= Cas::nulltoSpace($endereco->bairro);
			}
			
			if ((isset($endereco->localidade)) and ($endereco->localidade !=""))
			{	
				$retorno->cidade	= Cas::nulltoSpace($endereco->localidade);
			}
			
			if ((isset($endereco->uf)) and ($endereco->uf !=""))
			{	
				$retorno->estado	= Cas::nulltoSpace($endereco->uf);
			}
			
			if ((isset($endereco->localidade)) and ($endereco->localidade !=""))
			{
				$retorno->ok 		= "S";
			} else {
				$retorno->ok 		= "N";
			}
		} else {
			// Se $endereco é null ou inválido, definir como não encontrado
			$retorno->ok 		= "N";
		}
		
		return $retorno;
	}
}
