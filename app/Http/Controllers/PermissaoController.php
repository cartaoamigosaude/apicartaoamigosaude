<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Helpers\Cas;
use DB;
use stdClass;


class PermissaoController extends Controller
{

	public function index_perfil (Request $request)
    {
		if (!$request->user()->tokenCan('view.permissoes')) 
        {
            return response()->json(['error' => 'Não autorizado para listar.'], 403);
        }
		
		$orderby            = $request->input('orderby', 'perfis.nome');
		$direction          = $request->input('direction', 'asc');
		$conteudo           = $request->input('conteudo', '');
		$limite             = $request->input('limite', 100);

		$perfis   			= DB::connection('mysql')->table('perfis')
														->select('id','nome')
														->where('id','>',1)
														->where(function ($perfis) use ($conteudo) {
															if ($conteudo !== "") {
																$perfis->orWhere('id',  	'like', "%$conteudo%")
																	   ->orWhere('nome',  	'like', "%$conteudo%");
															}
														})
														->orderBy($orderby, $direction)
														->paginate($limite);
	
		return response()->json($perfis, 200);
	}
	
	public function treeview(Request $request)
    {
		if (!$request->user()->tokenCan('view.permissoes')) 
        {
            return response()->json(['error' => 'Não autorizado para listar.'], 403);
        }

        $orderby 					= $request->input('orderby', 'menus.nome');
        $direction 					= $request->input('direction', 'asc');
        $conteudo	 				= $request->input('conteudo', "");

		if (!Cache::has('menu')) 
		{
			Cas::limparCacheMenu();
		}

		$perfil_id					= $request->perfil_id;

		$query 						= DB::connection('mysql')
										->table('menus')
										->select('id','parent_id', 'rota','ordem','nome','programa_id')
										->where('id', '>=', '1');

		$query->where(function ($query) use ($conteudo)
		{
			if ($conteudo !== "") 
			{
				$query->orWhere('nome',	  		'like', "%$conteudo%")
					  ->orwhere('id',			'like', "%$conteudo%");
			}
		});
		
		$query->orderBy('ordem', 'asc');
		$menus 					= $query->get();
		$todos_menus			= [];
		//return response()->json($menus, 200);

		foreach ($menus as $menu) 
		{
			// VERIFICAR SE O MENU PAI JA FOI ENCONTRADA
			$pai_id 				= $menu->parent_id;

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
			$menu->programa								= '';

			if ((!is_null($menu->programa_id)) and ($menu->programa_id != 0))
			{
				if((!isset($programas[$menu->programa_id])))
				{
					$programas[$menu->programa_id] 	= '';

					$sql 						= "SELECT nome FROM programas WHERE id = '$menu->programa_id'";
					$programa					= DB::connection('mysql')->select($sql);

					if (isset($programa[0]->nome))
					{
						$programas[$menu->programa_id] = $programa[0]->nome;
					}
				}

				$menu->programa					= $programas[$menu->programa_id];
			}

			$menu->ativo						= 'N';
			$sql 								= "SELECT perfil_id FROM perfil_menu_opcao WHERE menu_id = $menu->id AND perfil_id = $perfil_id";
			$ativo								= DB::connection('mysql')->select($sql);

			if (isset($ativo[0]->perfil_id))
			{
				$menu->ativo					= 'S';
			}

			$menu_array 						= (array) $menu;
			$menu_array['children'] 			= [];
			$refs[$menu->id] 					= $menu_array;
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
	
	public function menu_store(Request $request)
	{
		if (!$request->user()->tokenCan('view.permissoes')) 
        {
            return response()->json(['error' => 'Não autorizado para alterar.'], 403);
        }
		
		$validator         	 = validator($request->only(
			'menu_id',
			'parent_id',
			'perfil_id', 
			'habilitar',
		),[
			'menu_id'			=> 'required|exists:menus,id',
			'parent_id'			=> 'nullable|required|exists:menus,id',
			'perfil_id'			=> 'required|exists:perfis,id',
			'habilitar'			=> 'required|in:"S","N"',
		]);
		
		if ($validator->fails())
		{
			return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
		}

		$id_listar 				= Cas::obterIdPermissaoListar();

		if ($request->habilitar == 'N')
		{
			if ($request->menu_id == 1)
			{
				//TODOS OS MENUS
				$delete 			= DB::connection('mysql')
										->table('perfil_menu_opcao')
										->where('perfil_id','=', $request->perfil_id)
										->where('menu_id',	'>=', 1)
										->delete();
			}

			if ($request->parent_id == 1)
			{
				//FILHOS DOS MENUS
				$filhos				= Cas::obterFilhasMenu($request->menu_id);

				$delete 			= DB::connection('mysql')
										->table('perfil_menu_opcao')
										->where('perfil_id',	'=', $request->perfil_id)
										->whereIn('menu_id', $filhos)
										->delete();
			}

			if (($request->menu_id != 1) and ($request->parent_id != 1))
			{
				//MENU ESPECIFICO
				$delete 			= DB::connection('mysql')
										->table('perfil_menu_opcao')
										->where('menu_id',		'=', $request->menu_id)
										->where('perfil_id',		'=', $request->perfil_id)
										->delete();
			}

			if ($delete == 0)
			{
				return response()->json(['mensagem' => 'Falha ao desabilitar permisssão.'], 400);
			}

			return response()->json(['mensagem' => 'Sucesso ao desabilitar permisssão.'], 200);
		} 

		if (($request->menu_id == 1) || ($request->parent_id == 1))
		{
			$filhos				= Cas::obterFilhasMenu($request->menu_id);

			$delete 			= DB::connection('mysql')
									->table('perfil_menu_opcao')
									->where('perfil_id',		'=', $request->perfil_id)
									->where('opcao_id',	'=', $id_listar)
									->whereIn('menu_id', $filhos)
									->delete();

			foreach ($filhos as $filho)
			{
				$insert = DB::connection('mysql')
						->table('perfil_menu_opcao')
						->insert([
							'menu_id'      	=> $filho,
							'perfil_id'     => $request->perfil_id,
							'opcao_id' 		=> $id_listar,
							'pode' 		   	=> 1,
							'updated_at'	=> date('Y-m-d H:m:s'),
							'created_at'	=> date('Y-m-d H:m:s'),
						]);
			}
		}

		if (($request->menu_id != 1) and ($request->parent_id != 1))
		{
			$insert = DB::connection('mysql')
						->table('perfil_menu_opcao')
						->insert([
							'menu_id'      	=> $request->menu_id,
							'perfil_id'     => $request->perfil_id,
							'opcao_id' 		=> $id_listar,
							'pode' 		   	=> 1,
							'updated_at'	=> date('Y-m-d H:m:s'),
							'created_at'	=> date('Y-m-d H:m:s'),
						]);
		}

		if (!$insert)
		{
			return response()->json(['mensagem' => 'Falha ao habilitar permisssão.'], 400);
		}

		return response()->json(['mensagem' => 'Sucesso ao habilitar permisssão.'], 200);
		
	}

	public function permissao_index (Request $request)
    {
		if (!$request->user()->tokenCan('view.permissoes')) 
        {
            return response()->json(['error' => 'Não autorizado para listar.'], 403);
        }

		$limite              					= $request->input('limite', 100);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$conteudo            					= $request->input('conteudo', '');
       
		$permissoes 							= DB::connection('mysql')
														->table('opcoes')
														->select('id','nome')
														->where(function ($query) use ($conteudo) {
															if ($conteudo !== "") 
															{
																$query->where('nome', 'like', "%$conteudo%");
															}
														})
														->where('ativo',  '=', '1')
														->orderBy($orderby,$direction)
														->paginate($limite);

		$permissoes->getCollection()->transform(function ($permissao) use ($request)
		{

			$permissao->ativo					= 'N';
			$sql 								= "SELECT perfil_id FROM perfil_menu_opcao WHERE menu_id = $request->menu_id AND perfil_id = $request->perfil_id AND opcao_id = $permissao->id";
			$ativo								= DB::connection('mysql')->select($sql);

			if (isset($ativo[0]->perfil_id))
			{
				$permissao->ativo				= 'S';
			}

			return $permissao;
		});
					
		return response()->json($permissoes, 200);
	}
	
	public function permissao_store(Request $request)
    {
		if (!$request->user()->tokenCan('edit.permissoes')) 
        {
            return response()->json(['error' => 'Não autorizado para alterar.'], 403);
        }

		$validator         	 = validator($request->only(
			'menu_id',
			'parent_id',
			'perfil_id', 
			'opcao_id',
			'habilitar',
		),[
			'menu_id'			=> 'required|exists:menus,id',
			'parent_id'			=> 'nullable|required|exists:menus,id',
			'perfil_id'			=> 'required|exists:perfis,id',
			'opcao_id'			=> 'required|exists:opcoes,id',
			'habilitar'			=> 'required|in:"S","N"',
		]);
		
		if ($validator->fails())
		{
			return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
		}

		//ARMAZENA E MANDA PARA OS MENUS
		$modificados			= array();

		//DESABILITAR
		if ($request->habilitar == 'N')
		{
			//AFETA TODOS OS MENUS
			if ($request->menu_id == 1)
			{
				$delete 			= DB::connection('mysql')
										->table('perfil_menu_opcao')
										->where('perfil_id','=', $request->perfil_id)
										->where('opcao_id',	'=', $request->opcao_id)
										->where('menu_id',	'>=', 1)
										->delete();

				$sql 				= "SELECT id as menu_id FROM menus WHERE id >= 1";
				$menus				= DB::connection('mysql')->select($sql);
				
				$menus				= array_column($menus, 'menu_id');

				//VERIFICAR SE TEM REGISTROS ATIVOS
				foreach ($menus as $menu)
				{
					$status			= "S";

					if (Cas::verificadorPermissoes($menu, $request->perfil_id, $request->opcao_id) == 0)
					{
						$status		= "N";
					}
					
					$modificados[]	= ['status' => $status, 'menu_id' => $menu];
				}
			}

			//AFETA TODOS OS FILHOS
			if ($request->parent_id == 1)
			{
				//FILHOS DOS MENUS
				$sql 				= "SELECT id as menu_id FROM menus WHERE parent_id = $request->menu_id";
				$filhos				= DB::connection('mysql')->select($sql);
				$filhos				= array_column($filhos, 'menu_id');
				$filhos[]			= $request->menu_id;

				$delete 			= DB::connection('mysql')
										->table('perfil_menu_opcao')
										->where('perfil_id','=', $request->perfil_id)
										->where('opcao_id',	'=', $request->opcao_id)
										->whereIn('menu_id', $filhos)
										->delete();

				//VERIFICAR SE TEM REGISTROS ATIVOS
				foreach ($filhos as $filho)
				{
					$status			= "S";

					if (Cas::verificadorPermissoes($filho, $request->perfil_id, $request->opcao_id) == 0)
					{
						$status		= "N";
					}
					
					$modificados[]	= ['status' => $status, 'menu_id' => $filho];
				}
			}

			//MENU ESPECIFICO
			if (($request->menu_id != 1) and ($request->parent_id != 1))
			{
				$delete 			= DB::connection('mysql')
										->table('perfil_menu_opcao')
										->where('menu_id',	'=', $request->menu_id)
										->where('perfil_id','=', $request->perfil_id)
										->where('opcao_id',	'=', $request->opcao_id)
										->delete();

				$status				= "S";

				if (Cas::verificadorPermissoes($request->menu_id, $request->perfil_id, $request->opcao_id) == 0)
				{
					$status		= "N";
				}
				
				$modificados[]	= ['status' => $status, 'menu_id' => $request->menu_id];
			}

			if ($delete == 0)
			{
				return response()->json(['mensagem' => 'Falha ao desabilitar permisssão.'], 400);
			}

			return response()->json(['mensagem' => 'Sucesso ao desabilitar permisssão.', 'menus_alterados' => $modificados], 200);
		}

		//HABILITAR PERMISSAO
		$opcao_id			= Cas::obterIdPermissaoListar();

		//AFETA TODOS OS MENUS ABAIXO
		if (($request->menu_id == 1) || ($request->parent_id == 1))
		{
			$filhos				= Cas::obterFilhasMenu($request->menu_id);

			//EXCLUI PARA TER CERTEZA QUE NAO DARA ERRO NO CODIGO
			$delete 			= DB::connection('mysql')
									->table('perfil_menu_opcao')
									->where('perfil_id','=', $request->perfil_id)
									->where('opcao_id',	'=', $request->opcao_id)
									->whereIn('menu_id', $filhos)
									->delete();

			foreach ($filhos as $filho)
			{
				//INSERE UMA POR UMA
				$insert = DB::connection('mysql')
						->table('perfil_menu_opcao')
						->insert([
							'menu_id'   => $filho,
							'perfil_id' => $request->perfil_id,
							'opcao_id' 	=> $request->opcao_id,
							'pode' 		=> 1,
							'updated_at'=> date('Y-m-d H:m:s'),
							'created_at'=> date('Y-m-d H:m:s'),
						]);

				$modificados[]	= ['status' => 'S', 'menu_id' => $filho];

				//INSERE O LISTAR SE TIVER DESABILITADO
				Cas::inserirListarMenu($filho, $request->perfil_id, $request->opcao_id);
			}
		}

		if (($request->menu_id != 1) and ($request->parent_id != 1))
		{
			$insert = DB::connection('mysql')
						->table('perfil_menu_opcao')
						->insert([
							'menu_id'   => $request->menu_id,
							'perfil_id' => $request->perfil_id,
							'opcao_id' 	=> $request->opcao_id,
							'pode' 		=> 1,
							'updated_at'=> date('Y-m-d H:m:s'),
							'created_at'=> date('Y-m-d H:m:s'),
						]);

			$modificados[]	= ['status' => 'S', 'menu_id' => $request->menu_id];

			//INSERE O LISTAR SE TIVER DESABILITADO
			Cas::inserirListarMenu($request->menu_id, $request->perfil_id, $request->opcao_id);
		}

		if (!$insert)
		{
			return response()->json(['mensagem' => 'Falha ao habilitar permisssão.'], 400);
		}

		return response()->json(['mensagem' => 'Sucesso ao habilitar permisssão.', 'menus_alterados' => $modificados, 'opcao_id_lista' => $opcao_id], 200);
	}
}
