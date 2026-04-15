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
use DateTime;
use Exception;

class ParcelaBusinessService
{

	public static function obterSituacaoParcela($data_vencimento,$data_pagamento,$data_baixa)
    {
		
	    if ($data_pagamento!=null)
	    {
            return 'Paga';
	    }

        if ($data_baixa !=null)
	    {
            return 'Baixada';
	    } 
		  
	  
		if ($data_vencimento <= date('Y-m-d'))
		{
			return 'Vencida';  
		}			  
		
	    return 'Á vencer';  
    }	

	public static function podepagarParcela($situacao)	
	{
		if (($situacao == 'Vencida') or ($situacao == 'Á vencer'))
		{
			return 'S';
		}
		
		return 'N';
	}

	public static function podebaixarParcela($situacao, $galaxPayId)	
	{
		if (($situacao != 'Vencida') and ($situacao != 'Á vencer'))
		{
			return 'N';
		}
		
		if ($galaxPayId > 0)
		{
			return 'N';
		}

		return 'S';
	}

	public static function podeebaixaParcela($situacao,$parcela_id,$contrato_id)	
	{
		if ($situacao == 'Baixada')
		{
			if (\App\Models\Parcela::where('contrato_id','=',$contrato_id)->where('id','>',$parcela_id)->count() == 0)
			{
				return 'S';
			}
		}
		return 'N';
	}

	public static function podeepagarParcela($situacao,$parcela_id,$contrato_id)	
	{
		if ($situacao == 'Paga')
		{
			if (\App\Models\Parcela::where('contrato_id','=',$contrato_id)->where('id','>',$parcela_id)->count() == 0)
			{
				$parcela 									= \App\Models\Parcela::find($parcela_id);
				if (isset($parcela->id) and ((Cas::nulltoSpace($parcela->statusDescription) == "") or ((Cas::nulltoSpace($parcela->statusDescription) == "Paga fora do sistema"))))
				{
					return 'S';
				} 
				return 'N';
			}
		}
		return 'N';
	}

	public static function podeinserirParcela($contrato_id)	
	{
		$parcela = \App\Models\Parcela::where('contrato_id','=',$contrato_id)
									->orderBy('nparcela','desc')
									->first();
		
		if (isset($parcela->id))
		{
			if (($parcela->data_pagamento != null) or ($parcela->data_baixa != null))
			{
				return 'S';
			} else {
				return 'N';
			}
		} else {
			return 'S';
		}			
		
	}

	public static function podeexcluirParcela($contrato_id,$parcela_id,$data_pagamento,$data_baixa, $galaxPayId, $nparcela)
    {
		if ($data_pagamento != null)
		{
			return 'N';
		}

		if ($data_baixa != null)
		{
			return 'N';
		}	
		
		if ($nparcela ==1)
		{
			return 'N';
		}
		
		if ($galaxPayId > 0)
		{
			return 'N';
		}

		if (\App\Models\Parcela::where('contrato_id','=',$contrato_id)
							   ->where('id','>',$parcela_id)
							   ->count() == 1)
		{
			return 'N';
		} 
		
		return 'S';
		
	}

	public static function podenegociarParcela($situacao)	
	{
		if ($situacao != 'Vencida')
		{
			return 'N';
		}
		
		return 'S';
	}

	public static function podeeditarParcela($situacao,$galaxPayId)	
	{
		if (($situacao == 'Paga') or ($situacao == 'Baixada'))
		{
			return 'N';
		}
		
		if ($galaxPayId > 0)
		{
			return 'N';
		}

		return 'S';
	}

	public static function podeboletoParcela($situacao,$boletobankNumber)	
	{
		if (($situacao != 'Vencida') and ($situacao != 'Á vencer'))
		{
			return 'N';
		}
		
		if ($boletobankNumber > 0)
		{
			return 'N';
		}

		return 'S';
	}

	public static function podeeboletoParcela($situacao,$boletobankNumber, $contrato_id)	
	{
		if (($situacao == 'Paga') or ($situacao == 'Baixada'))
		{
			return 'N';
		}
		
		if ($boletobankNumber == 0)
		{
			return 'N';
		}

		$contrato 				= \App\Models\Contrato::find($contrato_id);
		
		if (!isset($contrato->id))
		{
			return 'N';
		}
		
		if ($contrato->tipo == 'F')
		{
			if (is_null($contrato->contractacceptedAt))
			{
				return 'N';
			}
		}
		
		return 'S';
	}

	public static function podecancelCobranca($situacao, $galaxPayId)	
	{
		if (($situacao == 'Paga') or ($situacao == 'Baixada'))
		{
			return 'N';
		}
		
		if ($galaxPayId == 0)
		{
			return 'N';
		}

		return 'S';
	}

	public static function obter_parcelaAbertas($contrato_id)
    {
		$ids 						= array();
		
		$parcelas 					= DB::table('parcelas')
										->select('galaxPayId','boletopdf')
										->where('contrato_id', '=', $contrato_id)
										->where('data_pagamento','=',null)
										->where('data_baixa','=',null)
										->where('galaxPayId','>',0)
										->orderBy('nparcela')
										->get();
														
		 foreach ($parcelas as $parcela)
         {
			 $ids[]					= $parcela->galaxPayId;
		 }
		 
		 return implode(",", $ids);
	}

	/**
	 * Função para calcular juros e multa de boletos em atraso
	 * 
	 * @param float $valorBoleto Valor original do boleto
	 * @param string|int $dataVencimentoOuDiasAtraso Data de vencimento no formato 'Y-m-d' (ex: '2023-12-31') ou número de dias em atraso
	 * @param string|null $dataAtual Data atual para cálculo no formato 'Y-m-d' (opcional, padrão: data atual)
	 * @param float $taxaJurosMensal Taxa de juros mensal em percentual (opcional, padrão: 1%)
	 * @param float $percentualMulta Percentual da multa por atraso (opcional, padrão: 2%)
	 * @return array Array com os valores calculados
	 */
	public static function  calcularJurosBoleto($valorBoleto, $dataVencimentoOuDiasAtraso, $dataAtual = null, $taxaJurosMensal = 5.0, $percentualMulta = 2.0) 
	{
		
		$retorno               					= new stdClass;
		$retorno->erro 							= "";
		$retorno->valorOriginal					= 0;
		$retorno->diasAtraso					= 0;
		$retorno->taxaJurosMensal				= 0;
		$retorno->taxaJurosDiaria				= 0;
		$retorno->juros							= 0;
		$retorno->percentualMulta				= 0;
		$retorno->multa							= 0;
		$retorno->multaJuros					= 0;
		$retorno->valorTotal					= 0;
		$retorno->emAtraso						= 0;
		
		// Validação dos parâmetros
		if (!is_numeric($valorBoleto) || $valorBoleto <= 0) {
			$retorno->erro 						= 'Valor do boleto inválido';
			return $retorno;
		}
		
		// Verificar se o segundo parâmetro é um número de dias em atraso
		if (is_numeric($dataVencimentoOuDiasAtraso) && is_int($dataVencimentoOuDiasAtraso + 0)) 
		{
			// Usar diretamente o número de dias em atraso
			$diasAtraso 							= max(0, (int)$dataVencimentoOuDiasAtraso);
			$emAtraso 								= $diasAtraso > 0;
		} else {
			// Usar o formato de data tradicional
			try {
				$vencimento 						= new DateTime($dataVencimentoOuDiasAtraso);
				$atual 								= $dataAtual ? new DateTime($dataAtual) : new DateTime('now');
			} catch (Exception $e) {
				$retorno->erro 						= 'Formato de data inválido';
				return $retorno;
			}
			
			// Se não estiver em atraso, retorna o valor original
			if ($atual <= $vencimento) 
			{
				$retorno->valorOriginal				= $valorBoleto;
				$retorno->diasAtraso				= 0;
				$retorno->juros						= 0;
				$retorno->multa						= 0;
				$retorno->valorTotal				= 0;
				$retorno->emAtraso					= 0;
				return $retorno;
			}
			
			// Calcular dias de atraso
			$diasAtraso 							= $vencimento->diff($atual)->days;
			$emAtraso 								= true;
		}
		
		// Converter taxa mensal para diária (considerando mês de 30 dias)
		$taxaJurosDiaria 							= ($taxaJurosMensal / 30) / 100;
		
		// Calcular juros
		$juros 										= $valorBoleto * $taxaJurosDiaria * $diasAtraso;
		
		// Calcular multa
		$multa 										= $valorBoleto * ($percentualMulta / 100);
		
		// Calcular valor total
		$valorTotal 								= $valorBoleto + $juros + $multa;
		$multaJuros 								= $juros + $multa;
		// Retornar array com todos os valores calculados
	
		$retorno->valorOriginal						= $valorBoleto;
		$retorno->diasAtraso						= $diasAtraso;
		$retorno->taxaJurosMensal					= $taxaJurosMensal;
		$retorno->taxaJurosDiaria					= $taxaJurosDiaria * 100;
		$retorno->juros								= $juros;
		$retorno->percentualMulta					= $percentualMulta;
		$retorno->multa								= $multa;
		$retorno->multaJuros 						= $multaJuros;
		$retorno->valorTotal						= $valorTotal;
		$retorno->emAtraso							= $emAtraso;
		return $retorno;
	}

	public static function ajustarDiaVencimento($data)
	{
		if (substr_count($data, '-') == 2) 
		{
			list($ano, $mes, $dia) 					= explode("-",$data);
			$formato 								= 'A';
		} else {
			if (substr_count($data, '/') == 2) 
			{
				list($dia, $mes, $ano) 				= explode("/",$data);
				$formato 							= 'B';
			} else {
				return $data;
			}
		}
		
		// Lista de dias por mês (Janeiro = 1, Fevereiro = 2, ..., Dezembro = 12)
		$diasPorMes = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

		// Verifica se o mês é fevereiro e se o ano é bissexto
		// Fevereiro (0-indexado)
		if ($mes === 2) 
		{ 
			$ehBissexto 							= ($ano % 4 === 0 && $ano % 100 !== 0) || ($ano % 400 === 0);
			$ndia 									= min($dia, $ehBissexto ? 29 : 28);
		} else {
			// Para outros meses, usa a tabela de dias por mês
			$ndia  									= min($dia, $diasPorMes[$mes - 1]);
		}
		
		if ($formato == 'A')
		{
			$data 									= $ano . "-" . $mes . "-"  . $ndia;
		} else {
			$data 									= $ndia . "/" . $mes . "/"  . $ano;
		}
		return $data; 
	}
}
