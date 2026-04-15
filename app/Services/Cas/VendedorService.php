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

class VendedorService
{

	public static function obterIDVendedor($id)
	{
		
		$retorno               					= new stdClass;
		$retorno->vendedor_id 					= 0;
		$retorno->planos 						= array();
		
		$vendedor                               =  \App\Models\Vendedor::select('id')->where('user_id','=',$id)->first();
		
        if (isset($vendedor->id)) 
		{
			$retorno->vendedor_id 				= $vendedor->id;
			$retorno->planos 					= \App\Models\PlanoVendedor::where('vendedor_id', $vendedor->id)->pluck('plano_id')->toArray();
		}
		return $retorno;
		
	}

	public static function obterTokenVendedor($id)
	{
		
		$vendedor                               =  \App\Models\Vendedor::select('id','token')->where('user_id','=',$id)->first();
		
        if (isset($vendedor->id)) 
		{
			return Cas::nulltoSpace($vendedor->token);
		}
		
		return "";
		
	}
}
