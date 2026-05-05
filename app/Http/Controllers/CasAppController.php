<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use App\Helpers\Cas;
use App\Helpers\ClubeCerto;
use App\Helpers\Conexa;
use App\Helpers\CelCash;
use App\Helpers\Ip2Location;
use App\Helpers\BrasilApi;
use App\Helpers\Kolmeya;
use App\Helpers\ChatHot;
use stdClass;
use DB;

/*  */
class CasAppController extends Controller
{

	public function esqueci_minha_senha(Request $request)
	{
		$retorno 	                        				= new stdClass;
		$retorno->reset_token								= "";
		$retorno->phone_mask								= "";
		
		$cpf												= preg_replace('/[^0-9]/', '', $request->cpf);
		$cpf 												= str_pad($cpf, 11, "0", STR_PAD_LEFT);
		
		$cliente                    						= \App\Models\Cliente::where('cpfcnpj','=',$cpf)->first();
		
		if (!isset($cliente->id)) 
		{
			$retorno->message								= "CPF inválido ou não encontrado";
			return response()->json($retorno, 404);
		}
		
		$beneficiario            							= \App\Models\Beneficiario::with('contrato','cliente')
																					 ->where('cliente_id','=',$cliente->id)
																					 ->where('ativo','=',1)
																					 ->first();
													  
		if (!isset($beneficiario->id)) 
		{
			$retorno->message								= "Não existe nenhum contrato. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404";
			return response()->json($retorno, 404);
		}
		
		$username											= $cpf . "@cartaoamigosaude.com.br";

		$user                       						= \App\Models\User::where('email', $username)->first();

		if (!isset($user->id)) 
		{
			$retorno->message								= "Não existe nenhum usuário com este cpf. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404";
			return response()->json($retorno, 404);
		}
		
		// (Opcional) evita spam por CPF: 1 reset ativo por vez
        // Se quiser permitir múltiplos, remova esse lock.
        $userLockKey 										= 'pwdreset:userlock:'.$cpf;
        if (Cache::has($userLockKey)) 
		{
            // já tem reset recente em andamento: não cria outro
            // ainda assim devolvemos null ou um reset existente (se quiser)
            // aqui vou só negar criação e deixar o controller responder genérico.
		   //$retorno->message								= "Em andamento";
           //return response()->json($retorno, 404);
        }
		
		$otp 												= str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
		
		$sms              									= [
			[
				"phone"     => intval(Kolmeya::tratarTelefone($cliente->telefone)), 
				"message"   => "Seu token para recuperar senha: " . $otp,
				"reference" => $cpf
			]
		];
		
		$sendsms 											= Kolmeya::sendSMS($user->id,$sms);
		
		if ($sendsms->error)
		{
			$retorno->message								= "Envio de SMS indisponível. Tente um pouco mais tarde. Se continuar indisponível, entre em contato com o Cartão no Whatsapp: (19) 98951-240";
			return response()->json($retorno, 404);
		}
		
		Log::info("sms", ['sms'	=> $sms]);
		Log::info("sendsms", ['sendsms'	=> $sendsms]);
		
		$telefone											 = preg_replace('/\D/', '', $cliente->telefone);
		// Mantém apenas os 4 últimos dígitos
		$final 												= substr($telefone, -4);
		// Celular ou fixo (não importa o tamanho)
		$telefone 											= "(**) *****-" . $final;
		$retorno->phone_mask								= $telefone . " | ". $otp;
		$retorno->reset_token 								= bin2hex(random_bytes(32));
		$expiresAt 											= CarbonImmutable::now()->addMinutes(10);
		
		// otp_hash: Hash::make (bcrypt/argon2i/argon2id conforme config)
       $payload = [
            'user_id'     									=> $user->id,
            'otp_hash'     									=> Hash::make($otp),
            'attempts'     									=> 0,
            'max_attempts' 									=> 5,
            'expires_at'   									=> $expiresAt->toIso8601String(),
            'verified_at'  									=> null,
            'exchange_token' 								=> null,
        ];
		
		// grava reset por token
        Cache::put('pwdreset:reset:'.$retorno->reset_token, $payload, $expiresAt);

        // marca CPF em "cooldown" (mesmo TTL do reset) para reduzir spam
        Cache::put($userLockKey, true, $expiresAt);
		
		return response()->json($retorno, 200);
	}
	
	
	public function recuperar_minha_senha(Request $request)
	{
		
		$validator = Validator::make($request->all(), [
            'reset_token' 		=> 'required',
            'otp' 				=> 'required|digits:6',
        ]);

        if ($validator->fails()) 
		{
            return response()->json(['message' => Cas::getMessageValidTexto($validator->errors())], 404);
        }
		
		$key 												= 'pwdreset:reset:'.$request->reset_token;
		
		$lock 												= Cache::lock("lock:{$key}", 5);
		$otp 												= $request->otp; 
		
        $exchangeToken = $lock->block(3, function () use ($key, $otp) 
		{

            $data 											= Cache::get($key);
            if (!$data) {
                return null; // expirou/inexistente
            }

            // valida expiração (segurança extra, TTL já cobre)
            $expiresAt 										= CarbonImmutable::parse($data['expires_at']);
            if ($expiresAt->isPast()) 
			{
                Cache::forget($key);
                return null;
            }

            // tenta limite
            $attempts 										= (int) ($data['attempts'] ?? 0);
            $max 											= (int) ($data['max_attempts'] ?? 5);
            if ($attempts >= $max) {
                Cache::forget($key);
                return null;
            }

            // incrementa tentativa sempre (mesmo se errar)
            $attempts++;
            $data['attempts'] 								= $attempts;

            $ok 											= Hash::check($otp, $data['otp_hash']);

            if (!$ok) {
                // persiste tentativa e mantém TTL original
                Cache::put($key, $data, $expiresAt);
                return null;
            }

            // ok: marca verified_at e cria exchange_token
            $verifiedAt 									= CarbonImmutable::now();
            $exchangeToken 									= bin2hex(random_bytes(64)); // 128 chars

            $data['verified_at'] 							= $verifiedAt->toIso8601String();
            $data['exchange_token'] 						= $exchangeToken;

            // Mantém o reset vivo até expirar (ou você pode encurtar)
            Cache::put($key, $data, $expiresAt);

            // Cria índice reverso exchange_token -> reset_token (TTL curto)
            $exExpiresAt 									= $verifiedAt->addMinutes(10);
            Cache::put('pwdreset:exchange:'.$exchangeToken, [
                'reset_token' => Cas::stripPrefix($key, 'pwdreset:reset:'),
                'user_id'     => $data['user_id'],
                'verified_at' => $data['verified_at'],
            ], $exExpiresAt);

            return $exchangeToken;
        });
		
		$retorno 	                        				= new stdClass;
		if ($exchangeToken === null) 
		{
		   $retorno->message								= "Token expirou ou não existe";
           return response()->json($retorno, 410);
        }

		$retorno->reset_token								= $request->reset_token;
		$retorno->exchange_token							= $exchangeToken;								
		$retorno->otp										= $request->otp;
		return response()->json($retorno, 200);
	}
	
	public function alterar_minha_senha(Request $request)
	{
		$validator = Validator::make($request->all(), [
            'exchange_token' 	=> 'required',
            'nova_senha' 		=> 'required|min:8',
        ]);

        if ($validator->fails()) 
		{
            return response()->json(['message' => Cas::getMessageValidTexto($validator->errors())], 404);
        }
		
		$exchangeToken			= $request->exchange_token;
		$newPassword			= $request->nova_senha;
		
		$exKey 					= 'pwdreset:exchange:'.$exchangeToken;
		 
		$lock 					= Cache::lock("lock:{$exKey}", 5);
		 
		$senha                 = $lock->block(3, function () use ($exKey, $exchangeToken, $newPassword) {

            $ex 				= Cache::get($exKey);
            if (!$ex) {
                return false;
            }

            $user 				= \App\Models\User::find($ex['user_id']);
            if (!$user) {
                Cache::forget($exKey);
                return false;
            }

            // atualiza senha
            $user->password 	= Hash::make($newPassword);
            $user->save();

            // consome tokens do fluxo (one-time)
            Cache::forget($exKey);

            // opcional: remove também o reset_token associado
            if (!empty($ex['reset_token'])) {
                Cache::forget('pwdreset:reset:'.$ex['reset_token']);
            }

            return true;
        });
		
		if (!$senha)
		{
			return response()->json(['message' => 'Usuário não encontrado'], 404);
		}
		
		return response()->json($senha, 200);
		
	}
	
    public function login(Request $request)
	{
		
		$validator = Validator::make($request->all(), [
            'cpf' 				=> 'required',
            'senha' 			=> 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 404);
        }

		$request->cpf			= preg_replace('/[^0-9]/', '', $request->cpf);
		$request->cpf 			= str_pad($request->cpf, 11, "0", STR_PAD_LEFT);	
		
		$cliente                 = \App\Models\Cliente::select('id','nome','user_id','data_nascimento')
												 	  ->where('cpfcnpj','=',$request->cpf)
													  ->where('tipo','=','F')
													  ->first();
													  
		if (!isset($cliente->id)) 
		{
            return response()->json(['mensagem' => 'CPF não encontrado. Se voce é dependente, solicite para o titular o seu cadastro.'], 404);
        }											  
		
		$beneficiario 										= \App\Models\Beneficiario::with('contrato')
																		->where('cliente_id', '=', $cliente->id)
																		->where('ativo', '=', 1)
																		->whereHas('contrato', function ($query) {
																			$query->whereIn('status', array('active','waitingPayment'));
																		})
																		->first();
		if (!isset($beneficiario->id)) 
		{
            return response()->json(['mensagem' => 'Não existe nenhum contrato ativo com o seu CPF'], 404);
        }		
		
		if ((!isset($beneficiario->contrato->status)) or (($beneficiario->contrato->status !='active') and ($beneficiario->contrato->status !='waitingPayment')))
		{
			return response()->json(['mensagem' => 'Não existe nenhum contrato ativo com o seu CPF'], 404);
		}
		
		if (($beneficiario->tipo == 'D') and ($beneficiario->acessoapp ==0))
		{
			return response()->json(['mensagem' => 'Solicite para o titular o seu cadastro'], 404);
		}

		$username											= $request->cpf . "@cartaoamigosaude.com.br";

		$user                       						= \App\Models\User::where('email', $username)->first();

		if (!isset($user->id)) 
		{
			$request->senha									= preg_replace('/[^0-9]/', '', $request->senha);
			$request->senha									= str_pad($request->senha, 11, "0", STR_PAD_LEFT);	
			
			if ($request->senha != $request->cpf)
			{
				return response()->json(['mensagem' => 'Olá seja bem-vindo! No primeiro acesso é necessário informar o seu CPF e na senha repetir o seu CPF.'], 404);
			}	
			$user            								= new \App\Models\User();
			$user->vendedor_id								= 0;
			$user->perfil_id								= 0;
			$user->name										= $cliente->nome;
			$user->email 									= $username;
			$user->url_imagem								= "";
			$user->password 								= Hash::make($request->senha);
			$user->save();
		}

		$escopos 											= array();
		$escopos[]											= 'view.beneficiarios';
        $escopos[]											= 'edit.beneficiarios';
		
		$token                  							= Cas::oauthToken($username,$request->senha,$escopos);

		if (!isset($token['access_token']))
		{
			return response()->json(['mensagem' => 'A senha informada não está correta. Tente recuperar a sua senha.'], 404);
		}

		if (Cas::nulltoSpace($cliente->email) == "")
		{
			$cliente->email									= 'email@meu.com.br';
		}
		if (Cas::nulltoSpace($user->url_imagem) == "")
		{
			$user->url_imagem								= 'https://images.unsplash.com/photo-1531123414780-f74242c2b052?ixlib=rb-1.2.1&ixid=MnwxMjA3fDB8MHxzZWFyY2h8NDV8fHByb2ZpbGV8ZW58MHx8MHx8&auto=format&fit=crop&w=900&q=60';
		}

		$usuario 	                        				= new stdClass;
		$usuario->id 										= $cliente->id;
		$usuario->cpf 										= $cliente->cpfcnpj;
		$usuario->nome 										= $cliente->nome;
		$usuario->email										= $cliente->email;
		$usuario->image 	            					= $user->url_imagem;
		
		$auth 	                        					= new stdClass;
		$auth->token  	            						= $token['access_token'];
		$auth->refresh_token 								= $token['refresh_token'];
		$auth->expires_in 									= $token['expires_in'];
		$auth->token_type 									= $token['token_type'];
		
		if (isset($token['expires_in'])) 
		{
			$currentTimestamp 								= time(); // Timestamp atual
			$expiresTimestamp 								= $currentTimestamp + $token['expires_in']; // Soma o tempo de expiração
			//$usuario->expiresin 							= date('Y-m-d H:i:s', $expiresTimestamp);
			$auth->expiresin 								= $expiresTimestamp;
		}
		
		$cliente                    						= \App\Models\Cliente::find($beneficiario->contrato->cliente_id);
		
		$empresa 	                       					= new stdClass;
		
		$empresa->nome_empresa								= "Cartão Amigo Saúde";
		$empresa->url_logo 									= "https://storage.googleapis.com/flutterflow-io-6f20.appspot.com/projects/cartao-amigo-saude-otl9zt/assets/118ppay92t3o/icone.png";
		$empresa->cor_primaria 								= "#6f61ef";
		$empresa->cor_secundaria 							= "#ee8b60";
		
		if ((isset($cliente->id)) and ($cliente->tipo =='J'))
		{
			if ((Cas::nulltoSpace($cliente->url_logo) !="") and (Cas::nulltoSpace($cliente->cor_primaria) !="") and (Cas::nulltoSpace($cliente->cor_secundaria) !=""))
			{
				$empresa->nome_empresa						= $cliente->nome;
				$empresa->cor_primaria 						= $cliente->cor_primaria;
				$empresa->cor_secundaria 					= $cliente->cor_secundaria;
				$empresa->url_logo 							= $cliente->url_logo;
			}
		}
		
		$retorno 	                        				= new stdClass;
		$retorno->auth										= $auth;
		$retorno->user 										= $usuario;
		$retorno->config									= $empresa;
													
		return response()->json($retorno, 200);
		
	}
	
	public function ativarBeneficio(Request $request, $id)
	{
		$payload 	                        				= new stdClass;
		$payload->beneficiario_id							= $id;
		$payload->ativar									= true;
		$payload->produtos 									= array();
		
		$produto 	                        				= new stdClass;
		$produto->id 										= $request->produto_id;
		$payload->produtos[]								= $produto;
		
		$ativar 											= Cas::ativarDesativarProdutos($payload);
		if (isset(($ativar[0]->ok)) and ($ativar[0]->ok == 'S'))
		{
			return response()->json(true, 200);
		}
		
		return response()->json($ativar, 404);
	}
	
	
	public function view_empresa(Request $request, $id)
	{
		
		$retorno 	                        				= new stdClass;
		$retorno->name 										= "";
		$retorno->cnpj 										= "";
		$retorno->address									= "";
		$retorno->phone 									= "";
		$retorno->email 									= ""; 
		$retorno->url 										= "";
		
		$cliente_id											= 16118;
		
		$beneficiario 										= \App\Models\Beneficiario::with('contrato')->find($id);
		
		if (!isset($beneficiario->id)) 
		{
			return response()->json($retorno, 200);
		}
		
		$cliente                    						= \App\Models\Cliente::find($beneficiario->contrato->cliente_id);
		
		if ((!isset($cliente->id)) or ($cliente->tipo !='J'))
		{
			$cliente                    					= \App\Models\Cliente::find($cliente_id);
		}
		
		if (!isset($cliente->id)) 
		{
			$retorno->url 										= "2";
			return response()->json($retorno, 200);
		}		
		
		$partesEndereco = [];

				// Logradouro + número + complemento
		$linha1 = trim(
					$cliente->logradouro . ', ' . 
					$cliente->numero . 
					(!empty($cliente->complemento) ? ' ' . $cliente->complemento : '')
				);

		if (!empty($linha1)) 
		{
			$partesEndereco[] = $linha1;
		}

		// Bairro
		if (!empty($cliente->bairro)) 
		{
			$partesEndereco[] = $cliente->bairro;
		}

		// Cidade / Estado
		$cidadeEstado = trim(
			$cliente->cidade . 
			(!empty($cliente->estado) ? '/' . $cliente->estado : '')
		);

		if (!empty($cidadeEstado)) 
		{
			$partesEndereco[] = $cidadeEstado;
		}

		// CEP (formatado)
		$cepFormatado = null;
		if (!empty($cliente->cep)) 
		{
			$cep = preg_replace('/\D/', '', $cliente->cep);
			if (strlen($cep) === 8) 
			{
				$cepFormatado = substr($cep, 0, 5) . '-' . substr($cep, 5);
			}
		}

		if ($cepFormatado) 
		{
			$partesEndereco[] = 'CEP ' . $cepFormatado;
		}
		
		$telefone = preg_replace('/\D/', '', $cliente->telefone);

		// Celular (11 dígitos)
		if (strlen($telefone) === 11) 
		{
			$telefone =  sprintf(
					'(%s) %s-%s',
					substr($telefone, 0, 2),
					substr($telefone, 2, 5),
					substr($telefone, 7)
				);
		}

		// Fixo (10 dígitos)
		if (strlen($telefone) === 10) 
		{
			$telefone = sprintf(
					'(%s) %s-%s',
					substr($telefone, 0, 2),
					substr($telefone, 2, 4),
					substr($telefone, 6)
				);
			}
	
		$retorno->name 								= $cliente->nome;
		$retorno->cnpj 								= $cliente->cpfcnpj;
		$retorno->address							= implode(' – ', $partesEndereco);
		$retorno->phone 							= $telefone;
		$retorno->email 							= $cliente->email; 
		$retorno->url 								= $cliente->url_logo;
		
		return response()->json($retorno, 200);
	}
	
	public function alterar_senha(Request $request, $id)
	{

		$validator = Validator::make($request->all(), [
            'senha' 			=> 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 404);
        }

        $rcliente              								= \App\Models\Cliente::find($id);
        
        if (!isset($rcliente->id))
        {
            return response()->json(['mensagem' => 'Cliente não encontrado.'], 404);
        }

	
		$cpf         										= preg_replace('/\D/', '', $rcliente->cpfcnpj);

		$username											= $cpf . "@cartaoamigosaude.com.br";

		$user                       						= \App\Models\User::where('email', $username)->first();

		if (!isset($user->id)) 
		{
			return response()->json(['mensagem' => 'O usuário não foi encontrato. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 404);
		}

		$user->password 									= Hash::make($request->senha);
		$user->save();
		
		return response()->json(['mensagem' => 'Senha alterada com sucesso'], 200);

	}
	
	public function home(Request $request)
	{
		
		list ($cpf,$lixo) 									= explode("@",$request->user()->email);
		$beneficiario 										= Cas::obterDadosBeneficiario($cpf);
		
		$retorno 	                        				= new stdClass;
		$profile											= new stdClass;
		
		$profile->id										= $request->user()->id;
		$profile->name										= $request->user()->name;
		$profile->email										= $beneficiario->email;
		$profile->avatarUrl									= null;
		$retorno->profile									= $profile;
		
		$card												= new stdClass;
		
		$card->holderName									= $beneficiario->nome;
		$card->planName										= "Cartão Amigo Saúde";
		if ($beneficiario->situacao == "A")
		{
			$card->isActive									= true;
		} else {
			$card->isActive									= false;
		}
		$card->memberId										= $beneficiario->id;
		$card->barcode										= null;
		$card->qrcode										= null;
		$retorno->card										= $card;
		
		
		$consultas											= new stdClass;
		$consultas->count									= 0;
		$consultas->period									= "Este mês";
		$exames												= new stdClass;
		$exames->pending									= 0;
		$medicamentos										= new stdClass;
		$medicamentos->inUse								= 0;
		$emergencias										= new stdClass;
		$emergencias->count									= 0;
		$emergencias->lastDays								= 90;
		$stat												= new stdClass;
		$stat->consultations								= $consultas;
		$stat->exams										= $exames;
		$stat->medications									= $medicamentos;
		$stat->emergencies									= $emergencias;
		
		$retorno->stats										= $stat;
		
		$quickActions										= array();
		
		$quickAction 	                        			= new stdClass;
		$quickAction->id									= "agendar-consulta";
		$quickAction->label									= "Tele Medicina";
		$quickAction->icon									= "medical_services";
		$quickAction->route									= "/appointments?modo=webview";
		$quickAction->color									= "#2E7D32";
		$quickActions[]										= $quickAction;
		
		$quickAction 	                        			= new stdClass;
		$quickAction->id									= "agendar-exame";
		$quickAction->label									= "Agendar Consultas & Exames";
		$quickAction->icon									= "assignment";
		$quickAction->route									= "/concierge";
		$quickAction->color									= "#0277BD";
		$quickActions[]										= $quickAction;
		
		$quickAction 	                        			= new stdClass;
		$quickAction->id									= "auxilio-funeral";
		$quickAction->label									= "Auxilio Funeral";
		$quickAction->icon									= "volunteer_activism";
		$quickAction->route									= "/webview?url=https://www.cartaoamigosaude.com.br/auxilio-funeral&title=Auxilio Funeral";
		$quickAction->color									= "#8E24AA";
		$quickActions[]										= $quickAction;
		
		$quickAction 	                        			= new stdClass;
		$quickAction->id									= "seguro-vida";
		$quickAction->label									= "Seguro de vida";
		$quickAction->icon									= "security";
		$quickAction->route									= "/webview?url=https://www.cartaoamigosaude.com.br/seguro-de-vida&title=Seguro de vida";
		$quickAction->color									= "#455A64";
		$quickActions[]										= $quickAction;
		
		$quickAction 	                        			= new stdClass;
		$quickAction->id									= "medicamentos";
		$quickAction->label									= "Desconto em Medicamentos";
		$quickAction->icon									= "local_pharmacy";
		$quickAction->route									= "/webview?url=https://www.cartaoamigosaude.com.br/desconto-em-farmacias&title=Desconto em Medicamentos";
		$quickAction->color									= "#FB8C00";
		$quickActions[]										= $quickAction;
		
		$quickAction 	                        			= new stdClass;
		$quickAction->id									= "equipamentos-saude";
		$quickAction->label									= "Equipamentos de Saúde";
		$quickAction->icon									= "medical_services";
		$quickAction->route									= "/webview?url=https://www.cartaoamigosaude.com.br/emprestimo-de-equipamentos-medico-hospitalar&title=Equipamentos de Saúde";
		$quickAction->color									= "#D32F2F";
		$quickActions[]										= $quickAction;
		
		$retorno->quickActions								= $quickActions;
		
		$recentActivities									= array();
		
		$agendamentos 										= \App\Models\ClinicaBeneficiario::with('especialidade', 'clinica')
																							->where('beneficiario_id', $beneficiario->id)
																							->whereIn('asituacao_id', [1,4,6,10])
																							->whereIn('id', function ($query) use ($beneficiario) {
																								$query->select(DB::raw('MAX(id)'))
																									->from('clinica_beneficiario')
																									->where('beneficiario_id', $beneficiario->id)
																									->whereIn('asituacao_id', [1,4,6,10])
																									->groupBy('asituacao_id');
																							})
																							->get();
		foreach ($agendamentos as $agendamento)
		{
																							 
			$recentActivitie 	                        	= new stdClass;
			$recentActivitie->id							= $agendamento->id;
			$recentActivitie->clinica 						= "";
			
			if ($agendamento->tipo == 'C')
			{
				$recentActivitie->type						= "appointment";
				$recentActivitie->title						= "Consulta marcada";
			} else {
				$recentActivitie->type						= "exam";
				$recentActivitie->title						= "Resultado de exame";
			}
			
			if (Cas::nulltoSpace($agendamento->dmedico) != "")
			{				
				$recentActivitie->description				= $agendamento->dmedico . " • " . $agendamento->especialidade->nome;
			} else {
				$recentActivitie->description				= $agendamento->especialidade->nome;
			}
			
			if (isset($agendamento->clinica->nome))
			{
				$recentActivitie->clinica 					= $agendamento->clinica->nome;
			}
			
			if (!is_null($agendamento->agendamento_data_hora))
			{
				list($ano,$mes,$dia) 						= explode("-",substr($agendamento->agendamento_data_hora,0,10));
				$hora 										= substr($agendamento->agendamento_data_hora,11,5);
				$agendamento_data 							= $ano . "-" . $mes ."-" . $dia;
				$recentActivitie->data_hora					= $dia . "/" . $mes . " ". $hora;
			} else {
				$agendamento_data 							= "";
				$hora										= "";
				$recentActivitie->data_hora					= "";
			}
			
			$recentActivitie->date							= $agendamento_data;
			$recentActivitie->hora							= $hora;
			
			
			switch ($agendamento->asituacao_id) 
			{
				case 1: /* Solicitado */
					$recentActivitie->status				= "solicitado";
					break;
				case 4: /* pagamento */
					$recentActivitie->status				= "pagamento";
					break;
				case 6: /* confirmado */
					$recentActivitie->status				= "confirmado";
					break;
				case 10: /* concluido */
					$recentActivitie->status				= "concluido";
					break;
			}
			$recentActivities[]								= $recentActivitie;
		}
		
		$retorno->recentActivities							= $recentActivities;
		
		$healthTips											= array();
		$healthTip 	                        				= new stdClass;
		$healthTip->id                                     	= "hidratacao";
		$healthTip->title									= "Hidratação";
		$healthTip->description								= "Beba água regularmente.";
		$healthTip->icon									= "local_drink";
		$healthTips[]										= $healthTip;
		
		$healthTip 	                        				= new stdClass;
		$healthTip->id                                     	= "exercicios";
		$healthTip->title									= "Exercícios";
		$healthTip->description								= "Pratique atividades físicas.";
		$healthTip->icon									= "directions_walk";
		$healthTips[]										= $healthTip;
		$retorno->healthTips								= $healthTips;
		$retorno->beneficiario 								= $beneficiario;
		
		return response()->json($retorno, 200);
	}
	
	public function view_dados_pessoais(Request $request, $id)
	{

		if (!$request->user()->tokenCan('view.beneficiarios')) {
            return response()->json(['mensagem' => 'Não autorizado para visualizar clientes.'], 403);
        }

        $rcliente              								= \App\Models\Cliente::find($id);
        
        if (!isset($rcliente->id))
        {
            return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
        }

		$cliente               								= new stdClass;

		$cliente->id         								= $rcliente->id;
		$cliente->cpf         								= preg_replace('/\D/', '', $rcliente->cpfcnpj);
		$cliente->nome         								= $rcliente->nome;

		 
		$cliente->sexo										= $rcliente->sexo;
		$cliente->data_nascimento 							= $rcliente->data_nascimento;
		$cliente->telefone     								= $rcliente->telefone;
	    $cliente->email         							= $rcliente->email;
        $cliente->cep           							= $rcliente->cep;
        $cliente->logradouro    							= $rcliente->logradouro;
        $cliente->numero        							= $rcliente->numero;
        $cliente->complemento   							= Cas::nulltoSpace($rcliente->complemento);
        $cliente->bairro        							= $rcliente->bairro;
        $cliente->cidade        							= $rcliente->cidade;
        $cliente->estado        							= $rcliente->estado;

        return response()->json($cliente, 200);

	}
	
	public function update_dados_pessoais(Request $request, $id)
    {

        if (!$request->user()->tokenCan('edit.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado para atualizar beneficiarios.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'          	=> 'required|string|max:100',
            'telefone'      	=> 'required|string|max:15',
			'sexo'      		=> 'required|string|in:Masculino,Feminino,M,F',
			'data_nascimento'   => 'required|date_format:d/m/Y',
            'cep'           	=> 'required|string|max:9',
            'logradouro'    	=> 'required|string|max:100',
            'numero'        	=> 'required|string|max:20',
            'complemento'   	=> 'nullable|string|max:100',
            'bairro'        	=> 'required|string|max:100',
            'cidade'        	=> 'required|string|max:100',
            'estado'        	=> 'required|string|max:2'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        $cliente                							= \App\Models\Cliente::find($id);

        if (!isset($cliente->id)) 
		{
            return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
        }
    
		list($dia,$mes,$ano)								= explode("/",$request->data_nascimento);
		$data_nascimento 									= $ano . "-" . $mes . "-". $dia;

		if (($request->sexo == 'Masculino') or ($request->sexo == 'M'))
		{
			$request->sexo									= 'M';
		} else {
			$request->sexo									= 'F';
		}

		$cliente->nome         								= $request->nome;
		$cliente->telefone     								= $request->telefone;
		$cliente->sexo      								= $request->sexo;
		$cliente->data_nascimento   						= $data_nascimento;
        $cliente->email         							= $request->email;
        $cliente->cep           							= $request->cep;
        $cliente->logradouro    							= $request->logradouro;
        $cliente->numero        							= $request->numero;
		if (isset($request->complemento))
		{
        	$cliente->complemento   						= $request->complemento;
		} else {
			$cliente->complemento   						= "";
		}
        $cliente->bairro        							= $request->bairro;
        $cliente->cidade        							= $request->cidade;
        $cliente->estado        							= $request->estado;

		$cliente->save();

        return response()->json($id, 200);
    }
	
	public function view_dependente(Request $request, $id)
	{

		if (!$request->user()->tokenCan('view.beneficiarios')) {
            return response()->json(['mensagem' => 'Não autorizado para visualizar clientes.'], 403);
        }

		$beneficiario              							= \App\Models\Beneficiario::find($id);
        
        if (!isset($beneficiario->id))
        {
            return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
        }
			
        $rcliente              								= \App\Models\Cliente::find($beneficiario->cliente_id);
        
        if (!isset($rcliente->id))
        {
            return response()->json(['mensagem' => 'Beneficiário/cliente não encontrado.'], 404);
        }

		$cliente               								= new stdClass;
		$cliente->id         								= $rcliente->id;
		$cliente->cpf         								= preg_replace('/\D/', '', $rcliente->cpfcnpj);
		$cliente->nome         								= $rcliente->nome;
		$cliente->sexo										= $rcliente->sexo;
		$cliente->data_nascimento 							= $rcliente->data_nascimento;
		$cliente->telefone     								= $rcliente->telefone;
	    $cliente->email         							= $rcliente->email;
        $cliente->cep           							= $rcliente->cep;
        $cliente->logradouro    							= $rcliente->logradouro;
        $cliente->numero        							= $rcliente->numero;
        $cliente->complemento   							= Cas::nulltoSpace($rcliente->complemento);
        $cliente->bairro        							= $rcliente->bairro;
        $cliente->cidade        							= $rcliente->cidade;
        $cliente->estado        							= $rcliente->estado;
		$cliente->parentesco_id 							= $beneficiario->parentesco_id;
        return response()->json($cliente, 200);

	}
	
	public function update_dependente(Request $request, $id)
    {

        if (!$request->user()->tokenCan('edit.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado para atualizar beneficiarios.'], 403);
        }

		if ($id > 0)
		{
			$beneficiario              						= \App\Models\Beneficiario::find($id);
			
			if (!isset($beneficiario->id))
			{
				return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
			}
		} 
		
		$rules = [
            'nome'          	=> 'required|string|max:100',
            'telefone'      	=> 'required|string|max:15',
			'sexo'      		=> 'required|string|in:Masculino,Feminino,M,F',
			'data_nascimento'   => 'required|date_format:d/m/Y',
            'cep'           	=> 'required|string|max:9',
            'logradouro'    	=> 'required|string|max:100',
            'numero'        	=> 'required|string|max:20',
            'complemento'   	=> 'nullable|string|max:100',
            'bairro'        	=> 'required|string|max:100',
            'cidade'        	=> 'required|string|max:100',
            'estado'        	=> 'required|string|max:2',
			'parentesco_id'		=> 'required|exists:parentescos,id'
        ];

		if ($id == 0)
		{
			$rules['beneficiario_id']					= 'required|exists:beneficiarios,id';
			$rules['cpf']								= 'required|string|max:20';
		}

        $validator = Validator::make($request->all(), $rules);
    
        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
		if ($id > 0)
		{
			$cliente                						= \App\Models\Cliente::find($beneficiario->cliente_id);

			if (!isset($cliente->id)) 
			{
				return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
			}
		} else {
			
			$titular                           				= \App\Models\Beneficiario::with('contrato')->find($request->beneficiario_id);

			if (!isset($titular->id))
			{
				return response()->json(['mensagem' => 'Beneficiário titular não encontrado'], 422);
			}

			if ($titular->contrato->tipo == 'F')
			{
				$plano_id             					= $titular->contrato->plano_id;
			} else {
				$plano_id             					= $titular->plano_id;
			}

			$plano              						= \App\Models\Plano::select('id','qtde_beneficiarios')->find($plano_id);

			if (!isset($plano->id))
			{
				return response()->json(['mensagem' => 'Plano do beneficiário titular não encontrado. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 422);
			}

			if (\App\Models\Plano::limiteDependentes($plano) <= 0)
			{
				return response()->json(['mensagem' => 'O plano contratado não permite inserir dependente. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 422);
			}
			
			if ($titular->contrato->tipo == 'F')
			{
				$qtde_dependentes 							= \App\Models\Beneficiario::where('contrato_id','=',$titular->contrato_id)
																			  ->where('tipo','=','D')
																			  ->where('desc_status','=','ATIVO')
																			  ->count();
			} else {
				$qtde_dependentes 							= \App\Models\Beneficiario::where('parent_id','=',$titular->id)
																			  ->where('tipo','=','D')
																			  ->where('desc_status','=','ATIVO')
																			  ->count();
			}

			if ($qtde_dependentes >= \App\Models\Plano::limiteDependentes($plano))
			{
				return response()->json(['mensagem' => 'Limite de Dependente que o plano permite atingido.'], 422);
			}
			
			$cpf                        					= str_replace(array('.','-','/'), '', $request->cpf);	 
			$cpf                   							= str_pad($cpf, 11, '0', STR_PAD_LEFT);
			
			$cliente                    					= \App\Models\Cliente::where('cpfcnpj','=',$cpf)->first();

			if (isset($cliente->id))
			{
				if (\App\Models\Beneficiario::where('cliente_id','=',$cliente->id)->where('ativo','=',1)->count() > 0)
				{
					return response()->json(['mensagem' => 'O CPF do dependente já está ativo em um contrato. Entre em contato com o Cartão.'], 422);
				}
			} else {
				$cliente            						= new \App\Models\Cliente();
				$cliente->cpfcnpj 							= $cpf;
				$cliente->ativo 							= true;
			}
		}
		
		list($dia,$mes,$ano)								= explode("/",$request->data_nascimento);
		$data_nascimento 									= $ano . "-" . $mes . "-". $dia;

		if (($request->parentesco_id == 3) or ($request->parentesco_id == 6))
		{
			$idade 				    					= Carbon::createFromDate($data_nascimento)->age;
			if ($idade > 21)
			{
				return response()->json(['mensagem' => 'Irmãos e Netos não podem ser maior que 21 anos'], 404);
			}
		}

		if (($request->sexo == 'Masculino') or ($request->sexo == 'M'))
		{
			$request->sexo									= 'M';
		} else {
			$request->sexo									= 'F';
		}

		$cliente->nome         								= $request->nome;
		$cliente->telefone     								= $request->telefone;
		$cliente->sexo      								= $request->sexo;
		$cliente->data_nascimento   						= $data_nascimento;
        $cliente->email         							= $request->email;
        $cliente->cep           							= $request->cep;
        $cliente->logradouro    							= $request->logradouro;
        $cliente->numero        							= $request->numero;
		if (isset($request->complemento))
		{
        	$cliente->complemento   						= $request->complemento;
		} else {
			$cliente->complemento   						= "";
		}
        $cliente->bairro        							= $request->bairro;
        $cliente->cidade        							= $request->cidade;
        $cliente->estado        							= $request->estado;
		if ($cliente->save())
		{
			if ($id== 0)
			{
				$contrato                              		= \App\Models\Contrato::where('cliente_id','=',$cliente->id)
																				  ->where('motivo','=','I')
																				  ->first();

				if (isset($contrato->id))
				{
					return response()->json(['mensagem' => 'Não foi possível cadastrar este dependente porque o CPF informado possui pendência financeira. Para regularizar ou tirar dúvidas, entre em contato com o Cartão no WhatsApp: (19) 98951-2404'], 422);
				}

				$jaexiste                           			= \App\Models\Beneficiario::with('contrato')
																				  ->where('cliente_id','=',$cliente->id)
																				  ->first();
				if (isset($jaexiste->id))
				{
					if ((isset($jaexiste->contrato->tipo)) and ($jaexiste->contrato->tipo == 'F'))
					{
						return response()->json(['mensagem' => 'O Dependente já está cadastrado no contrato número '. $jaexiste->contrato_id . '. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 422);
					}
				}
		
				$beneficiario								= new \App\Models\Beneficiario();
				$beneficiario->contrato_id 					= $titular->contrato_id;
				$beneficiario->cliente_id					= $cliente->id;
				$beneficiario->vigencia_inicio				= date('Y-m-d');
				$beneficiario->vigencia_fim					= '2099-12-31';
				$beneficiario->ativo						= true;
				$beneficiario->desc_status					= 'ATIVO';
				$beneficiario->parent_id					= $titular->id;
				$beneficiario->tipo							= 'D';
				$beneficiario->tipo_usuario					= 'DEPENDENTE';
				$beneficiario->plano_id             		= $plano_id;
			}
			$beneficiario->parentesco_id        			= $request->parentesco_id;
			$beneficiario->save();
		}

        return response()->json($id, 200);
    }
	
	public function index_especialidades(Request $request)
	{
		$sql 								= "SELECT id, nome FROM especialidades where ativo=1 and tipo='C' order by nome";
		$especialidades 					= DB::select($sql);	

		return response()->json($especialidades, 200);

	}
	
	public function linkMagicoConexa(Request $request, $id)
    {
		
		$beneficiario                          = \App\Models\Beneficiario::find($id);

		if (!isset($beneficiario->id))
		{
            return response()->json(['error' => "Beneficiário não encontrado"], 422);
        }
		
		$beneficiarioproduto  					= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$id)
																				 ->where('produto_id','=',4)
																				 ->first();		
																				  
		if ((!isset($beneficiarioproduto->id)) or ($beneficiarioproduto->ativacao==0))
		{
			$associate 							= Conexa::ativarDesativarBeneficiario($id,4,true);
			if ($associate->ok !='S')
			{
				return response()->json(['error' => $associate->mensagem], 404);
			}
		}
		
		$ipAddress 								= $request->ip();
		$magiclink 								= Cas::gerarLinkMagico($id, 4, $ipAddress);
		
		if ((isset($magiclink->ok)) and ($magiclink->ok == "N"))
		{
			return response()->json(['error' => $magiclink->mensagem], 404);
		}
		
		return response()->json($magiclink, 200);
	}
	
	public function local_beneficiario(Request $request, $id)
	{
		
		$cliente               								= new stdClass;
        $cliente->bairro        							= "";
        $cliente->cidade        							= "";
        $cliente->estado        							= "";
		
		$local               								= new stdClass;
        $local->cidade        								= "";
        $local->estado        								= "";
	
		$meulocal 										    = Ip2Location::IpGeolocation($request->ip());

		if (isset($meulocal->region))
		{
			$local->estado 									= strtoupper($meulocal->region);
			$local->cidade        							= strtoupper($meulocal->city);
		} 

		$cliente->local 									= $local;

		$beneficiario 		   								= \App\Models\Beneficiario::with('cliente')->find($id);
		
		if (isset($beneficiario->id))
		{
        	$cliente->bairro        						= $beneficiario->cliente->bairro;
        	$cliente->cidade        						= strtoupper($beneficiario->cliente->cidade);
        	$cliente->estado        						= strtoupper($beneficiario->cliente->estado);
		}

		if ($cliente->estado != "")
		{
			$uf 											= $cliente->estado;
		} else {
			$uf 											= $local->estado;
		}

        return response()->json($cliente, 200);

	}

	public function local_cidades(Request $request)
	{
	
		$uf              							= $request->input('uf', '');
		$cidades 									= BrasilApi::Municipios($uf);

        return response()->json($cidades, 200);

	}
	
	public function index_agendamentos(Request $request, $id)
	{
		
		$query									= DB::table('clinica_beneficiario')
														->select('clinica_beneficiario.id',
																 'clinica_beneficiario.tipo',
																 'clinica_beneficiario.beneficiario_id',
																 'especialidades.nome as especialidade',
																 'clinica_beneficiario.solicitado_data_hora',
																 'clinica_beneficiario.agendamento_data_hora',
																 'clinica_beneficiario.preagendamento_data_hora',
																 'clinica_beneficiario.cancelado_data_hora',
																 'clinica_beneficiario.confirmado_data_hora',
																 'clinica_beneficiario.pagamento_data_hora',
																 'clinica_beneficiario.asituacao_id',
																 'clinica_beneficiario.galaxPayId',
																 'clinica_beneficiario.status',
																 'clinica_beneficiario.paymentLink',
																 'clinica_beneficiario.boletopdf',
																 'clinica_beneficiario.boletobankNumber',
																 'clinica_beneficiario.pixpage',
																 'clinica_beneficiario.piximage',
																 'clinica_beneficiario.pixqrCode',
																 'clinica_beneficiario.vencimento',
																 'clinica_beneficiario.pagamento',
																 'clinica_beneficiario.url_voucher',
																 'clinica_beneficiario.valor',
																 'clinica_beneficiario.asituacao_id',
																 'asituacoes.nome as situacao');
																 
		$query->where('clinica_beneficiario.beneficiario_id','=',$id)
			  ->leftJoin('especialidades','clinica_beneficiario.especialidade_id','=','especialidades.id')
			  ->leftJoin('asituacoes','clinica_beneficiario.asituacao_id','=','asituacoes.id')
			  ->orderBy('solicitado_data_hora','desc');
		
		$lagendamentos								= $query->get();
		$agendamentos 								= array();

		foreach ($lagendamentos as $agendamento)
		{
			
			$reg               						= new stdClass;
			$reg->id								= $agendamento->id;
			$reg->especialidade						= $agendamento->especialidade;
			$reg->situacao 							= $agendamento->situacao;
			$reg->bpagar 							= false;
			$reg->cpagar 							= false;
			$reg->pcance 							= true;
			$reg->tvouch                            = false;
			$reg->vencimento						= "";
			$reg->pagamento							= "";
			$reg->boletopdf 						= "";
			$reg->boletoget             			= "";
			$reg->pixpage 							= "";
			$reg->piximage 							= "";
			$reg->pixqrcode             			= "";							
			$reg->cartao							= "";
			$reg->telefone 							= "";
			$reg->voucher							= "";
			$reg->voucherget						= "";
			
			if (is_null($agendamento->solicitado_data_hora))
			{
				$reg->detalhar 						= false;
			} else {
				$reg->detalhar 						= true;
			}
			
			$reg->valor 							= str_replace('.',',',$agendamento->valor);
			if ($agendamento->tipo == 'E')
			{
				$reg->especialidade					= 'Exames e Procedimentos';
			}
			
			$data 									= substr($agendamento->solicitado_data_hora,0,10);
			list($ano,$mes,$dia) 					= explode("-",$data);
			$reg->data								= "$dia/$mes/$ano";
			if (!is_null($agendamento->vencimento))
			{
				list($ano,$mes,$dia) 				= explode("-",$agendamento->vencimento);
				$reg->vencimento					= "$dia/$mes/$ano";
			}
			if (!is_null($agendamento->pagamento))
			{
				list($ano,$mes,$dia) 				= explode("-",$agendamento->pagamento);
				$reg->pagamento						= "$dia/$mes/$ano";
			}
			if ($agendamento->asituacao_id ==4)
			{
				$beneficiario            			= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
		
				if (isset($beneficiario->id))
				{
					$reg->telefone 					= preg_replace('/\D/', '', $beneficiario->cliente->telefone);
				}
			
				if ($agendamento->boletobankNumber > 0)
				{
					$reg->bpagar 					= true;
					$reg->boletopdf 				= $agendamento->boletopdf;
					$reg->boletoget             	= config('services.cas.api_url') . "/api/agendamentos/boleto/" . $agendamento->id;
					$reg->pixpage 					= $agendamento->pixpage;
					$reg->piximage 					= $agendamento->piximage;
					$reg->pixqrcode             	= $agendamento->pixqrCode;
				} else {
					if (Cas::nulltoSpace($agendamento->paymentLink) !="")
					{
						$reg->cpagar 				= true;
						$reg->cartao 				= $agendamento->paymentLink;
					}
				}
			}
			
			if (Cas::nulltoSpace($agendamento->url_voucher) !="")
			{
				$reg->voucher 						= $agendamento->url_voucher;
				$reg->voucherget             		= config('services.cas.api_url') . "/api/agendamentos/voucher/" . $agendamento->id;
				$reg->tvouch						= true;
			}
			
			if (($agendamento->asituacao_id ==5) or ($agendamento->asituacao_id ==8))
			{
				$reg->bpagar 						= false;
				$reg->cpagar 						= false;
				$reg->pcance 						= false;
				$reg->tvouch						= false;
			}
			
			if ($agendamento->asituacao_id >=10)
			{
				$reg->bpagar 						= false;
				$reg->tvouch						= false;
				$reg->pcance 						= false;
			}
			
			//$reg->tvouch						= false;
			
			$agendamentos[] 						= $reg;
		}
        return response()->json($agendamentos, 200);
	}
	
	public function view_agendamento(Request $request, $id)
    {
		
		$agendamento                             = \App\Models\ClinicaBeneficiario::with('clinica','beneficiario','especialidade','situacao')->find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['error' => 'Agendamento não encontrado.'], 404);
        }
		
		
		$clinica								= new stdClass();
		
		if ($agendamento->clinica_id ==0)
		{	
			$clinica->id						= 0;
			$clinica->nome						= "";
			$clinica->cep        				= "";
			$clinica->endereco        			= "";
			$clinica->numero        			= "";
			$clinica->complemento        		= "";
			$clinica->bairro        			= "";
			$clinica->cidade        			= "";
			$clinica->estado        			= "";
			$clinica->telefone 					= "";
		} else {
			$clinica							= new stdClass();
			$clinica->id						= $agendamento->clinica->id;
			$clinica->nome						= $agendamento->clinica->nome;
			$clinica->cep        				= $agendamento->clinica->cep;
			$clinica->endereco	    			= $agendamento->clinica->logradouro;
			$clinica->numero        			= $agendamento->clinica->numero;
			$clinica->complemento        		= Cas::nulltoSpace($agendamento->clinica->complemento);
			$clinica->bairro        			= $agendamento->clinica->bairro;
			$clinica->cidade        			= $agendamento->clinica->cidade;
			$clinica->estado        			= $agendamento->clinica->estado;
			$clinica->telefone 					= $agendamento->clinica->telefone;
		}
		
		$clinica->especialidade 				= $agendamento->especialidade->nome;
		$clinica->data							= "";
		$clinica->hora							= "";
		$clinica->vencimento					= "";
		$clinica->pagamento						= "";
		$clinica->tagendado 					= false;
		if ($agendamento->asituacao_id > 1)
		{
			if ($clinica->id > 0)
			{
				$clinica->tmostrar              = true;
			} else {
				$clinica->tmostrar              = false;
			}
		} else {
			$clinica->tmostrar                  = false;
		}
		$clinica->tvencimento 					= false;
		$clinica->tpagamento 					= false;
		$clinica->tmensagem 					= false;
		$clinica->mensagem 						= "";
		
		if ($agendamento->valor > 0)
		{
			$clinica->tvalor 					= true;
			$clinica->valor 					= "R$ ". str_replace(".",",",$agendamento->valor);
		} else {
			$clinica->tvalor 					= false;
			$clinica->valor						= "";
		}
		
		$clinica->situacao						= "";
		
		
		$situacao 								= \App\Models\Asituacao::find($agendamento->asituacao_id);
		if (isset($situacao->id))
		{
			$clinica->situacao					= $situacao->nome;
		} else {
			$clinica->situacao					= "";
		}
		
		if ((!is_null($agendamento->agendamento_data_hora)) and ($agendamento->asituacao_id >=3))
		{
			$data 								= substr($agendamento->agendamento_data_hora,0,10);
			list($ano,$mes,$dia) 				= explode("-",$data);
			$clinica->data						= "$dia/$mes/$ano";
			$clinica->hora						= substr($agendamento->agendamento_data_hora,11,5);
			$clinica->tagendado 				= true;
		}
		if (!is_null($agendamento->vencimento))
		{
			list($ano,$mes,$dia) 				= explode("-",$agendamento->vencimento);
			$clinica->vencimento				= "$dia/$mes/$ano";
			$clinica->tvencimento 				= true;
		}
		if (!is_null($agendamento->pagamento))
		{
			list($ano,$mes,$dia) 				= explode("-",$agendamento->pagamento);
			$clinica->pagamento					= "$dia/$mes/$ano";
			$clinica->tpagamento 				= true;
		}
		
		if (($agendamento->asituacao_id !=5) and ($agendamento->asituacao_id !=8))
		{
			$clinica->mensagem 					= Cas::nulltoSpace($agendamento->observacao);
		} else {
			$motivo 							= \App\Models\AgendamentoCmotivo::find($agendamento->cmotivo_id);
			if (isset($motivo->id))
			{
				$clinica->mensagem 				= "Cancelado pelo motivo: " . $motivo->nome;
			} else {
				$clinica->mensagem 				= "Cancelado";
			}
		}	
		
		$predatas 								= array();
		$pdatas 								= \App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$id)->get();
		
		foreach ($pdatas as $pdata)
		{
			$reg								= new stdClass();
			$data 								= substr($pdata->data_hora,0,10);
			list($ano,$mes,$dia) 				= explode("-",$data);
			$reg->id 							= $pdata->id;
			$reg->data							= "$dia/$mes/$ano";
			$reg->hora							= substr($pdata->data_hora,11,5);
			if ($pdata->escolhido == 1)
			{
				$reg->selec 					= true;
			} else {
				$reg->selec 					= false;
			}
			$predatas[]							= $reg;
		}
		
		$clinica->predatas						= $predatas;
		
		if (($agendamento->asituacao_id == 2) and (count($predatas) > 0))
		{
			$clinica->tconfirma 				= true;
		} else {
			$clinica->tconfirma 				= false;
		}
		
		$clinica->exames						= \App\Models\ExamePedido::select('id','nome','caminho')->where('clinica_beneficiario_id','=',$id)->get();
		$clinica->datas							= \App\Models\SugestaoData::select('id','data')->where('clinica_beneficiario_id','=',$id)->get();
		
        return response()->json($clinica,200);
    }
	
	public function index_mensalidades(Request $request, $id)
	{
		
		$beneficiario 		   								= \App\Models\Beneficiario::with('contrato')
																					  ->with('cliente')
																		 			  ->find($id);
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
		} 

		$pagto               								= new stdClass;
		$pagto->telefone 									= preg_replace('/\D/', '', $beneficiario->cliente->telefone);
		$pagto->parcelas 									= array();
		$pagto->pagas 										= array();
		$pagto->vencidas									= array();
		$pagto->avencer										= array();

		if ($beneficiario->contrato->tipo == 'F')
		{
			$query											= DB::table('parcelas')
																	->select('parcelas.id',
																			 'parcelas.contrato_id',
																			 'parcelas.valor',
																			 'parcelas.nparcela',
																			 'parcelas.data_vencimento',
																			 'parcelas.data_pagamento',
																			 'parcelas.data_baixa',
																			 'parcelas.valor_pago',
																			 'parcelas.boletopdf',
																			 'parcelas.pixpage',
																			 'parcelas.piximage',
																			 'parcelas.pixqrCode',
																			 'parcelas.boletobankNumber',
																			 'parcelas.statusDescription')
																	 ->where('parcelas.contrato_id','=',$beneficiario->contrato_id);
			 $query->orderBy('data_vencimento','desc');

			 $cparcelas										= $query->get();
			 foreach ($cparcelas as $parcela)
			 {
			
				$parcela->psituacao 						= Cas::obterSituacaoParcela($parcela->data_vencimento,$parcela->data_pagamento,$parcela->data_baixa);
				if ((!is_null($parcela->data_pagamento)) and ($parcela->valor_pago > 0))
				{
					$parcela->valor 						= $parcela->valor_pago;
				}
				if  ($parcela->psituacao == 'Paga')
				{
					$parcela->formapagamento    			= $parcela->statusDescription;
				} else {
					$parcela->formapagamento				= "";
				}
				if (is_null($parcela->data_pagamento))
				{
					$parcela->data_pagamento				= "";
				}
				if (is_null($parcela->data_baixa))
				{
					$parcela->data_baixa					= "";
				}
				$reg               							= new stdClass;
				$reg->parcela_id 							= $parcela->id;
				$reg->contrato_id 							= $parcela->contrato_id;
				$reg->nparcela 								= $parcela->nparcela;
				list($ano,$mes,$dia)						= explode("-", $parcela->data_vencimento);
				$reg->vencimento 							= "$dia/$mes/$ano";
				if ($parcela->data_baixa != "")
				{
					list($ano,$mes,$dia)					= explode("-", $parcela->data_baixa);
					$reg->baixa 							= "$dia/$mes/$ano";
				} else {
					$reg->baixa 							= "";
				}
				if ($parcela->data_pagamento !="")
				{
					list($ano,$mes,$dia)					= explode("-", $parcela->data_pagamento);
					$reg->pagamento 						= "$dia/$mes/$ano";
				} else {
					$reg->pagamento 						= "";
				}
				$reg->valor 								= str_replace('.',',',$parcela->valor);
				$reg->forma 								= $parcela->formapagamento;
				$reg->situacao 								= $parcela->psituacao;
				if ($reg->situacao =='Vencida')
				{
					$date 									= $parcela->data_vencimento. " 23:59:59";
					$vencimento 							= Carbon::createFromDate($date);
					$now 									= Carbon::now();
					$diferenca 								= $vencimento->diffInDays($now);
					$reg->vdias								= intval($diferenca);
				} else {
					if ($reg->situacao =='Á vencer')
					{
						$date 								= $parcela->data_vencimento. " 23:59:59";
						$vencimento 						= Carbon::createFromDate($date);
						$now 								= Carbon::now();
						$diferenca 							= $now->diffInDays($vencimento);
						$reg->vdias							= intval($diferenca);
					} else {
						$reg->vdias 						= ''; 
					}
				}
				
				$reg->boletopdf 							= "";
				$reg->boletoget								= "";
				$reg->pixpage 								= "";
				$reg->piximage 								= "";
				$reg->pixqrcode 							= "";
				$reg->cartao 								= "";
				$reg->bpagar 								= false;
				$reg->cpagar								= false;
				
				switch ($reg->situacao) 
				{
					case 'Paga':
						$pagto->pagas[]						= $reg;
						break;
					case 'Baixada':
						break;
					case 'Vencida':
						if ($parcela->boletobankNumber > 0)
						{
							if ($reg->vdias <= 59)
							{
								$reg->bpagar 				= true;
								$reg->boletopdf 			= $parcela->boletopdf;
								$reg->boletoget             = config('services.cas.api_url') . "/api/parcelas/boleto/" . $parcela->id;
								$reg->pixpage 				= $parcela->pixpage;
								$reg->piximage 				= $parcela->piximage;
								$reg->pixqrcode             = $parcela->pixqrCode;
							} 
						} else {
							$contrato 				        = \App\Models\Contrato::find($parcela->contrato_id);
							if ((isset($contrato->id)) and ($contrato->paymentLink !=""))
							{
								$reg->cpagar 				= true;
								$reg->cartao 				= $contrato->paymentLink;
							}
						}
						$pagto->vencidas[]					= $reg;
						break;
					case 'Á vencer':
						if ($parcela->boletobankNumber > 0)
						{
							if ($reg->vdias <= 120)
							{
								$reg->bpagar 				= true;
								$reg->boletopdf 			= $parcela->boletopdf;
								$reg->boletoget             = config('services.cas.api_url') . "/api/parcelas/boleto/" . $parcela->id;
								$reg->pixpage 				= $parcela->pixpage;
								$reg->piximage 				= $parcela->piximage;
								$reg->pixqrcode             = $parcela->pixqrCode;
							} 
						} else {
							$contrato 				        = \App\Models\Contrato::find($parcela->contrato_id);
							if ((isset($contrato->id)) and ($contrato->paymentLink !=""))
							{
								$reg->cpagar 				= true;
								$reg->cartao 				= $contrato->paymentLink;
							}
						}
						$pagto->avencer[]					= $reg;
						break;
				} 
				$pagto->parcelas[]							= $reg;
				
			}
		}

	 	usort($pagto->avencer, function  ($a, $b) {return $a->vdias <=> $b->vdias;});    // Ordena em ordem crescente
		usort($pagto->vencidas, function ($a, $b) {return $b->vdias <=> $a->vdias;});    // Ordena em ordem decrescente
		return response()->json($pagto, 200);
	}
	
	public function agendamento(Request $request, $id)
	{

		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$beneficiario 		   								= \App\Models\Beneficiario::with('cliente','contrato')->find($id);
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['mensagem' => 'Beneficiário não encontrado. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404 '], 404);
		}

		if (\App\Models\Plano::dependenteCasTelemedicina($beneficiario))
		{
			return response()->json(['mensagem' => 'Dependentes do plano CAS têm acesso apenas à Telemedicina.'], 422);
		}

		$validator = Validator::make($request->all(), [
			'servico' => [
				'required',
				'in:Consulta Presencial,Exames e Procedimentos', // Apenas 'Consulta Presencial' ou 'Exames e Procedimentos'
			],
			'especialidade_id' => [
				'nullable',
				'integer',
				function ($attribute, $value, $fail) {
					if ($value > 0 && !\DB::table('especialidades')->where('id', $value)->exists()) {
						$fail("O campo $attribute deve referenciar uma especialidade válida.");
					}
				},
			],
			'estado' => [
				'nullable',
				'regex:/^[A-Z]{2}$/', // Verifica se é uma UF válida
				function ($attribute, $value, $fail) {
					$ufs = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
					if (!in_array($value, $ufs)) {
						$fail("O campo $attribute deve ser um estado válido do Brasil.");
					}
				},
			],
			'cidade' 	=> 'nullable|string|max:255',
			'datas.*' 	=> 'nullable', // Certifique-se de que os elementos em 'datas' são datas válidas
		]);
		
        if ($validator->fails()) 
		{
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		if (!isset($request->especialidade_id))
		{
			$request->especialidade_id		= 0;
		}
		
		$payload								= (object) $request->all();
		//
		Log::info('payload', ['payload' => $payload]);
			
		$agendamentoAberta = \App\Models\ClinicaBeneficiario::where('beneficiario_id', $id)
			->where('especialidade_id', $request->especialidade_id)
			->whereNotIn('asituacao_id', [5, 6, 7, 8, 9, 10, 11, 12])
			->first();

		if (isset($agendamentoAberta->id)) {
			return response()->json(['mensagem' => 'Já existe agendamento em aberto para aquele beneficiario e especialidade'], 422);
		}
		
		$chave 										= 'exames_'.$id;
		$pedidos 									= \Cache::get($chave, []);
		
		$asituacao 		   							= \App\Models\Asituacao::find(1);
		
		DB::beginTransaction();
		
		$agendamento            			    	= new \App\Models\ClinicaBeneficiario();
		$agendamento->beneficiario_id           	= $id;
		$agendamento->tipo 							= substr($request->servico,0,1);
		$agendamento->clinica_id            		= 0;
		$agendamento->especialidade_id          	= $request->especialidade_id;
		$agendamento->solicitado_data_hora			= date('Y-m-d H:i:s');
		$agendamento->valor           				= 0;
		$agendamento->cobranca          			= false;
		$agendamento->confirma          			= false;
		$agendamento->dmedico						= "";
		$agendamento->asituacao_id 					= 1;
		$agendamento->situacao						= 'R';
		$agendamento->forma          				= "";
		$agendamento->user_id 						= $request->user()->id;
		$agendamento->cidade 						= $request->cidade;
		$agendamento->estado 						= $request->estado;
		
		if ((!isset($request->altura)) or (!is_numeric($request->altura)))
		{
			$request->altura						= 0;
		}
		
		if ((!isset($request->peso)) or (!is_numeric($request->peso)))
		{
			$request->peso							= 0;
		}
		
		if (!isset($request->medicamentos))
		{
			$request->medicamentos					= "";
		}
		
		$agendamento->altura						= $request->altura;
		$agendamento->peso							= $request->peso;
		$agendamento->medicamento					= $request->medicamentos;
		
		if (isset($asituacao->id))
		{
			$agendamento->observacao				= str_replace(array("<p>","</p>"),"",$asituacao->orientacao);
		} else {
			$agendamento->observacao				= "";
		}
		
		if ($agendamento->save())
		{
			// isto deve sair
			//Cas::enviarMensagemAgendamento($agendamento->id, $beneficiario->id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
			
			if ($agendamento->tipo == 'E')
			{
				$pedidos 				= $request->input('pedidos', []);
				
				if (!is_array($pedidos)) $pedidos = [];

				Log::info('pedidos', ['pedidos' => $pedidos]);
				
				$linha										= 1;
				foreach ($pedidos as $index => $p) 
				{
					 
					$nome 			= $p['nome'] ?? null;
					$mime 			= $p['mime'] ?? 'application/octet-stream';
					$base64 		= $p['conteudo'] ?? null;

					if (!$nome || !$base64) 
					{
						Log::warning('pedido inválido', ['index' => $index]);
						continue;
					}

					// Se vier "data:image/png;base64,....", remove o prefixo
					if (str_contains($base64, 'base64,')) 
					{
						$base64 = explode('base64,', $base64, 2)[1];
					}

					// Decode seguro
					$bin = base64_decode($base64, true);
					if ($bin === false) {
						Log::warning('base64 inválido', ['index' => $index, 'nome' => $nome]);
						continue;
					}

					// Pasta destino (Linux) - mantendo seu padrão
					$folderName 			= 'exame/' . $linha;
					$destinationPath 		= public_path($folderName);

					File::ensureDirectoryExists($destinationPath, 0755, true);

					// Nome seguro + único
					$ext 					= pathinfo($nome, PATHINFO_EXTENSION);
					$base 					= Str::slug(pathinfo($nome, PATHINFO_FILENAME));
					$codigo 				= bin2hex(random_bytes(6));
					$fileName 				= $codigo . '-' . $base . ($ext ? '.' . strtolower($ext) : '');

					// Grava arquivo
					$fullPath 				= $destinationPath . DIRECTORY_SEPARATOR . $fileName;
					file_put_contents($fullPath, $bin);

					$caminho = url($folderName . '/' . $fileName);

					// ✅ grava no banco (mantendo sua lógica)
					$exame 					= \App\Models\ExamePedido::where('clinica_beneficiario_id', $agendamento->id)
																	 ->where('nome', $nome)
																	 ->first();

					if (!isset($exame->id))
					{
						$exame 							= new \App\Models\ExamePedido();
						$exame->clinica_beneficiario_id	= $agendamento->id;
						$exame->nome 					= $nome;
						$exame->caminho 				= $caminho;
						$exame->save();
					}

					$linha++;
				}
			}
	
			$datas = $request->input('datas');

			if (is_string($datas)) 
			{
				$decoded 								= json_decode($datas, true);

				if (json_last_error() === JSON_ERROR_NONE) {
					$datas = $decoded;
				} else {
					$datas = [];
				}
			}

			$datas 										= is_array($datas) ? $datas : [];
		
			Log::info('datas', ['datas' => $datas]);
			 
			foreach ($datas  as $data)
			{
				list($dia,$mes,$ano) 					= explode("/",$data);
				$data 									= "$ano-$mes-$dia";
				
				$sugestao 								= \App\Models\SugestaoData::where('clinica_beneficiario_id','=',$agendamento->id)
																			      ->where('data','=',$data)
																			      ->first();
																		 
				if (!isset($sugestao->id))
				{
					$sugestao            				= new \App\Models\SugestaoData();
					$sugestao->clinica_beneficiario_id	= $agendamento->id;
					$sugestao->data						= $data;
					$sugestao->save();
				}
			}
			
			$historico            			    		= new \App\Models\AgendamentoHistorico();
			$historico->clinica_beneficiario_id			= $agendamento->id;
			$historico->user_id 						= $request->user()->id;
			$historico->historico						= "Solicitado pelo APP em: " . date('d/m/Y H:i:s');			
			$historico->save();
			
			DB::commit();
			$retorno              						= new stdClass;
			$retorno->id 								= $agendamento->id;
			$retorno->mensagem 							= $agendamento->observacao . ' Favor acompanhar através da opção agendamentos.';
			return response()->json($retorno, 200);
		}
		
		DB::rollBack();
		return response()->json(['mensagem' => 'Ocorreu problema na tentativa de fazer o re-agendamento.  Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 422);

	}
	
	public function index_cmotivos(Request $request)
	{
		 $sql                    		= "SELECT id, nome, descricao from agendamento_cmotivos where disponivel=1 order by ordem";
         $motivos			       		= DB::select($sql);
		 
		 return response()->json($motivos, 200);
	}
	
	public function agendamento_cancelar(Request $request, $id)
{
	// Cancelamento de agendamento desabilitado para o App
	return response()->json(['mensagem' => 'Funcionalidade não disponível no aplicativo. Entre em contato com o suporte.'], 403);

	$agendamento                              	= \App\Models\ClinicaBeneficiario::with('clinica')->find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		
		$galaxPayId									= $agendamento->galaxPayId;
		$motivo_id              					= $request->input('motivo_id', 1);
		$agendamento->asituacao_id 					= 8;
		$agendamento->cmotivo_id 					= $motivo_id;
		$agendamento->cancelado_data_hora			= date('Y-m-d H:i:s');
		$agendamento->cancelado_por 				= $request->user()->id;
		$agendamento->galaxPayId					= "";
		$agendamento->url_voucher 					= "";
		if ($agendamento->save())
		{
			if ($galaxPayId !="")
			{
				$response 							= json_decode($agendamento->response);
				if (isset($response->galaxPayId))
				{
					$galaxPayId 					= $response->galaxPayId;
					$cancelar 						= CelCash::cancelCharges($galaxPayId,2);
				}
			}
			$beneficiario                       	= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
			if (isset($beneficiario->id))
			{
				$asituacao 		   					= \App\Models\Asituacao::find(8);
				if (isset($asituacao->id))
				{
					/*
					Cas::enviarMensagemAgendamento($agendamento->id,$agendamento->beneficiario_id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
					if ((Cas::nulltoSpace($asituacao->whatsappc) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
					{
						Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$asituacao->whatsappc,$request->user()->id);
					}
					*/
				}
			}
		}
		
		return response()->json($agendamento->id, 200);
	}
	// Integração Full - Pronto Atendimento

	public function criarAtendimentoImediato(Request $request)
	{
		$validator = Validator::make($request->all(), [
            'patientId' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

		$plano_id = $request->user()->plano_id; // Assuming user has plano_id
		$resultado = Conexa::criarAtendimentoImediato($request->all(), $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function anexarArquivoAtendimento(Request $request, $idProtocol)
	{
		$validator = Validator::make($request->all(), [
            'base64Content' => 'required|string',
			'extension' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

		$plano_id = $request->user()->plano_id;
		$resultado = Conexa::anexarArquivoAtendimento($idProtocol, $request->all(), $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function obterAtendimentoImediatoPaciente($patientId)
	{
		$plano_id = auth()->user()->plano_id; // Assuming user is authenticated
		$resultado = Conexa::obterAtendimentoImediatoPaciente($patientId, $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function cancelarAtendimentoImediato($patientId)
	{
		$plano_id = 1;
		$resultado = Conexa::cancelarAtendimentoImediato($patientId, $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {se()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function obterUltimaChamada($patientId)
	{
		$plano_id = auth()->user()->plano_id;
		$resultado = Conexa::obterUltimaChamada($patientId, $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	// Integração Full - Agendado com Especialidade Médica

	public function listarEspecialidades(Request $request)
	{
		$plano_id 			= 0; //$request->user()->plano_id;
		$encaixe 			= true;
		$resultado 			= Conexa::listarEspecialidades($plano_id,$encaixe);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function listarMedicosPorEspecialidade(Request $request, $specialtyId, $page)
	{
		$plano_id = 0;
		$resultado = Conexa::listarMedicosPorEspecialidade($specialtyId, $page, $request->input('name'), $request->input('patientId'), $request->input('sortType'), $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}
	
	public function listarMedicosPorEspecialidadeEncaixe($specialtyId,$plano_id=0)
	{
		
		$name 									= "";
		$patientId								= 0;
		$sortType								= "";
		$medicos								= array();
		$page									= 1;
		
		do {
			 
			$resultado 							= Conexa::listarMedicosPorEspecialidade($specialtyId, $page, $name, $patientId, $sortType, $plano_id);
		
			if (!isset($resultado->ok)) 
			{
				break;
			}
		
			$ok 								= $resultado->ok;
		
			if ($ok === 'S' && !empty($resultado->object)) 
			{
				if (is_array($resultado->object)) 
				{
					$medicos	 				= array_merge($medicos	, $resultado->object);
				} else {
					$medicos[] 					= $resultado->object;
				}
			}
			$page++; // Próxima página
		} while ($ok === 'S');
		
		$horarios 								= array();
		$startDate 								= Carbon::now('America/Sao_Paulo');
		$endDate 								= $startDate->copy()->addDays(10);
		
		foreach ($medicos as $medico)
		{
			$resultado 							= Conexa::obterHorariosDisponiveisMedico($doctorId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d'),$plano_id);
		}
		
	}

	public function obterHorariosDisponiveisMedico(Request $request, $doctorId)
	{
		$plano_id 				= 0; //$request->user()->plano_id;
		// Data inicial (agora)
		$startDate 				= Carbon::now('America/Sao_Paulo');
		// Data fim = data inicial + 3 meses
		$endDate 				= $startDate->copy()->addMonths(1);
		$resultado 				= Conexa::obterHorariosDisponiveisMedico($doctorId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d'),$plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function criarAgendamentoMedico(Request $request)
	{
		$validator = Validator::make($request->all(), [
            'appointmentDate' => 'required|string',
			'doctorId' => 'required|integer',
			'patientId' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

		$plano_id = $request->user()->plano_id;
		$resultado = Conexa::criarAgendamentoMedico($request->all(), $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	// Integração Full - Agendado com Outras Especialidades

	public function listarProfissionaisSaudePorNome(Request $request, $page)
	{
		$validator = Validator::make($request->all(), [
            'professionalType' => 'required|string',
			'patientId' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

		$plano_id = $request->user()->plano_id;
		$resultado = Conexa::listarProfissionaisSaudePorNome(
			$page, 
			$request->input('professionalType'), 
			$request->input('patientId'), 
			$request->input('name'), 
			$request->input('theme'), 
			$request->input('occupationArea'), 
			$request->input('specialty'), 
			$request->input('approach'), 
			$request->input('ageRange'), 
			$request->input('searchByTriage'), 
			$request->input('executeCount'), 
			$request->input('sortType'), 
			$plano_id
		);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function obterHorariosDisponiveisProfissional(Request $request, $id)
	{
		$validator = Validator::make($request->all(), [
            'startDate' => 'required|string',
			'professionalType' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

		$plano_id = $request->user()->plano_id;
		$resultado = Conexa::obterHorariosDisponiveisProfissional($id, $request->input('startDate'), $request->input('professionalType'), $request->input('patientId'), $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	public function criarAgendamentoProfissional(Request $request)
	{
		$validator = Validator::make($request->all(), [
            'appointmentDate' => 'required|string',
			'doctorId' => 'required|integer',
			'patientId' => 'required|integer',
			'professionalType' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

		$plano_id = $request->user()->plano_id;
		$resultado = Conexa::criarAgendamentoProfissional($request->all(), $request->input('professionalType'), $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}

	// Avaliação

	public function salvarAvaliacaoAtendimento(Request $request)
	{
		$validator = Validator::make($request->all(), [
            'appointmentId' => 'required|integer',
			'professional' => 'required|array',
			'plataform' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 400);
        }

		$plano_id = $request->user()->plano_id;
		$resultado = Conexa::salvarAvaliacaoAtendimento($request->all(), $plano_id);

		if ($resultado->ok == 'S') {
			return response()->json($resultado->object, 200);
		} else {
			return response()->json(['mensagem' => $resultado->mensagem], $resultado->status);
		}
	}
}
