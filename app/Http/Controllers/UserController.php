<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Helpers\Cas;
use DB;
use stdClass;

class UserController extends Controller
{

    public function show(Request $request)
    {
       
	   $user       =  \App\Models\User::select('id','name as nome','whatsapp as telefone','url_imagem as url','email', 'token_chat')->find($request->user()->id);

       if (!isset($user))
       {
		   $user 				= new stdClass();
		   $user->nome			= "";
		   $user->telefone 		= "";
		   $user->url			= "";
		   $user->email			= "";
		   $user->token_chat	= "";
	   }
	   $user->telefone 			= Cas::nulltoSpace($user->telefone);
	   $user->url				= Cas::nulltoSpace($user->url);
	   $user->token_chat		= Cas::nulltoSpace($user->token_chat);
	   
	   return $user;
		
    }
	
	public function update (Request $request)
    {
        
        $validator         	 			= validator($request->only(
												'nome', 
												'email',
												'telefone',
											),[
												'nome' 				=> 'required', 
												'email'	            => 'required',
												'telefone'			=> 'required',
											]);
                                                                        
        if ($validator->fails())
        {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

        $user                       	= \App\Models\User::find($request->user()->id);

        if (!isset($user->id)) 
        {
            return response()->json(['mensagem' => 'Usuário ou senha inválido'], 400);
        }

		DB::beginTransaction();
       
        $user->name                     = $request->nome; 
        $user->email                    = $request->email;
		$user->whatsapp				  	= $request->telefone; 
		
		if (isset($request->clinica_id))
		{
			$user->clinica_id 			= $request->clinica_id;
		}	

		if (isset($request->contrato_id))
		{
			$user->contrato_id 			= $request->contrato_id;
		}			
		
		if (isset($request->token_chat))
		{
			$user->token_chat			= $request->token_chat;
		}
		
		$caminho						="";
		
        if (isset($request->foto))
        {
            $file                       = $request->foto;

            $codigo 					= bin2hex(random_bytes(6));
            $folderName					= 'foto' . '/' . date("Y-m");
            $extension 			        = $file->getClientOriginalExtension() ?: 'jpeg';

            $fileName 					= $codigo . '-' . $file->getClientOriginalName() .'.'. $extension;

            $destinationPath 			= public_path() . '/' . $folderName;
            $file->move($destinationPath, $fileName);

            $caminho                    = url("/") . '/' . $folderName . '/' . $fileName;

            $user->url_imagem 			= $caminho;
        }
        
        if (!$user->save())
        {
			DB::rollBack();
            return response()->json(['mensagem' => 'Erro salvar'], 400);
        }

		DB::commit();
        return response()->json($caminho, 200);
    }

    public function update_foto (Request $request)
    {

		$user                       = \App\Models\User::find($request->user()->id);
 
        if (!isset($user->id)) 
        {
            return response()->json(['mensagem' => 'Usuário ou senha inválido'], 400);
        }

		DB::beginTransaction();
		
        $file                       = $request->foto;

        $codigo 					= bin2hex(random_bytes(6));
        $folderName					= 'foto' . '/' . date("Y-m");
        $extension 			        = $file->getClientOriginalExtension() ?: 'jpeg';
        $fileName 					= $codigo . '-' . $file->getClientOriginalName() .'.'. $extension;

        $destinationPath 			= public_path() . '/' . $folderName;
        $file->move($destinationPath, $fileName);

        $caminho                    = url("/") . '/' . $folderName . '/' . $fileName;
        $user->url_imagem 			= $caminho;
        
        if (!$user->save())
        {
			DB::rollBack();
            return response()->json(['mensagem' => 'Erro salvar'], 400);
        }

		DB::commit();
        return response()->json($caminho, 200);
    }
	
	public function update_senha (Request $request)
    {
        if ($request->senhaNova != $request->senhaNovaValid)
        {
            return response()->json(['mensagem' => 'As senhas novas precisam ser identicas'], 400);
        }

       $user                       	= \App\Models\User::find($request->user()->id);

        if (!isset($user->id)) 
        {
            return response()->json(['mensagem' => 'Usuário ou senha inválido'], 400);
        }

        if (!Hash::check($request->senhaAtual, $user->password)) 
        {
            return response()->json(['mensagem' => 'Senha atual incorreta'], 400);
        }

        $user->password         = Hash::make($request->senhaNova);

        if (!$user->save())
        {
            return response()->json(['mensagem' => 'Erro ao salvar senha'], 400);
        }

        return response()->json(['mensagem' => 'Senha atualizada com sucesso.'], 200);
    }
	
	public function getMenu(Request $request)
	{
		$sql 					= "SELECT menus.rota, menus.nome, programas.nome as programa
									FROM menus JOIN programas on menus.programa_id = programas.id
									WHERE menus.programa_id IS NOT NULL AND menus.rota IS NOT NULL 
									ORDER BY menus.ordem";
		$menus					= DB::connection('mysql')->select($sql);

		return response()->json($menus, 200);
	}
	
	public function menu(Request $request)
	{
			
			//if (!Cache::has('menu')) 
			//{
				Cas::limparCacheMenu();
			//}

			$query 					= DB::connection('mysql')
										->table('menus')
										->select(	
											'id',
											'parent_id', 
											'rota',
											'nome',
											'icon',
											'programa_id',
										);
			
			$query->orderBy('ordem', 'asc');
			$menus 					= $query->get();
			$todos_menus			= [];

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
					$todos_menus[$menu->id] 				= $menu;
				}
			}
			
			$refs 											= [];
			$tree 											= [];

			foreach ($todos_menus as $menu) 
			{
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
}