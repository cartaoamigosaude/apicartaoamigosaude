<?php

namespace App\Http\Controllers;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Token;
use App\Helpers\Cas;
use DB;

class TokenController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.tokens')) {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

        $limite              				= $request->input('limite', 10);
		$orderby             				= $request->input('orderby', 'created_at');
		$direction          				= $request->input('direction', 'desc');
		$campo            					= $request->input('campo', 'token');
        $conteudo            				= $request->input('conteudo', '');

		$tokens 							= \App\Models\Token::query()
												// carrega o usuário se existir (user_id pode ser nulo; o belongsTo retorna null sem erro)
												->with(['user:id,name,email']) 
												->select('id','token','user_id','observacao','created_at','updated_at')
												->when(($campo !== '') && ($conteudo !== ''), function ($query) use ($campo, $conteudo) {
													$query->where($campo, 'like', "%{$conteudo}%");
												})
												->orderBy($orderby, $direction)
												->paginate($limite);

		// Locale para o diffForHumans (usa o do app; se vazio, cai em pt_BR)
		$locale = app()->getLocale() ?: 'pt_BR';

		$tokens->getCollection()->transform(function ($token) use ($locale) {
			// Campos humanos
			$token->criado 				= optional($token->created_at)->locale($locale)->diffForHumans();
			$token->usado  				= optional($token->updated_at)->locale($locale)->diffForHumans();
			return $token;
		});

		return response()->json($tokens, 200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.tokens')) {
            return response()->json(['error' => 'Não autorizado para criar situações.'], 403);
        }

		do {
			// Gera um número de 4 dígitos
			$token								 = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
			// Verifica se já existe no banco (token)
			$exists 							 = DB::table('cancelamento_tokens')->where('token', $token)->exists();
		} while ($exists); 
	
		$registro      			                = new \App\Models\Token();
		$registro->token 						= $token;
		$registro->user_id 						= 0;
		$registro->save();
		return response()->json(['success' => true, 'token'=> $token], 200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.tokens')) {
            return response()->json(['error' => 'Não autorizado para excluir situações.'], 403);
        }

        $Token 									= \App\Models\Token::find($id);

        if (!isset($Token->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }


        $Token->delete();
        return response()->json($id, 200);
    }
}
