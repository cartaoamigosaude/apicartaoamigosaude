<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use App\Helpers\Cas;
use Carbon\Carbon;
use DB;
use stdClass;

class MenuController extends Controller
{
	
	public function index_parent ($id)
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

    public function treeview(Request $request)
    {
        $orderby 					= $request->input('orderby', 'menus.ordem');
        $direction 					= $request->input('direction', 'asc');
        $conteudo	 				= $request->input('conteudo', "");
	
		if (!Cache::has('menu')) 
		{
			Cas::limparCacheMenu();
		}

		$query 					= DB::connection('mysql')
									->table('menus')
									->select(	
										'menus.id',
										'menus.parent_id', 
										'menus.rota',
										'menus.ordem',
										'menus.nome',
										'menus.programa_id'
									)
									->where('menus.id', '>=', 1);

		$query->where(function ($query) use ($conteudo)
		{
			if ($conteudo !== "") 
			{
				$query->orWhere('menus.nome',	  	'like', "%$conteudo%")
					  ->orwhere('menus.id',			'like', "%$conteudo%");
			}
		});
		
		$query->orderBy('menus.ordem', 'asc');
		$menus 					= $query->get();
		$todos_menus			= [];
		//return response()->json($menus, 200);

		foreach ($menus as $menu) 
		{
			// VERIFICAR SE O MENU PAI JA FOI ENCONTRADA
			$pai_id = $menu->parent_id;

			if (!isset($todos_menus[$pai_id])) 
			{
				// VERIFICAR SE O PAI JA ESTA NO ARRAY ATUAL DE MENUS
				$pai_encontrado 		= false;
				foreach ($menus as $menu_item) 
				{
					if ($menu_item->id == $pai_id) 
					{
						$pai_encontrado = true;
						break;
					}
				}
				// SE O PAI NAO ESTA NO ARRAY VAI BUSCAR (NECESSARIO PARA O TREEVIEW)
				if (!$pai_encontrado) 
				{
					$pais = Cas::buscarParentId($pai_id);
					
					foreach ($pais as $pai) 
					{
						// SE O PAI NAO EXISTES EM TODOS OS MENUS ADICIONA ELE
						if (!isset($todos_menus[$pai->id])) 
						{
							$todos_menus[$pai->id] = $pai;
						}
					}
				}
			}
			// ADICIONA A UNIDADE EM TODAS AS UNIDADES SE NAO ESTIVER
			if (!isset($todos_menus[$menu->id]))
			{
				$todos_menus[$menu->id] 			= $menu;
			}
		}
		
		$refs 											= [];
		$tree 											= [];

		$programas										= array();

		foreach ($todos_menus as $menu) 
		{
			
			$sql 									= "SELECT count(*) as quantidade FROM menus WHERE parent_id = $menu->id";
			$parent									= DB::connection('mysql')->select($sql);

			if ($parent[0]->quantidade > 0)
			{
				$pode_excluir						= 'N';
			} else {
				$pode_excluir						= 'S';
			}
			
			$menu->pode_excluir 					= $pode_excluir; 
			$menu->programa							= '';

			if ((!is_null($menu->programa_id)) and ($menu->programa_id != 0))
			{
				if((!isset($programas[$menu->programa_id])))
				{
					$programas[$menu->programa_id] 	= '';

					$sql 						= "SELECT nome FROM programas WHERE id = $menu->programa_id";
					$programa					= DB::connection('mysql')->select($sql);

					if (isset($programa[0]->nome))
					{
						$programas[$menu->programa_id] = $programa[0]->nome;
					}
				}

				$menu->programa					= $programas[$menu->programa_id];
			}

			$menu_array 							= (array) $menu;
			$menu_array['children'] 				= [];
			$refs[$menu->id] 						= $menu_array;
		}
		
		foreach ($todos_menus as $menu) 
		{
			if ($menu->id == 1) 
			{
				$tree[] 											= &$refs[$menu->id];
			} else 
			{
				$refs[$menu->parent_id]['children'][] 				= &$refs[$menu->id];
			}
		}			
		
		\Cache::put('menu', true, now()->addDays(1));

		return response()->json($tree, 200);
	}

	public function show($id, Request $request)
    {
		if (!$request->user()->tokenCan('view.menus')) 
        {
            return response()->json(['error' => 'Não autorizado para alterar.'], 403);
        }

		$menu 					= DB::connection('mysql')->table('menus')
														->select(
															'id',
															'nome',
															'ordem',
															'rota',
															'parent_id', 
															'programa_id',
															'icon',
														)
														->where('id', '=',$id)
														->first();

		if (!isset($menu->id)) 
		{
			return response()->json(['error' => 'Menu não encontrado'], 400);
		}

		return response()->json($menu, 200);	
	}

	public function store (Request $request)
    {
		if (!$request->user()->tokenCan('edit.menus')) 
        {
            return response()->json(['error' => 'Não autorizado para inserir.'], 403);
        }

		if ($request->submenu == 0)
		{
			$valid         	 		= validator($request->only(
				'nome', 
				'ordem',
			),[
				'nome'				=> 'required',
				'ordem'				=> 'required|numeric',
			]);
		} else
		{
			$valid         	 		= validator($request->only(
											'parent_id',
											'nome', 
											'ordem',
											'programa_id',
											'rota', 
											'icon',
										),[
											'parent_id'			=> 'required|exists:menus,id',
											'nome'				=> 'required',
											'ordem'				=> 'required|numeric',
											'programa_id'		=> 'required|exists:programas,id',
											'rota'				=> 'required',
											'icon'				=> 'required',
										]);
		}

		if ($valid->fails())
		{
			return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 400);
		}

		$menu            					= new \App\Models\Menu();
		$menu->nome							= $request->nome;
		$menu->ordem 						= $request->ordem;
		$menu->parent_id 					= 1;
		$menu->icon							= $request->icon;

		if ($request->submenu == 1)
		{
			$menu->programa_id				= $request->programa_id;
			$menu->rota						= $request->rota;
			$menu->parent_id 				= $request->parent_id;
		}
		
		if (!$menu->save())
		{     
			return response()->json(['error' => 'Erro ao salvar'], 500);
		}

		return response()->json(['id' => $menu->id], 200);
	}

	public function update ($id, Request $request)
    {	
		if (!$request->user()->tokenCan('edit.menus')) 
        {
            return response()->json(['error' => 'Não autorizado para alterar.'], 403);
        }

		if ($request->submenu == 0)
		{
			$valid         	 = validator($request->only(
				'nome', 
				'ordem',
			),[
				'nome'				=> 'required',
				'ordem'				=> 'required|numeric',
			]);
		} else
		{
			$valid         	 = validator($request->only(
											'parent_id',
											'nome', 
											'ordem',
											'programa_id',
											'rota', 
											'icon',
										),[
											'parent_id'			=> 'required|exists:menus,id',
											'nome'				=> 'required',
											'ordem'				=> 'required|numeric',
											'programa_id'		=> 'required|exists:programas,id',
											'rota'				=> 'required',
											'icon'				=> 'required',
										]);
		}

		if ($valid->fails())
		{
			return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 400);
		}

		$menu            					= \App\Models\Menu::find($id);

		if (!isset($menu->id))
		{
			return response()->json(['error' => 'ID Não encontrado'], 404);
		}

		$menu->nome							= $request->nome;
		$menu->ordem 						= $request->ordem;
		$menu->parent_id 					= 1;
		$menu->icon							= $request->icon;

		if ($request->submenu == 1)
		{
			$menu->programa_id				= $request->programa_id;
			$menu->rota						= $request->rota;
			$menu->parent_id 				= $request->parent_id;
		}
		
		if (!$menu->save())
		{     
			return response()->json(['error' => 'Erro ao salvar'], 500);
		}

		return response()->json(['id' => $menu->id], 200);
	}

	public function destroy($id, Request $request)
    {	
		if (!$request->user()->tokenCan('delete.menus')) 
        {
            return response()->json(['error' => 'Não autorizado para excluir.'], 403);
        }
		
		$menu 									= \App\Models\Menu::find($id);
		
		if (!isset($menu->id)) 
		{
			return response()->json(['error' => 'Menu não encontrada'], 404);
		}

		$sql 									= "SELECT count(*) as quantidade FROM menus WHERE parent_id = '$id'";
		$menu									= DB::connection('mysql')->select($sql);

		if ($menu[0]->quantidade > 0)
		{
			return response()->json(['error' => "Menu não pode ser excluido. Existe {$menu[0]->quantidade} menus filhas relacionadas a ela."], 403);
		}

		if (!$menu->delete())
		{
			return response()->json(['error' => 'Erro ao deletar'], 400);
		}

		return response()->json(['id' => $id], 200);
	}
}
