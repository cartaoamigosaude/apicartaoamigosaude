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

class PermissaoService
{

	public static function limparCacheMenu()
    {
		$sql 					= "SELECT id FROM menus where id > 0";
		$menus					= DB::connection('mysql')->select($sql);

		foreach ($menus as $menu)
		{
			\Cache::forget("buscar_menu_pai:{$menu->id}");
		}
	}

	public static function buscarParentId($id)
	{
		DB::statement('SET SESSION cte_max_recursion_depth = 1000000');

        $chave_cache 				= "buscar_parent_id:{$id}";	

		$menus 						= \Cache::remember($chave_cache, now()->addDays(1), function () use ($id) 
		{
			return DB::connection('mysql')
						->table(DB::raw("(WITH RECURSIVE menu_cte AS (
											SELECT 
												id, 
												parent_id, 
												nome, 
												rota,
												ordem,
												programa_id
											FROM menus
											WHERE id = '$id'
											UNION ALL
											SELECT 
												u.id, 
												u.parent_id, 
												u.nome,
												u.rota,
												u.ordem,
												u.programa_id
											FROM menus u
											INNER JOIN menu_cte cte ON cte.parent_id = u.id
										) 
										SELECT * FROM menu_cte) as menu_cte"
							))
						->select(
							'menu_cte.id',
							'menu_cte.parent_id',
							'menu_cte.nome',
							'menu_cte.rota',
							'menu_cte.id as key',
							'menu_cte.ordem',
							'menu_cte.programa_id',
						)
						->get();
		});
	
        return $menus;
	}

	public static function verificadorPermissoes($menu_id, $perfil_id, $opcao_id = 0) 
	{
		$sql 			= "SELECT count(*) as qnt FROM perfil_menu_opcao WHERE menu_id = $menu_id AND perfil_id = $perfil_id AND opcao_id = $opcao_id";
		$permissao		= DB::connection('mysql')->select($sql);

		if (!isset($permissao[0]->qnt))
		{
			return 0;
		}
	
		return $permissao[0]->qnt;
	}

	public static function obterIdPermissaoListar() 
	{
		$sql 				= "SELECT id FROM opcoes WHERE escopo = 'view'";
		$permissao			= DB::connection('mysql')->select($sql);

		if (!isset($permissao[0]->id))
		{
			return 0;
		}
	
		return $permissao[0]->id;
	}

	public static function obterFilhasMenu($parent_id) 
	{
		$menus				= array();

		$sql 				= "WITH RECURSIVE menu_cte AS (
									SELECT 
										menu_id, 
										parent_id
									FROM menus
									WHERE parent_id = $parent_id
									UNION ALL
									SELECT 
										u.menu_id, 
										u.parent_id
									FROM menus u
									INNER JOIN menu_cte cte ON cte.menu_id = u.parent_id
								)
								SELECT menu_id
								FROM menu_cte";
		$filhos				= DB::connection('mysql')->select($sql);

		if (isset($filhos[0]->menu_id))
		{
			$menus 			= array_column($filhos, 'menu_id');
		}
		
		$menus[]			= $parent_id;

		return $menus;
	}

	public static function inserirListarMenu($menu_id, $perfil_id, $opcao_id)
	{
		$id_listar 			= Cas::obterIdPermissaoListar();

		if (($id_listar == 0) or ($id_listar == $opcao_id))
		{
			return false;
		}

		$sql 				= "SELECT count(*) as qnt FROM perfil_menu_opcao WHERE menu_id 	= '$menu_id' AND perfil_id = '$perfil_id' AND opcao_id  = '$id_listar'";
		$count				= DB::connection('mysql')->select($sql);

		if ((!isset($count[0]->qnt)) or ($count[0]->qnt > 0))
		{
			return false;
		}

		$insert 			= DB::connection('mysql')
									->table('perfil_menu_opcao')
									->insert([
											'menu_id'   => $menu_id,
											'nivel_id'  => $perfil_id,
											'opcao_id' 	=> $id_listar,
											'pode' 		   => 1,
										]);
	}
}
