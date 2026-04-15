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

class ContratoBusinessService
{

	public static function obterSituacaoContrato($status)
	{
		switch ($status) 
		{
			case "active":
			case "waitingPayment":
				return 'Ativo';
			case "closed":
				return 'Encerrado';
			case "canceled":
			case "stopped":
				return 'Cancelado';
			case "suspended":
				return 'Suspenso';	
			default:
				return 'Registrado';
		}

		return $status;
	}

	public static function podeeditarContrato($situacao,$id=0)	
	{
		if ($situacao == '')
		{
			return 'S';
		}
		
		if ($id > 0)
		{
			$parcela 				= \App\Models\Parcela::where('contrato_id','=',$id)
														->where('boletobankNumber','>',0)
														->first();
			
			if (isset($parcela->id))
			{
				return 'N';
			} else {
				return 'S';
			}
		}
		
		return 'N';
	}

	public static function linkassinarContrato($situacao,$paymentLink)	
	{
		return $paymentLink;
	}

	public static function podecarneContrato($situacao,$contrato_id,$parcela_id=0)	
	{
		if ($situacao != 'active')
		{
			return 'N';
		}
		
		//return 'S';
		
		if ($parcela_id > 0)
		{
			if (\App\Models\Parcela::where('contrato_id','=',$contrato_id)
								   ->where('id','<>',$parcela_id)
								   ->where('data_pagamento','=',null)
								   ->where('data_baixa','=',null)
								   ->where('galaxPayId','>',0)
								   ->where('boletobankNumber','>',0)
								   ->count() ==0)
			{
				return 'N';
			} 
		} else {
			if (\App\Models\Parcela::where('contrato_id','=',$contrato_id)
								   ->where('data_pagamento','=',null)
								   ->where('data_baixa','=',null)
								   ->where('galaxPayId','>',0)
								   ->where('boletobankNumber','>',0)
								   ->count() ==0)
			{
				return 'N';
			} 
		}
		
		return 'S';
	}

	public static function ecartaoContrato($contrato_id)	
	{
		$contrato 					= \App\Models\Contrato::find($contrato_id);
		
		if (!isset($contrato->id))
		{
			return false;
		} 	
		
		if ($contrato->mainPaymentMethodId == 'creditcard')
		{
			return true;
		}
		
		return false;
	}

	public static function podefaturaContrato($situacao)	
	{
		if (($situacao == 'active') or ($situacao == 'waitingPayment'))
		{
			return 'S';
		}
		
		return 'N';
	}
}
