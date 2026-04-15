<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Helpers\Cas;
use Carbon\Carbon;
use DB;
use stdClass;


class UsuarioController extends Controller
{

	public function index (Request $request)
    {
		if (!$request->user()->tokenCan('view.usuarios')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar listar usuários.'], 403);
        }

		$limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$conteudo            					= $request->input('conteudo', '');
       
		$usuarios	 								= DB::connection('mysql')
													->table('users')
													->select(	
																'users.id',
																'users.vendedor_id', 
																'users.perfil_id', 
																'perfis.nome as perfil',
																'users.name', 
																'users.email', 
																'users.created_at', 
															)
													->leftJoin('perfis','users.perfil_id','=','perfis.id')
													->where(function ($query) use ($conteudo) {
														if ($conteudo !== "") 
														{
															$query->orWhere('users.name', 'like', "%$conteudo%");
															$query->orWhere('users.email', 'like', "%$conteudo%");
														}
													})
													->orderBy($orderby,$direction)
													->paginate($limite);

		$usuarios->getCollection()->transform(function ($usuario) 
		{
			$usuario->pode_excluir 			= 'N';

			return $usuario;
		});
					
		return response()->json($usuarios, 200);
	}

	public function show ($id, Request $request)
    {
		if (!$request->user()->tokenCan('view.usuarios')) 
        {
            return response()->json(['error' => 'Não autorizado para alterar.'], 403);
        }

		$users 					= DB::connection('mysql')->table('users')
														->select(
															'users.id',
															'users.vendedor_id', 
															'users.perfil_id',
															'users.name', 
															'users.email',
															'users.clinica_id',
															'users.contrato_id',
														)
														->where('users.id', '=',$id)
														->first();

		if (!isset($users->id)) 
		{
			return response()->json(['error' => 'Usuario não encontrado'], 400);
		}

		return response()->json($users, 200);			
	}

	public function store (Request $request)
    {
		if (!$request->user()->tokenCan('edit.usuarios')) 
        {
            return response()->json(['error' => 'Não autorizado para inserir.'], 403);
        }

		$valid         	 = validator($request->only(
										'vendedor_id', 
										'perfil_id',
										'name', 
										'email',
										'password'
									),[
										'name'			=> 'required|max:100',
										'email' 		=> 'required|max:100',
										'vendedor_id'	=> 'nullable',
										'perfil_id'     => 'required|exists:perfis,id',
										'password'		=> 'required',
									]);

		if ($valid->fails())
		{
			return response()->json(['error' => Cas::getMessageValidTexto($valid->errors())], 400);
		}

		$usuario 							= \App\Models\User::where('email', '=', $request->email)->first();
										
		if (isset($usuario->id)) 
		{
			return response()->json(['error' => 'Login já existente'], 400);
		}
		
		if ((!isset($request->vendedor_id)) or (!is_numeric($request->vendedor_id)))
		{
			 $request->vendedor_id			= 0;
		}
		
		$user            					= new \App\Models\User();
		$user->vendedor_id					= $request->vendedor_id ?? 0;
		$user->perfil_id					= $request->perfil_id;
		$user->name							= $request->name;
		$user->email 						= $request->email;
		$user->password 					= Hash::make($request->password);
		
		if (isset($request->clinica_id))
		{
			$user->clinica_id 				= $request->clinica_id;
		}	

		if (isset($request->contrato_id))
		{
			$user->contrato_id 				= $request->contrato_id;
		}			

		if ($user->save())
		{     
			return response()->json(['id' => $user->id, 'ok' => true], 200);
		}

		return response()->json(['error' => 'Erro ao salvar'], 400);
	}

	public function update ($id, Request $request)
    {	
		if (!$request->user()->tokenCan('edit.usuarios')) 
        {
            return response()->json(['error' => 'Não autorizado para alterar.'], 403);
        }

		$valid         	 = validator($request->only(
										'vendedor_id',
										'perfil_id',										
										'name', 
										'email',
										'password'
									),[
										'name'			=> 'required|max:100',
										'email' 		=> 'required|max:100',
										'vendedor_id'	=> 'nullable',
										'perfil_id'     => 'nullable',
										'password'		=> 'nullable',
									]);

		if ($valid->fails())
		{
			return response()->json(['error' => Cas::getMessageValidTexto($valid->errors())], 400);
		}

		$usuario 							= \App\Models\User::where('email', '=', $request->email)
																	->where('id', '<>', $id)
																	->first();
										
		if (isset($usuario->id)) 
		{
			return response()->json(['error' => 'Login já existente'], 400);
		}
				
		$user            					= \App\Models\User::find($id);

		if (!isset($user->id)) 
		{
			return response()->json(['error' => 'Usuario nao encontrado'], 400);
		}

		if ((!isset($request->vendedor_id)) or (!is_numeric($request->vendedor_id)))
		{
			$request->vendedor_id			= 0;
		}
		
		if ((!isset($request->perfil_id)) or (!is_numeric($request->perfil_id)))
		{
			$request->perfil_id				= 0;
		}
		
		$user->vendedor_id					= $request->vendedor_id ?? 0;
		$user->perfil_id					= $request->perfil_id;
		$user->name							= $request->name;
		$user->email 						= $request->email;
		
		if (isset($request->clinica_id))
		{
			$user->clinica_id 				= $request->clinica_id;
		}	

		if (isset($request->contrato_id))
		{
			$user->contrato_id 				= $request->contrato_id;
		}			

		if (isset($request->password) && $request->password != '')
		{
			$user->password 					= Hash::make($request->password);
		}

		if ($user->save())
		{     
			return response()->json(['id' => $user->id, 'ok' => true], 200);
		}

		return response()->json(['error' => 'Erro ao salvar'], 400);
	}

	public function destroy($id, Request $request)
    {
		if (!$request->user()->tokenCan('delete.usuarios')) 
        {
            return response()->json(['error' => 'Não autorizado para excluir.'], 403);
        }

		$retorno 				= new stdClass;
	
		$user 					= \App\Models\User::find($id);
		
		if (!isset($user->id)) 
		{
			return response()->json(['error' => 'Usuário não encontrado'], 400);
		}

		if ($user->delete())
		{
			return response()->json(['id' => $id, 'ok' => true], 200);
		}

		return response()->json(['error' => 'Erro ao deletar'], 400);
	}
}
