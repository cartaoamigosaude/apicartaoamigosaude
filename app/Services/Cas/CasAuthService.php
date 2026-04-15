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

class CasAuthService
{

	public static function obterEscopos($user)
	{

        if ($user->perfil_id == 1)
        {
            $sql                    		= "SELECT menus.rota, programas.nome as programa FROM menus JOIN programas ON menus.programa_id = programas.id";
            $menus			       			= DB::connection('mysql')->select($sql);
        
            $permissoes 					= array();

            $sql                          = "SELECT escopo as permissao, nome FROM opcoes";
            $spermissoes			       = DB::connection('mysql')->select($sql);

            foreach ($menus as $menu) 
            {
                foreach ($spermissoes as $permissao) {
                    $menuClone              = clone $menu;
                    $menuClone->permissao   = $permissao->permissao;
					$menuClone->nome   		= $permissao->nome;
                    $permissoes[]           = $menuClone;
                }
            }
        } else {
            $sql                          = "SELECT menus.rota, opcoes.escopo as permissao, opcoes.nome, programas.nome as programa
                                                FROM menus 
													JOIN perfil_menu_opcao 
														ON menus.id = perfil_menu_opcao.menu_id 
													JOIN programas 
														ON menus.programa_id = programas.id 
													JOIN opcoes 
														ON perfil_menu_opcao.opcao_id = opcoes.id
													WHERE perfil_menu_opcao.perfil_id = $user->perfil_id";
            $permissoes			        	= DB::connection('mysql')->select($sql);
        }     
                                    
        $escopos                    	= array(); 
		$acessos 						= array();
		
        foreach ($permissoes as $permissao) 
        {
            $key            							= $permissao->permissao . "." . $permissao->programa;

            if (!in_array($key, $escopos)) 
            {
                $escopos[] 								= $key;
            }
			
			if (!isset($acessos[$permissao->rota])) 
            {
                $acessos[$permissao->rota] 				= ['menu'=> $permissao->rota,'operacoes' => []];
            }
			
            $acessos[$permissao->rota]['operacoes'][] 	= ['botao' => $permissao->nome];
   
        }
		
		$acessos                = array_values($acessos);
        /*
		$escopos 					= array();
		$escopos[]					= 'view.beneficiarios';
        $escopos[]					= 'edit.beneficiarios';
        $escopos[]					= 'delete.beneficiarios';
        $escopos[]					= 'view.clientes';
        $escopos[]					= 'edit.clientes';
        $escopos[]					= 'delete.clientes';
        $escopos[]					= 'view.contratos';
        $escopos[]					= 'edit.contratos';
        $escopos[]					= 'delete.contratos';
		$escopos[]					= 'view.parcelas';
        $escopos[]					= 'edit.parcelas';
        $escopos[]					= 'delete.parcelas';
        $escopos[]					= 'view.periodicidades';
        $escopos[]					= 'edit.periodicidades';
        $escopos[]					= 'delete.periodicidades';
        $escopos[]					= 'view.planos';
        $escopos[]					= 'edit.planos';
        $escopos[]					= 'delete.planos';
        $escopos[]					= 'view.situacoes';
        $escopos[]					= 'edit.situacoes';
        $escopos[]					= 'delete.situacoes';
        $escopos[]					= 'view.vendedores';
        $escopos[]					= 'edit.vendedores';
        $escopos[]					= 'delete.vendedores';
		$escopos[]					= 'view.produtos';
        $escopos[]					= 'edit.produtos';
        $escopos[]					= 'delete.produtos';
		$escopos[]					= 'view.especialidades';
        $escopos[]					= 'edit.especialidades';
        $escopos[]					= 'delete.especialidades';
		$escopos[]					= 'view.clinicas';
        $escopos[]					= 'edit.clinicas';
        $escopos[]					= 'delete.clinicas';
		$escopos[]					= 'view.agendamentos';
        $escopos[]					= 'edit.agendamentos';
        $escopos[]					= 'delete.agendamentos';
		$escopos[]					= 'view.usuarios';
        $escopos[]					= 'edit.usuarios';
        $escopos[]					= 'delete.usuarios';
		$escopos[]					= 'view.menus';
        $escopos[]					= 'edit.menus';
        $escopos[]					= 'delete.menus';
		$escopos[]					= 'view.permissoes';
        $escopos[]					= 'edit.permissoes';
        $escopos[]					= 'delete.permissoes';
		*/
		$retorno					= new stdClass();
		$retorno->escopos 			= $escopos;
		$retorno->acessos 			= $acessos;
		
		return $retorno;
	}

	public static function oauthToken($login,$senha,$escopos)
    {
        $app_url                = env('APP_URL');
		$app_url = config('app.url');
		Log::info("app_url", ['app_url'	=> $app_url]);
		Log::info("login", ['login'	=> $login]);
		Log::info("senha", ['senha'	=> $senha]);
		Log::info("escopos", ['escopos'	=> $escopos]);
		 
        $response               = Http::asForm()->post($app_url . '/oauth/token', [
														'grant_type'    => 'password',
														'client_id'     => config('app.passport_client_id'),
														'client_secret' => config('app.passport_client_secret'),
														'username'      => $login,
														'password'      => $senha,
														'scope'         => is_array($escopos) ? implode(' ', $escopos) : (string) $escopos,
												 ]);
            
         $token                  = $response->json();

		 //Log::info("token", ['token'	=> $token]);

         return $token;
    }

	public static function refreshToken($request)
	{
		$request->validate(['refresh_token' => 'required|string']);
		$app_url                = env('APP_URL');
		$response 				= Http::asForm()->post($app_url . '/oauth/token', [
														'grant_type'        => 'refresh_token',
														'refresh_token'     => $request->refresh_token,
														'client_id'         => env('PASSPORT_CLIENT_ID'),
														'client_secret'     => env('PASSPORT_CLIENT_SECRET'),
														'scope'             => '',
												]);

		return $response->json();
	}
}
