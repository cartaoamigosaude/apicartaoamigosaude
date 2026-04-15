<?php

    namespace App\Http\Controllers;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Hash;
    use Laravel\Passport\Client;
    use Illuminate\Validation\ValidationException;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Str;
    use Illuminate\Support\Facades\Cache;

    use Illuminate\Support\Facades\Log;
	use App\Helpers\Cas;
    use stdClass;

    class LoginController extends Controller
    {

		public function login(Request $request)
		{
        
			$user                       = \App\Models\User::where('email', $request->username)->first();

			if (!isset($user->id)) 
			{
				return response()->json(['mensagem' => 'Usuário não encontrado'], 400);
			}
			
			$getaccess                     = Cas::obterEscopos($user);

			if (count($getaccess->escopos) ==0)
			{
				return response()->json(['mensagem' => 'Usuário nao tem autorizaçao'],403);
			}

			$token                  	= Cas::oauthToken($request->username,$request->password,$getaccess->escopos);

			if (!isset($token['access_token']))
			{
				//return response()->json($token, 400);
				return response()->json(['mensagem' => 'Usuário ou senha inválida'], 400);
			}

			$retorno                	= new stdClass();
			$retorno->perfil        	= $user->perfil_id;
			$retorno->nome          	= $user->name;
			$retorno->foto          	= $user->url_imagem;
			$retorno->token_chat        = Cas::nulltoSpace($user->token_chat);
			$retorno->refresh_token 	= $token['refresh_token'] ?? null;
			$retorno->token         	= $token['access_token'] ?? null; 
			$retorno->permissoes    	= $getaccess->escopos;
			$retorno->acessos    		= $getaccess->acessos;
			return response()->json($retorno, 200);
		}
		
	}