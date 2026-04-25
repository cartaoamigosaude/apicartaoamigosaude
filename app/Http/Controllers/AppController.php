<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
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
class AppController extends Controller
{

	public function senhalink_sms(Request $request)
	{
		
		$validator = Validator::make($request->all(), [
            'cpf' 				=> 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 404);
        }

		$request->cpf										= preg_replace('/[^0-9]/', '', $request->cpf);
		$request->cpf 										= str_pad($request->cpf, 11, "0", STR_PAD_LEFT);	
		
		$cliente                 							= \App\Models\Cliente::select('id','nome','telefone')
																					->where('cpfcnpj','=',$request->cpf)
																					->where('tipo','=','F')
																					->first();
													  
		if (!isset($cliente->id)) 
		{
            return response()->json(['mensagem' => 'CPF não encontrado. Se voce é dependente, solicite para o titular o seu cadastro.'], 404);
        }	
		
		$beneficiario            							= \App\Models\Beneficiario::with('contrato','cliente')
																					 ->where('cliente_id','=',$cliente->id)
																					 ->where('ativo','=',1)
																					 ->first();
													  
		if (!isset($beneficiario->id)) 
		{
            return response()->json(['mensagem' => 'Não existe nenhum contrato. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 404);
        }		
		
		if ((!isset($beneficiario->contrato->status)) or (($beneficiario->contrato->status !='active') and ($beneficiario->contrato->status !='waitingPayment')))
		{
			return response()->json(['mensagem' => 'Não existe nenhum contrato ativo com o seu CPF. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 404);
		}
		
		
		$telefone 										= Kolmeya::tratarTelefone($beneficiario->cliente->telefone);
		
		//$sms 											= Kolmeya::sendSMS($beneficiario->id,$messages);

		return response()->json(['mensagem' => 'O link do SMS foi enviado para o telefone cadastrado! Caso não receba em até 20 minutos, entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 200);
		
	}

	public function alterar_senha(Request $request, $id)
	{

		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado para visualizar clientes.'], 403);
        }

		$validator = Validator::make($request->all(), [
            'senha' 			=> 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 404);
        }

        $rcliente              								= \App\Models\Cliente::find($id);
        
        if (!isset($rcliente->id))
        {
            return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
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
		/*
		$beneficiario            							= \App\Models\Beneficiario::with('contrato')
																					 ->where('cliente_id','=',$cliente->id)
																					 ->where('ativo','=',1)
																					 ->first();
		*/										  
		if (!isset($beneficiario->id)) 
		{
            return response()->json(['mensagem' => 'Não existe nenhum contrato. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 404);
        }		
		
		if ((!isset($beneficiario->contrato->status)) or (($beneficiario->contrato->status !='active') and ($beneficiario->contrato->status !='waitingPayment')))
		{
			return response()->json(['mensagem' => 'Não existe nenhum contrato ativo com o seu CPF. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 404);
		}
		
		if (($beneficiario->tipo == 'D') and ($beneficiario->acessoapp ==0))
		{
			return response()->json(['mensagem' => 'Solicite ao titular do plano o acesso ao Aplicativo'], 404);
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
			return response()->json(['mensagem' => 'A senha informada não está correta. Tente recuperar a sua senha ou entre em contato com o Cartão no whatsapp (19) 98951-2404'], 404);
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
		$usuario->token  	            					= $token['access_token'];
		$usuario->refreshtoken 								= $token['refresh_token'];
		$usuario->expiresin 								= $token['expires_in'];
		$usuario->tokentype 								= $token['token_type'];
		
		$cliente                    						= \App\Models\Cliente::find($beneficiario->contrato->cliente_id);
		
		$usuario->nome_empresa								= "Cartão Amigo Saúde";
		$usuario->cor_primaria 								= "#6f61ef";
		$usuario->cor_secundaria 							= "#ee8b60";
		$usuario->url_logo 									= "https://storage.googleapis.com/flutterflow-io-6f20.appspot.com/projects/cartao-amigo-saude-otl9zt/assets/118ppay92t3o/icone.png";
		
		if ((isset($cliente->id)) and ($cliente->tipo =='J'))
		{
			if ((Cas::nulltoSpace($cliente->url_logo) !="") and (Cas::nulltoSpace($cliente->cor_primaria) !="") and (Cas::nulltoSpace($cliente->cor_secundaria) !=""))
			{
				$usuario->nome_empresa						= $cliente->nome;
				$usuario->cor_primaria 						= $cliente->cor_primaria;
				$usuario->cor_secundaria 					= $cliente->cor_secundaria;
				$usuario->url_logo 							= $cliente->url_logo;
			}
		}
		
		if (isset($token['expires_in'])) 
		{
			$currentTimestamp 								= time(); // Timestamp atual
			$expiresTimestamp 								= $currentTimestamp + $token['expires_in']; // Soma o tempo de expiração
			//$usuario->expiresin 							= date('Y-m-d H:i:s', $expiresTimestamp);
			$usuario->expiresin 							= $expiresTimestamp;
		}

		return response()->json($usuario, 200);
		
	}

	public function view_imagem(Request $request, $id)
	{
		$retorno 				        				= new stdClass();
		$retorno->imagens 								= array();
		$sql 											= "SELECT id as imagem_id, imagem as caminho FROM imagens where ativo=1 order by sequencia";
		$imagens										= DB::select($sql);

		foreach ($imagens as $imagem)
		{
			$retorno->imagens[]  						= $imagem->caminho;
		}

		return response()->json($retorno, 200);
	}

	public function view_produto(Request $request, $id)
	{
		$retorno 				        				= new stdClass();
		$retorno->produto_id							= 0;
		$retorno->nome 									= "";
		$retorno->paragrafos 							= array();
		$retorno->ajuda 								= "";
	
		$produto                       					= \App\Models\Produto::find($id);

		if (isset($produto->id))
		{
			$retorno->produto_id						= $produto->id;
			$retorno->nome 								= $produto->nome;
			$retorno->paragrafos 						= explode("<br>",$produto->descricao);
			$retorno->ajuda 							= $produto->ajuda;
		}

		return response()->json($retorno, 200);
	}

	public function view_beneficio(Request $request, $id)
	{

		if (!$request->user()->tokenCan('view.beneficiarios')) {
            return response()->json(['mensagem' => 'Não autorizado para visualizar clientes.'], 403);
        }

		$beneficiario 		   					= \App\Models\Beneficiario::with('contrato')
																		 ->where('cliente_id','=',$id)
																		 ->where('desc_status', '=', 'ATIVO')
																		 ->orderBy('id','desc')
																		 ->first();
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
		} 

		$retorno 				        		= new stdClass();
		$retorno->id                            = $beneficiario->id;
		$retorno->tipoben						= $beneficiario->tipo;
		$retorno->pagamento                     = false;
		if ($retorno->tipoben == 'T')
		{
			$retorno->titular                   = true;
			if ($beneficiario->contrato->tipo == 'F')
			{
				$retorno->pagamento             = true;
			}
		} else {
			$retorno->titular                   = false;
		}
		$retorno->cstatus          				= substr(Cas::obterSituacaoContrato($beneficiario->contrato->status),0,1); 
		$retorno->pstatus 						= 'A';
		$retorno->vendias						= 0;
		$retorno->qtdedep						= 0;
		$retorno->dmensag						= '';

		$parcela 								= \App\Models\Parcela::where('contrato_id','=',$beneficiario->contrato_id)
																 	 ->where('data_pagamento','=',null)
																 	 ->where('data_vencimento','<',date('Y-m-d'))
																 	 ->orderBy('data_vencimento','asc')
																 	 ->first();
		if (isset($parcela->id))
		{
			$date 								= $parcela->data_vencimento. " 23:59:59";
			$vencimento 						= Carbon::createFromDate($date);
			$now 								= Carbon::now();
			$diferenca 							= $vencimento->diffInDays($now);
			$retorno->vendias					= $diferenca;
				
			if ($diferenca >=9)
			{
				$retorno->pstatus				= 'I';
			}
		}

		//Log::info("retorno", ['retorno' => $retorno ]);
 
		if ($beneficiario->contrato->tipo == 'F')
		{
			$plano_id             				= $beneficiario->contrato->plano_id;
		} else {
			$plano_id             				= $beneficiario->plano_id;
			if (($beneficiario->tipo == 'D') and ($plano_id  ==0))
			{
				$dbeneficiario 		   			= \App\Models\Beneficiario::find($beneficiario->parent_id);
		
				if (isset($dbeneficiario->id))
				{
					$plano_id             		= $dbeneficiario->plano_id;
				} 
			}
		}

	    //Log::info("plano_id", ['plano_id' => $plano_id ]);
		//Log::info("ctipo", ['ctipo' => $beneficiario->contrato->tipo ]);
		//Log::info("tipo", ['tipo' => $beneficiario->tipo ]);
 
		if ($beneficiario->tipo == 'T')
		{
			
			$plano              				= \App\Models\Plano::select('id','qtde_beneficiarios')->find($plano_id);
					
			if (isset($plano->id))
			{
				$retorno->dmensag			= 'Seus dependentes têm acesso a consultas médicas e odontologicas, exames.';
				if ($beneficiario->contrato->tipo == 'F')
				{
					$qtde_dependentes 		= \App\Models\Beneficiario::where('contrato_id','=',$beneficiario->contrato_id)
																	  ->where('tipo','=','D')
																	  ->where('desc_status','=','ATIVO')
																	  ->count();
				} else {
					$qtde_dependentes 		= \App\Models\Beneficiario::where('parent_id','=',$beneficiario->id)
																	  ->where('tipo','=','D')
																	  ->where('desc_status','=','ATIVO')
																	  ->count();
				}
				$retorno->qtdedep 			= \App\Models\Plano::vagasDependentes($plano, $qtde_dependentes);
			}
		} 

		//Log::info("qtdedep", ['qtdedep' => $retorno->qtdedep ]);

		$produtos 								= array();
		$retorno->produtos 						= array();
		$retorno->destaques 					= array();

		$planoprodutos   						= \App\Models\PlanoProduto::with('produto')
																		 ->where('plano_id','=',$plano_id)
																	 	 ->get();
		//Log::info("planoproduto", ['planoproduto' => $planoprodutos ]);
		
		foreach ($planoprodutos as $planoproduto)
		{
			if ($planoproduto->produto->ativo == 1)
			{
				$produto 				        			= new stdClass();
				$produto->beneficiario_id                   = $beneficiario->id;
				$produto->produto_id 						= $planoproduto->produto->id;
				$produto->nome 								= $planoproduto->produto->titulo;
				if (($planoproduto->beneficiario == 'A') or ($planoproduto->beneficiario == $beneficiario->tipo))
				{
					$produto->permitido 					= true;
				} else {
					$produto->permitido 					= true;
				}
				$produto->orientacao 						= $planoproduto->produto->orientacao;
				$produto->descricao 						= $planoproduto->produto->descricao;
				$produto->imagem 							= $planoproduto->produto->imagem;
				$produto->controle 							= $planoproduto->produto->controle;
				$produtos[$planoproduto->produto->sequencia."#".$planoproduto->produto->id]						= $produto;
			}
		}

		if (count($produtos) > 0)
		{
			ksort($produtos);
			foreach ($produtos as $produto)
			{
				$retorno->produtos[]					= $produto;
			}
		}

		$sql 											= "SELECT id as imagem_id, imagem as caminho FROM imagens where ativo=1 order by sequencia";
		$imagens										= DB::select($sql);

		foreach ($imagens as $imagem)
		{
			$retorno->destaques[]  						= $imagem;
		}

		return response()->json($retorno, 200);

	}

	public function view_beneficiario(Request $request, $id)
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

		if ($rcliente->sexo == 'M')
		{
			$cliente->sexo									= 'Masculino';
		} else {
			$cliente->sexo									= 'Feminino';
		}

		list($ano,$mes,$dia)								= explode("-",$rcliente->data_nascimento);
		$cliente->data_nascimento 							= "$dia/$mes/$ano";
		$cliente->telefone     								= $rcliente->telefone;
	    $cliente->email         							= $rcliente->email;
        $cliente->cep           							= $rcliente->cep;
        $cliente->logradouro    							= $rcliente->logradouro;
        $cliente->numero        							= $rcliente->numero;
        $cliente->complemento   							= Cas::nulltoSpace($rcliente->complemento);
        $cliente->bairro        							= $rcliente->bairro;
        $cliente->cidade        							= $rcliente->cidade;
        $cliente->estado        							= $rcliente->estado;
		$cliente->beneficiario_id 							= 0;
		$cliente->parentesco_id 							= 0;

        return response()->json($cliente, 200);

	}

	public function update_beneficiario(Request $request, $id)
    {

        if (!$request->user()->tokenCan('edit.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado para atualizar beneficiarios.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'          	=> 'required|string|max:100',
            'telefone'      	=> 'required|string|max:15',
			'sexo'      		=> 'required|string|in:Masculino,Feminino',
			'data_nascimento'   => 'required|date_format:d/m/Y',
            'cep'           	=> 'required|string|max:9',
            'logradouro'    	=> 'required|string|max:100',
            'numero'        	=> 'required|string|max:20',
            'complemento'   	=> 'nullable|string|max:100',
            'bairro'        	=> 'required|string|max:100',
            'cidade'        	=> 'required|string|max:100',
            'estado'        	=> 'required|string|max:2',
			'beneficiario_id'   => 'nullable',
			'parentesco_id'   	=> 'nullable',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
		$payload											= (object) $request->all();

		Log::info("request", ['request' => $payload ]);

        $cliente                							= \App\Models\Cliente::find($id);

        if (!isset($cliente->id)) 
		{
            return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
        }
    
		if ((!isset($request->beneficiario_id)) or (!is_numeric($request->beneficiario_id)))
		{
			$request->beneficiario_id						= 0;
		}

		if ((!isset($request->parentesco_id)) or (!is_numeric($request->parentesco_id)))
		{
			$request->parentesco_id							= 0;
		}

		list($dia,$mes,$ano)								= explode("/",$request->data_nascimento);
		$data_nascimento 									= $ano . "-" . $mes . "-". $dia;

		if (($request->beneficiario_id > 0) and ($request->parentesco_id > 0))
		{
			if (($request->parentesco_id == 3) or ($request->parentesco_id == 6))
			{
				$idade 				    					= Carbon::createFromDate($data_nascimento)->age;  
				if ($idade > 21)
				{
					return response()->json(['mensagem' => 'Irmãos e Netos não podem ser maior que 21 anos'], 404);
				}
			}
		}

		if ($request->sexo == 'Masculino')
		{
			$request->sexo									= 'M';
		} else {
			$request->sexo									= 'F';
		}

		$cliente->nome         								= $request->nome;
		$cliente->telefone     								= $request->telefone;
		$cliente->sexo      								= $request->sexo;
		$cliente->data_nascimento   						= $data_nascimento;
       // $cliente->email         							= $request->email;
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
			if (($request->beneficiario_id > 0) and ($request->parentesco_id > 0))
			{
				$beneficiario                           	= \App\Models\Beneficiario::find($request->beneficiario_id);
								
				if (isset($beneficiario->id))
				{
					if ($beneficiario->parentesco_id != $request->parentesco_id)
					{
						$beneficiario->parentesco_id 		= $request->parentesco_id;
						$beneficiario->save();
					}
				}
			}
		}

        return response()->json($id, 200);
    }

	public function index_dependentes(Request $request, $id)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$beneficiario 		   								= \App\Models\Beneficiario::with('contrato')
																		 			 ->where('cliente_id','=',$id)
																		 			 ->orderBy('id','desc')
																		 			 ->first();
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
		} 

		$retorno               								= new stdClass;
		$retorno->parent_id 								= $beneficiario->id;
		$retorno->dependentes 								= array();

		$sql 												= "SELECT id, nome FROM parentescos order by nome";
		$retorno->parentescos 								= DB::select($sql);	

		if ($beneficiario->contrato->tipo == 'F')
		{	
			$plano_id             							= $beneficiario->contrato->plano_id;
			$dependentes 									= \App\Models\Beneficiario::with('cliente','parentesco')
																	  ->where('contrato_id','=',$beneficiario->contrato_id)
																	  ->where('tipo','=','D')
																	  ->where('desc_status','=','ATIVO')
																	  ->get();
		} else {
			$plano_id             							= $beneficiario->plano_id;
			$dependentes 									= \App\Models\Beneficiario::with('cliente','parentesco')
																	  ->where('parent_id','=',$beneficiario->id)
																	  ->where('tipo','=','D')
																	  ->where('desc_status','=','ATIVO')
																	  ->get();
		}

		$plano              								= \App\Models\Plano::select('id','qtde_beneficiarios')->find($plano_id);
				
		if (isset($plano->id))
		{
			$retorno->qtde_beneficiarios					= \App\Models\Plano::vagasDependentes($plano, count($dependentes));
		} else {
			$retorno->qtde_beneficiarios					= 0;
		}

		foreach ($dependentes as $dependente)
		{
			$cliente               							= new stdClass;
			$cliente->id         							= $dependente->id;
			$cliente->parent_id         					= $beneficiario->id;
			$cliente->parentesco_id         				= $dependente->parentesco_id;
			$cliente->parentesco         					= $dependente->parentesco->nome;
			$cliente->cliente_id         					= $dependente->cliente->id;
			$cliente->cpf         							= preg_replace('/\D/', '', $dependente->cliente->cpfcnpj);
			$cliente->nome         							= $dependente->cliente->nome;
			$cliente->telefone     							= $dependente->cliente->telefone;

			if ($dependente->cliente->sexo == 'M')
			{
				$cliente->sexo								= 'Masculino';
			} else {
				$cliente->sexo								= 'Feminino';
			}

			list($ano,$mes,$dia)							= explode("-",$dependente->cliente->data_nascimento);
			$cliente->data_nascimento 						= "$dia/$mes/$ano";
	    	$cliente->email         						= $dependente->cliente->email;
        	$cliente->cep           						= $dependente->cliente->cep;
        	$cliente->logradouro    						= $dependente->cliente->logradouro;
        	$cliente->numero        						= $dependente->cliente->numero;
        	$cliente->complemento   						= Cas::nulltoSpace($dependente->cliente->complemento);
        	$cliente->bairro        						= $dependente->cliente->bairro;
        	$cliente->cidade        						= $dependente->cliente->cidade;
        	$cliente->estado        						= $dependente->cliente->estado;
			$retorno->dependentes[] 						= $cliente;

		}
		
		return response()->json($retorno, 200);
	}

	public function index_motivos(Request $request, $id)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
		}
		
		 $sql                    		= "SELECT id, nome, descricao from agendamento_cmotivos where disponivel=1 order by ordem";
         $motivos			       		= DB::select($sql);
		 
		 return response()->json($motivos, 200);
	}
	
	public function index_beneficiarios(Request $request, $id)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$beneficiario 		   								= \App\Models\Beneficiario::with('contrato','cliente','parentesco')
																		 			 ->where('cliente_id','=',$id)
																		 			 ->orderBy('id','desc')
																		 			 ->first();
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['mensagem' => 'Beneficiário não encontrado.'], 404);
		} 

		$retorno               								= new stdClass;
		$retorno->dependentes 								= array();

		$cliente               								= new stdClass;
		$cliente->beneficiario_id         					= $beneficiario->id;
		$cliente->parentesco_id         					= $beneficiario->parentesco_id;
		$cliente->id         								= $beneficiario->cliente->id;
		$cliente->cpf         								= preg_replace('/\D/', '', $beneficiario->cliente->cpfcnpj);
		$cliente->nome         								= $beneficiario->cliente->nome;
		$cliente->telefone     								= $beneficiario->cliente->telefone;
		if ($beneficiario->tipo =='T')
		{
			$cliente->parentesco 							= 'Titular';
		} else {
			$cliente->parentesco 							= $beneficiario->parentesco->nome;
		}
		if ($beneficiario->cliente->sexo == 'M')
		{
			$cliente->sexo									= 'Masculino';
		} else {
			$cliente->sexo									= 'Feminino';
		}

		list($ano,$mes,$dia)								= explode("-",$beneficiario->cliente->data_nascimento);
		$cliente->data_nascimento 							= "$dia/$mes/$ano";
	    $cliente->email         							= $beneficiario->cliente->email;
        $cliente->cep           							= $beneficiario->cliente->cep;
        $cliente->logradouro    							= $beneficiario->cliente->logradouro;
        $cliente->numero        							= $beneficiario->cliente->numero;
        $cliente->complemento   							= Cas::nulltoSpace($beneficiario->cliente->complemento);
        $cliente->bairro        							= $beneficiario->cliente->bairro;
        $cliente->cidade        							= $beneficiario->cliente->cidade;
        $cliente->estado        							= $beneficiario->cliente->estado;
		$retorno->dependentes[] 							= $cliente;

		if ($beneficiario->tipo =='T')
		{
			if ($beneficiario->contrato->tipo == 'F')
			{	
				$dependentes 									= \App\Models\Beneficiario::with('cliente','parentesco')
																				->where('contrato_id','=',$beneficiario->contrato_id)
																				->where('tipo','=','D')
																				->where('ativo','=',1)
																				->get();
			} else {
				$dependentes 									= \App\Models\Beneficiario::with('cliente','parentesco')
																				->where('parent_id','=',$beneficiario->id)
																				->where('tipo','=','D')
																				->where('ativo','=',1)
																				->get();
			}

			foreach ($dependentes as $dependente)
			{
				$cliente               							= new stdClass;
				$cliente->beneficiario_id         				= $dependente->id;
				$cliente->parentesco_id         				= $dependente->parentesco_id;
				if (isset($dependente->parentesco->nome))
				{
					$cliente->parentesco 						= $dependente->parentesco->nome;
				} else {
					$cliente->parentesco						= "";
				}
				$cliente->id         							= $dependente->cliente->id;
				$cliente->cpf         							=  preg_replace('/\D/', '', $dependente->cliente->cpfcnpj);
				$cliente->nome         							= $dependente->cliente->nome;
				$cliente->telefone     							= $dependente->cliente->telefone;
			
				if ($dependente->cliente->sexo == 'M')
				{
					$cliente->sexo								= 'Masculino';
				} else {
					$cliente->sexo								= 'Feminino';
				}

				list($ano,$mes,$dia)							= explode("-",$dependente->cliente->data_nascimento);
				$cliente->data_nascimento 						= "$dia/$mes/$ano";
				$cliente->email         						= $dependente->cliente->email;
				$cliente->cep           						= $dependente->cliente->cep;
				$cliente->logradouro    						= $dependente->cliente->logradouro;
				$cliente->numero        						= $dependente->cliente->numero;
				$cliente->complemento   						= Cas::nulltoSpace($dependente->cliente->complemento);
				$cliente->bairro        						= $dependente->cliente->bairro;
				$cliente->cidade        						= $dependente->cliente->cidade;
				$cliente->estado        						= $dependente->cliente->estado;
				$retorno->dependentes[] 						= $cliente;

			}
		}
		
		return response()->json($retorno->dependentes, 200);
	}

	public function store_dependente(Request $request)
    {
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['mensagem' => 'Não autorizado para atualizar beneficiarios.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'id'  				=> 'nullable',
			'cliente_id'   		=> 'nullable',
			'parent_id'   		=> 'required|exists:beneficiarios,id',
			'cpf'       		=> 'required|string|max:20',
			'nome'          	=> 'required|string|max:100',
            'telefone'      	=> 'required|string|max:15',
			'sexo'      		=> 'required|string|in:Masculino,Feminino',
			'data_nascimento'   => 'required|date_format:d/m/Y',
            'cep'           	=> 'required|string|max:9',
            'logradouro'    	=> 'required|string|max:100',
            'numero'        	=> 'required|string|max:20',
            'complemento'   	=> 'nullable|string|max:100',
            'bairro'        	=> 'required|string|max:100',
            'cidade'        	=> 'required|string|max:100',
            'estado'        	=> 'required|string|max:2',
			'parentesco_id'   	=> 'required|exists:parentescos,id'
        ]);
		
        if ($validator->fails()) 
		{
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$titular                           		= \App\Models\Beneficiario::with('contrato')->find($request->parent_id);

		if (!isset($titular->id))
		{
			return response()->json(['mensagem' => 'Beneficiário titular não encontrado'], 422);
		}

		if ($titular->contrato->tipo == 'F')
		{
			$plano_id             				= $titular->contrato->plano_id;
		} else {
			$plano_id             				= $titular->plano_id;
		}

		$plano              					= \App\Models\Plano::select('id','qtde_beneficiarios')->find($plano_id);
				
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
			$qtde_dependentes 					= \App\Models\Beneficiario::where('contrato_id','=',$titular->contrato_id)
															  			  ->where('tipo','=','D')
															  			  ->where('desc_status','=','ATIVO')
															  			  ->count();
		} else {
			$qtde_dependentes 					= \App\Models\Beneficiario::where('parent_id','=',$titular->id)
															  			  ->where('tipo','=','D')
															  			  ->where('desc_status','=','ATIVO')
															  			  ->count();
		}

		if ($qtde_dependentes >= \App\Models\Plano::limiteDependentes($plano))
		{
			return response()->json(['mensagem' => 'Limite de Dependente que o plano permite atingido.'], 422);
		}

		$payload								= (object) $request->all();

		if (!isset($request->cliente_id))
		{
			$request->cliente_id				= 0;
			$payload->cliente_id				= 0;
		}

		if (!isset($request->id))
		{
			$request->id						= 0;
		}

		$payload->tipo 							= 'F';

		if ($payload->sexo == 'Masculino')
		{
			$payload->sexo 						= 'M';
		} else {
			$payload->sexo 						= 'F';
		}

		list($dia,$mes,$ano)					= explode("/",$request->data_nascimento);
		$payload->data_nascimento 				= $ano . "-" . $mes . "-". $dia;
		$payload->cpfcnpj 						= $request->cpf;

		$cliente 								= Cas::storeUpdateCliente($payload);

		$contrato                              	= \App\Models\Contrato::where('cliente_id','=',$cliente->id)
																	  ->where('motivo','=','I')
																	  ->first();
		
		if (isset($contrato->id))
		{
			return response()->json(['mensagem' => 'Dependente tem pendência financeira. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 422);
		}
		
		$jaexiste                           	= \App\Models\Beneficiario::with('contrato')
																		  ->where('id','<>',$request->id)
															  			  ->where('cliente_id','=',$cliente->id)
															  			  ->first();
		if (isset($jaexiste->id))
		{
			if ((isset($jaexiste->contrato->tipo)) and ($jaexiste->contrato->tipo == 'F'))
			{
				return response()->json(['mensagem' => 'O Dependente já está cadastrado no contrato número '. $jaexiste->contrato_id . '. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 422);
			}
		}

		DB::beginTransaction();
		
		if ($request->id > 0)
		{
			$beneficiario                       = \App\Models\Beneficiario::find($request->id);

			if (!isset($beneficiario->id))
			{
				$beneficiario					= new \App\Models\Beneficiario();
				$beneficiario->contrato_id 		= $titular->contrato_id;
				$beneficiario->cliente_id		= $cliente->id;
				$beneficiario->vigencia_inicio	= date('Y-m-d');
				$beneficiario->vigencia_fim		= '2099-12-31';
			} else {
				if ($beneficiario->cliente_id != $cliente->id)
				{
					DB::rollBack();
					return response()->json(['mensagem' => 'Não é permitido modificar o CPF de um dependente. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 422);
				}
			}
		} else {
			$beneficiario						= new \App\Models\Beneficiario();
			$beneficiario->contrato_id 			= $titular->contrato_id;
			$beneficiario->cliente_id			= $cliente->id;
			$beneficiario->vigencia_inicio		= date('Y-m-d');
			$beneficiario->vigencia_fim			= '2099-12-31';
		}
		$beneficiario->ativo					= true;
		$beneficiario->desc_status				= 'ATIVO';
		$beneficiario->parent_id				= $titular->id;
		$beneficiario->parentesco_id			= $request->parentesco_id;
		$beneficiario->tipo						= 'D';
		$beneficiario->tipo_usuario				= 'DEPENDENTE';
		$beneficiario->plano_id             	= $plano_id;
	
		if (!$beneficiario->save())
		{
			DB::rollBack();
			return response()->json(['mensagem' =>'Ocorreu erro na tentativa de registrar o Beneficiario. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404'], 404);
		}

		DB::commit();
		return response()->json($beneficiario->id, 200);
					
	}

	public function index_cespecialidades(Request $request)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$sql 								= "SELECT id, nome FROM especialidades where ativo=1 and tipo='C' order by nome";
		$especialidades 					= DB::select($sql);	

		return response()->json($especialidades, 200);

	}

	public function local_beneficiario(Request $request, $id)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

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

		//$cliente->cidades 									= BrasilApi::Municipios($uf);

        return response()->json($cliente, 200);

	}

	public function local_cidades(Request $request)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$uf              							= $request->input('uf', '');
		$cidades 									= BrasilApi::Municipios($uf);

        return response()->json($cidades, 200);

	}

	public function upload_exame(Request $request, $id)
	{

		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$beneficiario 		   								= \App\Models\Beneficiario::with('cliente')->find($id);
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['mensagem' => 'Beneficiário não encontrado. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404 '], 404);
		}

		$file                       = $request->source;
		$codigo 					= bin2hex(random_bytes(6));
		$folderName					= 'exame' . '/' . $id;
		$originalName 				= $file->getClientOriginalName();
		$extension 			        = $file->getClientOriginalExtension();

		$fileName 					= $codigo . '-' . $originalName;

		$destinationPath 			= public_path() . '/' . $folderName;
		$file->move($destinationPath, $fileName);

		$caminho                    = url("/") . '/' . $folderName . '/' . $fileName;

		$exame               		= new stdClass;
		$exame->nome 				= $originalName;
		$exame->url               	= $caminho;

		$cacheKey 					= 'exames_'.$id;
		// Obtém o valor atual da cache, ou inicializa um array vazio
		$currentExams 				= \Cache::get($cacheKey, []);
		// Adiciona o novo exame ao array
		$currentExams[] 			= $exame;
		// Atualiza o cache com o array atualizado
		\Cache::put($cacheKey, $currentExams);

		Log::info('upload', ['exame' => $exame]);
		return response()->json($exame, 200);

	}

	public function delete_exame(Request $request, $id)
	{

		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$beneficiario 		   		= \App\Models\Beneficiario::with('cliente')->find($id);
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['mensagem' => 'Beneficiário não encontrado. Entre em contato com o Cartão no Whatsapp: (19) 98951-2404 '], 404);
		}

		$cacheKey 					= 'exames_'.$id;

		// Obtém o valor atual da cache, ou inicializa um array vazio
		$currentExams 				= \Cache::get($cacheKey, []);
		$examName					= $request->nome;
		// Filtra o array para remover o exame com o nome especificado
		$updatedExams 				= array_filter($currentExams, function ($exame) use ($examName) {
			return $exame->nome !== $examName;
		});
	
		// Atualiza o cache com o array filtrado
		\Cache::put($cacheKey, $updatedExams);

		$retorno               		= new stdClass;
		if (count($updatedExams) > 0)
		{
			$retorno->qtde 			= count($updatedExams);
		} else {
			$retorno->qtde 			= null;
		}

		return response()->json($retorno, 200);

	}

	public function pre_agendamento(Request $request, $id)
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
			'datas.*' 	=> 'nullable|date', // Certifique-se de que os elementos em 'datas' são datas válidas
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
		
		if (!isset($request->medicamento))
		{
			$request->medicamento					= "";
		}
		
		$agendamento->altura						= $request->altura;
		$agendamento->peso							= $request->peso;
		$agendamento->medicamento					= $request->medicamento;
		
		if (isset($asituacao->id))
		{
			$agendamento->observacao				= str_replace(array("<p>","</p>"),"",$asituacao->orientacao);
		} else {
			$agendamento->observacao				= "";
		}
		
		if ($agendamento->save())
		{
			Cas::enviarMensagemAgendamento($agendamento->id, $beneficiario->id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
			
			/*
			if ((Cas::nulltoSpace($asituacao->whatsappc) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
			{
				Cas::enviarMensagemAgendamento($agendamento->id, $beneficiario->id,$agendamento->clinica->telefone,$asituacao->whatsappc,$request->user()->id);
			}
			*/
			if ($agendamento->tipo == 'E')
			{
				foreach ($pedidos as $pedido)
				{
					$exame 								= \App\Models\ExamePedido::where('clinica_beneficiario_id','=',$agendamento->id)
																				 ->where('nome','=',$pedido->nome)
																				 ->first();
																			 
					if (!isset($exame->id))
					{
						$exame            				= new \App\Models\ExamePedido();
						$exame->clinica_beneficiario_id	= $agendamento->id;
						$exame->nome					= $pedido->nome;
						$exame->caminho 				= $pedido->url;
						$exame->save();
					}
				}
				\Cache::forget($chave);
			}
	
			$cleanedString 								= stripslashes($payload->datas);
			// Decodificar o JSON em um array PHP
			$dateArray 									= json_decode($cleanedString, true);
		
			Log::info('datas', ['datas' => $dateArray ]);
			 
			foreach ($dateArray  as $data)
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

	public function index_parcelas(Request $request, $id)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

		$beneficiario 		   								= \App\Models\Beneficiario::with('contrato')
																					  ->with('cliente')
																		 			  ->where('cliente_id','=',$id)
																		 			  ->orderBy('id','desc')
																		 			  ->first();
		
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
	
	public function cancelar_agendamento(Request $request, $id)
	{
		// Cancelamento de agendamento desabilitado para o App
		return response()->json(['mensagem' => 'Funcionalidade não disponível no aplicativo. Entre em contato com o suporte.'], 403);

		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }
		
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
					Cas::enviarMensagemAgendamento($agendamento->id,$agendamento->beneficiario_id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
					if ((Cas::nulltoSpace($asituacao->whatsappc) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
					{
						Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$asituacao->whatsappc,$request->user()->id);
					}
				}
			}
		}
		
		return response()->json($agendamento->id, 200);
	}
	
	public function confirmar_agendamento(Request $request, $id)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }
		
		$agendamento                              	= \App\Models\ClinicaBeneficiario::with('clinica')->find($id);

        if (!isset($agendamento->id))
        {
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		
		if (is_null($agendamento->agendamento_data_hora))
		{
			
			if (\App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$id)->count() > 1)
			{
				return response()->json(['mensagem' => 'Favor selecionar um horário disponível para que seja possível fazer o agendamento.'], 404);
			}
			$horario								= \App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$id)->first();
			if (!isset($horario->id))
			{
				return response()->json(['mensagem' => 'Favor selecionar um horário disponível para que seja possível fazer o agendamento.'], 404);
			}
			$agendamento->agendamento_data_hora 	= $horario->data_hora;
		}
		$agendamento->confirmado_data_hora			= date('Y-m-d H:i:s');
		$agendamento->confirmado_por				= $request->user()->id;
		$asituacao_id 								= $agendamento->asituacao_id;
		if ($agendamento->valor > 0)
		{
			$agendamento->asituacao_id 				= 3;
			$agendamento->forma 					= 'B';
		} else {
			$agendamento->asituacao_id 				= 7;
		}
		
		if ($agendamento->save())
		{
			if ($agendamento->valor > 0)
			{
				if (Cas::nulltoSpace($agendamento->galaxPayId) =="")
				{
					$cobranca							= CelCash::storeAgendamentoCharges($id);
					if ((isset($cobranca->ok)) and ($cobranca->ok == 'S'))
					{
						$beneficiario                   = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
						if (isset($beneficiario->id))
						{
							$asituacao 		   			= \App\Models\Asituacao::find($agendamento->asituacao_id);
							if (isset($asituacao->id))
							{
								Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
								if ((Cas::nulltoSpace($asituacao->whatsappc) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
								{
									Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$asituacao->whatsappc,$request->user()->id);
								}
							}
						}
						return response()->json(['mensagem' => 'Agendamento confirmado com sucesso!. Atenção!! Se o pagamento não for realizado até o vencimento o agendamento será cancelado'], 200);
					} else {
						$agendamento                    = \App\Models\ClinicaBeneficiario::with('clinica')->find($id);
						$agendamento->asituacao_id 	    = $asituacao_id;
						if ($agendamento->save())
						{
							$beneficiario               = \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
							if (isset($beneficiario->id))
							{
								$asituacao 		   		= \App\Models\Asituacao::find($agendamento->asituacao_id);
								if (isset($asituacao->id))
								{
									Cas::enviarMensagemAgendamento($agendamento->id,$agendamento->beneficiario_id,$beneficiario->cliente->telefone,$asituacao->whatsapp,$request->user()->id);
									if ((Cas::nulltoSpace($asituacao->whatsappc) !="") and (Cas::nulltoSpace($agendamento->clinica->telefone) !=""))
									{
										Cas::enviarMensagemAgendamento($agendamento->id, $agendamento->beneficiario_id,$agendamento->clinica->telefone,$asituacao->whatsappc,$request->user()->id);
									}
								}
							}
						}
					}
					Log::info('cobranca', ['cobranca' => $cobranca]);
				}
			} else {
				 return response()->json(['mensagem' => 'Agendamento confirmado com sucesso!'], 200);
			}
		}
		
		return response()->json(['mensagem' => 'Ocorreu erro na tentativa de fazer a confirmação'], 404);
	
	}
	
	public function selecionar_data(Request $request, $id)
	{
		
		$payload											= (object) $request->all();

		Log::info("request", ["ID:$id" => $payload ]);
		
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
            'selec' 				=> 'required',
        ]);

		if ($validator->fails()) 
		{
            return response()->json(['mensagem' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		DB::beginTransaction();
		
		$agendamento                              	= \App\Models\ClinicaBeneficiarioData::find($id);

        if (!isset($agendamento->id))
        {
			DB::rollBack();
            return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
        }
		if (($request->selec=='true') or ($request->selec ==1))
		{
			$agendamento->escolhido 				= 1;
		} else {
			$agendamento->escolhido 				= 0;
		}
		
		if ($agendamento->save())
		{
			if ($agendamento->escolhido == 1)
			{
				$cagendamento                      = \App\Models\ClinicaBeneficiario::find($agendamento->clinica_beneficiario_id);

				if (!isset($cagendamento->id))
				{
					DB::rollBack();
					return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
				}
				$cagendamento->agendamento_data_hora = $agendamento->data_hora;
				if (!$cagendamento->save())
				{
					DB::rollBack();
					return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
				}
			} 
			$restante							 = DB::table('clinica_beneficiario_datas')
														->where('clinica_beneficiario_id','=',$agendamento->clinica_beneficiario_id)
														->where('id','<>',$id)
														->update(['escolhido' => 0]);
			if ($agendamento->escolhido == 0)
			{
				if (\App\Models\ClinicaBeneficiarioData::where('clinica_beneficiario_id','=',$id)->where('escolhido','=',1)->count() == 0)
				{
					$cagendamento                      = \App\Models\ClinicaBeneficiario::find($agendamento->clinica_beneficiario_id);

					if (!isset($cagendamento->id))
					{
						DB::rollBack();
						return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
					}
					$cagendamento->agendamento_data_hora = null;
					if (!$cagendamento->save())
					{
						DB::rollBack();
						return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
					}
				}
			}
			DB::commit();
		} else {
			DB::rollBack();
			return response()->json(['mensagem' => 'Agendamento não encontrado.'], 404);
		}
	
		
		return response()->json(true, 200);
	}
	
	public function index_agendamentos(Request $request, $id)
	{
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }
		
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
			
			//$reg->tvouch						= false;
			
			$agendamentos[] 						= $reg;
		}
        return response()->json($agendamentos, 200);
	}
	
	public function view_agendamento(Request $request, $id)
    {
		
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['mensagem' => 'Não autorizado listar beneficiarios.'], 403);
        }

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
	
	public function verificarAdimplentePermissao(Request $request)
    {
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['error' => 'Não autorizado listar beneficiarios.'], 403);
        }	
		
		$validator = Validator::make($request->all(), [
			'beneficiario_id'       			=> 'required|exists:beneficiarios,id',
			'produto_id'						=> 'required|exists:produtos,id',
			
        ]);
		
		if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$beneficiario 		   					= \App\Models\Beneficiario::with('contrato')->find($request->beneficiario_id);
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['error' => 'Beneficiário não encontrado.'], 404);
		} 

		$pstatus								= 'A';

		$parcela 								= \App\Models\Parcela::where('contrato_id','=',$beneficiario->contrato_id)
																 	 ->where('data_pagamento','=',null)
																	 ->where('data_baixa','=',null)
																 	 ->where('data_vencimento','<',date('Y-m-d'))
																 	 ->orderBy('data_vencimento','asc')
																 	 ->first();
		if (isset($parcela->id))
		{
			$date 								= $parcela->data_vencimento. " 23:59:59";
			$vencimento 						= Carbon::createFromDate($date);
			$now 								= Carbon::now();
			$diferenca 							= $vencimento->diffInDays($now);
				
			if ($diferenca >= 9)
			{
				$pstatus						= 'I';
			}
		}

		if ($pstatus =='I')
		{
			list($ano,$mes,$dia) 				= explode("-",$parcela->data_vencimento);
			return response()->json(['error' => "Informamos que o benefício não foi liberado devido à existência de pendências de pagamento (vencido em: $dia/$mes/$ano). Regularize sua situação para reativar o serviço. Em caso de dúvidas, estamos à disposição."], 404);
		}
		
		$parcela 								= \App\Models\Parcela::where('contrato_id','=',$beneficiario->contrato_id)
																 	 ->where('data_pagamento','=',null)
																	 ->where('data_baixa','=',null)
																	 ->where('contrato_id','>', 5701)
																 	 ->where('nparcela','=',1)
																 	 ->first();
		if (isset($parcela->id))
		{
			return response()->json(['error' => "Olá, estamos felizes que voce está conosco. Pague a 1ª mensalidade para ativar o benefício. Em caso de dúvidas, estamos à disposição."], 404);
		}
		
		$permite								= Cas::permiteProdutoBeneficio($request->beneficiario_id,$request->produto_id);
		
		if ($permite->ok=='N')
		{
			return response()->json(['error' => $permite->mensagem], 404);
		}
		
		return response()->json(true, 200);
	}
	
	public function ativarGerarLinkMagico(Request $request)
    {
		
		if (!$request->user()->tokenCan('view.beneficiarios')) 
		{
            return response()->json(['error' => 'Não autorizado listar beneficiarios.'], 403);
        }	
		
		$validator = Validator::make($request->all(), [
			'beneficiario_id'       			=> 'required|exists:beneficiarios,id',
			'produto_id'						=> 'required|exists:produtos,id',
			
        ]);
		
		if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$beneficiario 		   					= \App\Models\Beneficiario::with('contrato')->find($request->beneficiario_id);
		
		if (!isset($beneficiario->id))
		{
			return response()->json(['error' => 'Beneficiário não encontrado.'], 404);
		} 

		$pstatus								= 'A';

		$parcela 								= \App\Models\Parcela::where('contrato_id','=',$beneficiario->contrato_id)
																 	 ->where('data_pagamento','=',null)
																	 ->where('data_baixa','=',null)
																 	 ->where('data_vencimento','<',date('Y-m-d'))
																 	 ->orderBy('data_vencimento','asc')
																 	 ->first();
		if (isset($parcela->id))
		{
			$date 								= $parcela->data_vencimento. " 23:59:59";
			$vencimento 						= Carbon::createFromDate($date);
			$now 								= Carbon::now();
			$diferenca 							= $vencimento->diffInDays($now);
				
			if ($diferenca >= 9)
			{
				$pstatus						= 'I';
			}
		}

		if ($pstatus =='I')
		{
			list($ano,$mes,$dia) 				= explode("-",$parcela->data_vencimento);
			return response()->json(['error' => "Informamos que o benefício não foi liberado devido à existência de pendências de pagamento (vencido em: $dia/$mes/$ano). Regularize sua situação para reativar o serviço. Em caso de dúvidas, estamos à disposição."], 404);
		}
		
		$beneficiario_id							= $request->beneficiario_id;
		$permite									= Cas::permiteProdutoBeneficio($request->beneficiario_id,$request->produto_id);
		
		if ($permite->ok=='N')
		{
			if (($beneficiario->tipo == 'D') and ($request->produto_id == 2))
			{
				if ($beneficiario->contrato->tipo == 'J')
				{
					$beneficiario_id				= $beneficiario->parent_id;
				} else {
					$contrato_id                    = $beneficiario->contrato_id;
					$beneficiario 		   			= \App\Models\Beneficiario::where('contrato_id','=',$contrato_id)
																			  ->where('tipo','=','T')
																			  ->where('ativo','=',1)
																			  ->first();
		
					if (isset($beneficiario->id))
					{
						$beneficiario_id			= $beneficiario->id;
					}
				}
			} else {
				return response()->json(['error' => $permite->mensagem], 404);
			}
		}
		
		$beneficiarioproduto  						= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario_id)
																				 ->where('produto_id','=',$request->produto_id)
																				 ->first();		
																				  
		if ((!isset($beneficiarioproduto->id)) or ($beneficiarioproduto->ativacao==0))
		{
			switch ($request->produto_id) 
			{
				case 2: /* Clube Certo*/
					$associate 						= ClubeCerto::ativarDesativarBeneficiario($beneficiario_id,$request->produto_id,true);
					if ($associate->ok !='S')
					{
						return response()->json(['error' => $associate->mensagem], 404);
					}
					break;
				case 4: /* Telemedicina (Conexa)*/
					$associate 						= Conexa::ativarDesativarBeneficiario($beneficiario_id,$request->produto_id,true);
					if ($associate->ok !='S')
					{
						return response()->json(['error' => $associate->mensagem], 404);
					}
					break;
			}
		}
		
		$ipAddress 								= $request->ip();
		$magiclink 								= Cas::gerarLinkMagico($beneficiario_id, $request->produto_id, $ipAddress);
		
		return response()->json($magiclink, 200);
	}
}
