<?php

namespace App\Helpers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\ConnectException;
use Exception;
use App\Helpers\Cas;
use Carbon\Carbon;
use DB;
use stdClass;
 
class Conexa
{
	
	public static function ativarDesativarBeneficiario($beneficiario_id,$produto_id,$ativar=true)
	{
		if ($ativar)
		{
			$associate 									= Conexa::associateBeneficiario($beneficiario_id,$produto_id);
			if ($associate->ok == 'S')
			{
				$associate								= Cas::ativarDesativarProduto($beneficiario_id,$produto_id,$ativar,$associate->object->id);
			}
			return $associate;
		} else {
			$beneficiarioproduto  						= \App\Models\BeneficiarioProduto::with('beneficiario')
																						 ->where('beneficiario_id','=',$beneficiario_id)
																						 ->where('produto_id','=',$produto_id)
																						 ->first();		
			if (!isset($beneficiarioproduto))
			{
				$retorno 								= new stdClass();
				$retorno->ok 							= 'N';
				$retorno->mensagem 						= "É necessário que o Beneficiário esteja ativado";
				return $retorno;
			}
			
			$beneficiario                   			= \App\Models\Beneficiario::with('contrato')->find($beneficiario_id);
			
			if (!isset($beneficiario->id))
			{
				$plano_id								= 0;
			} else {
				$tipo                          			= $beneficiario->contrato->tipo ?? 'F';
				if ($tipo == 'F')
				{
					$plano_id 							= $beneficiario->contrato->plano_id ?? 0;
				} else {
					if ($beneficiario->tipo == 'T')
					{
						$plano_id 						= $beneficiario->plano_id;
					} else {
						$dbeneficiario                  = \App\Models\Beneficiario::where('id','=',$beneficiario->parent_id)->first();
						if (isset($dbeneficiario->id))
						{	
							$plano_id 					= $dbeneficiario->plano_id;
						} else {
							$plano_id					= 0;
						}
					}
				}
			}
			
			$inactivate 								= Conexa::inactivate($beneficiarioproduto->idintegracao, $plano_id);
			if ($inactivate->ok =='S')
			{
				$associate								= Cas::ativarDesativarProduto($beneficiario_id,$produto_id,$ativar,$beneficiarioproduto->idintegracao);
				/*
				if (($associate->ok == 'S') and ($beneficiarioproduto->beneficiario->tipo =='T'))
				{
					
				}
				*/
				return $associate;
			}
			
			return $inactivate;
		}
		
	}
	
	public static function associateBeneficiario($id,$produto_id=4)
    {
		$retorno 						= new stdClass();
        $retorno->ok                    = "N";

		$beneficiario                   = \App\Models\Beneficiario::with('contrato')->find($id);

        if (!isset($beneficiario->id))
        {
            $retorno->mensagem 			= 'Beneficiário não encontrado';
			return $retorno;
        }
		
		$permite						= Cas::permiteProdutoBeneficio($id,$produto_id);
		if ($permite->ok=='N')
		{
			$retorno->mensagem 			= $permite->mensagem;
			return $retorno;
		}
			
		$payload 						= new stdClass();
		$payload->name					= $beneficiario->cliente->nome;
		if (Cas::nulltoSpace($beneficiario->cliente->email) == "")
		{
			$beneficiario->cliente->email	= "sememail@cartaoamigosaude.com.br";
		}
		
		list($ano,$mes,$dia)            = explode("-",$beneficiario->cliente->data_nascimento);
		
		if (Cas::isValidEmail($beneficiario->cliente->email)) 
		{
			$payload->mail				= $beneficiario->cliente->email;
		} else {
			$payload->mail				= "sememail@cartaoamigosaude.com.br";
		}
		
		$payload->cpf 					= $beneficiario->cliente->cpfcnpj;
		$payload->dateBirth				= "$dia/$mes/$ano";
		if ($beneficiario->cliente->sexo =='M')
		{
			$payload->sex 				= 'MALE';
		} else {
			$payload->sex 				= 'FEMALE';
		}
		$telefone 								= preg_replace('/\D/', '', $beneficiario->cliente->telefone);
		$payload->cellphone						= $telefone;
		
		$address								= new stdClass();
		$address->additionalAddressDetails		= "";
		$address->city							= $beneficiario->cliente->cidade;
		$address->region						= $beneficiario->cliente->bairro;
		$address->country						= "Brasil";
		$address->state							= $beneficiario->cliente->estado;
		$address->streetAddress					= $beneficiario->cliente->logradouro;
		$address->numberAddress					= $beneficiario->cliente->numero;
		$address->zipCode						=  preg_replace('/\D/', '', $beneficiario->cliente->cep);
		
		if ($beneficiario->tipo == 'D')
		{
			$beneficiarioproduto  				= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario->parent_id)
																				 ->where('produto_id','=',$produto_id)
																				 ->first();		
			if (!isset($beneficiarioproduto))
			{
				if ($beneficiario->contrato->tipo =='J')
				{
					$retorno->mensagem 			= "É necessário que o Titular do Contrato seja integrado primeiro";
					return $retorno;
				} else {
					$cbeneficiario      		= \App\Models\Beneficiario::where('contrato_id','=',$beneficiario->contrato->id)
																  ->where('tipo','=','T')
																  ->first();
					if (!isset($cbeneficiario->id))
					{
						$retorno->mensagem 		= "Não foi encontrado o titular no contrato de dependente. Entre em contato com o suporte";
						return $retorno;
					}
					$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$cbeneficiario->id)
																		 ->where('produto_id','=',$produto_id)
																		 ->first();		
					if (!isset($beneficiarioproduto))
					{
						$retorno->mensagem 		= "É necessário que o Titular do Contrato seja integrado primeiro";
						return $retorno;
					}
				}
			}
			$payload->idNationality				= 1;
			$payload->address					= $address;
			$payload->patientHolderId			= $beneficiarioproduto->idintegracao;
			$payload->kinshipOfTheHolder		= 'OUTROS';
		}
		
		$tipo                          			= $beneficiario->contrato->tipo ?? 'F';
		
		if ($tipo == 'F')
		{
			$payload->plano_id 					= $beneficiario->contrato->plano_id ?? 0;
		} else {
			if ($beneficiario->tipo == 'T')
			{
				$payload->plano_id 				= $beneficiario->plano_id;
			} else {
				$dbeneficiario                  = \App\Models\Beneficiario::where('id','=',$beneficiario->parent_id)->first();
				if (isset($dbeneficiario->id))
				{	
					$payload->plano_id 			= $dbeneficiario->plano_id;
				} else {
					$payload->plano_id			= 0;
				}
			}
		}
				
		$associate								= Conexa::createOrUpdatePatient($payload);
		
		if ($associate->ok == 'S')
		{
			$activate 							= Conexa::activate($associate->object->id,$payload->plano_id);
			if ($activate->ok == 'S')
			{
				return $associate;
			} else {
				return $activate;
			}
		}
		
		$retorno->mensagem 						= $associate->mensagem;
		return $retorno;
		
	}
	
	public static function Token($sandbox=true,$plano_id=0)
    {
		
		if ($plano_id ==0)
		{
			$retorno 						= new stdClass();
			$retorno->token 				= "c9c86cb4b5bb6f8071847b4044c37a0c";
		    $retorno->url 					= config('services.conexa.api_url') . '/integration/enterprise';
			return 	$retorno; 
		}
		
		$token_loja							= "";
		
		$plano              				= \App\Models\Plano::select('id','token_loja')->find($plano_id);
					
		if (isset($plano->id))
		{
			$token_loja 					= $plano->token_loja;
		}
		
		$retorno 							= new stdClass();
		$retorno->url 						= "";	
		
		if (($token_loja =="") or ($token_loja =="c9c86cb4b5bb6f8071847b4044c37a0c"))
		{
			$retorno->token 				= "c9c86cb4b5bb6f8071847b4044c37a0c";
			$retorno->url 					= config('services.conexa.api_url') . '/integration/enterprise';
			return 	$retorno; 	
		}
		
		$retorno->token 					= $token_loja;
		$retorno->url 						= config('services.conexa.api_url') . '/integration/enterprise';
		return 	$retorno; 	
		
		/**
		if ($sandbox)
		{
			 $retorno->token 				= "c4568208cf1f8ff8299e0c0e6d9d5c99";
		     $retorno->url 					= config('services.conexa.api_url_hml') . '/integration/enterprise';
		} else {
			$retorno->token 				= "c9c86cb4b5bb6f8071847b4044c37a0c";
		    $retorno->url 					= config('services.conexa.api_url') . '/integration/enterprise';
		}
		*/
						
	}
	
	public static function listarPacientes($pagina=1,$plano_id=0)
    {
				
        $token 								= Conexa::Token(false, $plano_id); /* sandbox=true*/
		$url 								= $token->url . "/patients/list/$pagina";
		
        $response 							= Http::withHeaders(['token' => $token->token])->get($url);

        if ($response->successful()) 
		{
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
			$retorno->status 				= $response->json('status') ?? $response->status();
            $retorno->mensagem 				= $response->json('msg') ?? 'Erro desconhecido';
        }
		
		return $retorno;
    }
	
	public static function buscarPaciente($cpf,$plano_id=0)
    {
		$cpf								= preg_replace('/\D/', '',$cpf);
        $cpf 								= str_pad($cpf, 11, '0', STR_PAD_LEFT);
				
        $token 								= Conexa::Token(false,$plano_id); /* sandbox=true*/
		$url 								= $token->url . "/patients/cpf/{$cpf}";
		
        $response 							= Http::withHeaders(['token' => $token->token])->get($url);

        if ($response->successful()) 
		{
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
			$retorno->status 				= $response->json('status') ?? $response->status();
            $retorno->mensagem 				= $response->json('msg') ?? 'Erro desconhecido';
        }
		
		return $retorno;
    }
	
	public static function buscarPacienteStatus($cpf,$plano_id=0)
    {
		$cpf								= preg_replace('/\D/', '',$cpf);
        $cpf 								= str_pad($cpf, 11, '0', STR_PAD_LEFT);
		
		$token 								= Conexa::Token(false,$plano_id); /* sandbox=true*/
		$url 								= $token->url . "/patients/status/cpf/{$cpf}";
		
        $response 							= Http::withHeaders(['token' => $token->token])->get($url);

        if ($response->successful()) 
		{
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
			$retorno->status 				= $response->json('status') ?? $response->status();
            $retorno->mensagem 				= $response->json('msg') ?? 'Erro desconhecido';
        }
		
		return $retorno;
	}
	
	public static function createOrUpdatePatient($payload)
    {
         $token 							= Conexa::Token(false,$payload->plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/patients";
		 
		 try {
			$response = Http::withHeaders([
				'token' => $token->token,
				'Content-Type' => 'application/json',
			])->timeout(60) // Define um tempo maior para timeout, se necessário
			  ->post($url, $payload);

			// Logando o retorno da resposta
			Log::info("createOrUpdatePatient Response", ['response' => $response->object()]);

			if ($response->successful()) {
				$retorno 			= $response->object();
				$retorno->ok 		= 'S';
			} else {
				$retorno 			= new stdClass();
				$retorno->ok 		= 'N';
				$retorno->status 	= $response->json('status') ?? $response->status();
				$retorno->mensagem 	= $response->json('msg') ?? 'Não foi possível criar ou atualizar o paciente.';
				$retorno->payload 	= $payload;
				Log::error("createOrUpdatePatient Error", ['response' => $response->body(), 'payload' => $payload]);
			}
		} catch (ConnectException $e) {
			// Timeout ou problemas de conexão
			Log::error("createOrUpdatePatient Connection Error", [
				'message' => $e->getMessage(),
				'payload' => $payload,
			]);
			$retorno 			= new stdClass();
			$retorno->ok 		= 'N';
			$retorno->status 	= 0; // Indica erro de conexão
			$retorno->mensagem 	= 'Erro de conexão: não foi possível alcançar o servidor.';
		} catch (Exception $e) {
			// Captura outras exceções
			Log::error("createOrUpdatePatient General Error", [
				'message' => $e->getMessage(),
				'payload' => $payload,
			]);
			$retorno 			= new stdClass();
			$retorno->ok 		= 'N';
			$retorno->status 	= $e->getCode();
			$retorno->mensagem 	= 'Ocorreu um erro inesperado: ' . $e->getMessage();
		}		 

		return $retorno;    
	}
	
	public static function activate($id,$plano_id)
    {	
         $token 							= Conexa::Token(false,$plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/v2/patients/$id/activate";
        // Enviando a requisição POST
        $response = Http::withHeaders([
            'token' => $token->token,
            'Content-Type' => 'application/json',
		])->post($url, []);

        // Verificando o resultado da requisição
		
		Log::info("activate", ['response'=> $response->object()]);
		
        if ($response->successful()) {
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
            $retorno->status 				= $response->json('status') ?? $response->status();
			$retorno->mensagem				= 'Não foi possível ativar o paciente.';
            $retorno->details 				= $response->json('msg') ?? 'Erro desconhecido';
			$retorno->payload				= $url;
        }
		return $retorno;
    }
	
	public static function inactivate($id,$plano_id)
    {	
         $token 							= Conexa::Token(false,$plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/v2/patients/$id/block";
        // Enviando a requisição POST
        $response = Http::withHeaders([
            'token' => $token->token,
            'Content-Type' => 'application/json',
		])->post($url, []);

        // Verificando o resultado da requisição
		
		Log::info("inactivate", ['response'=> $response->object()]);
		
        if ($response->successful()) {
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
            $retorno->status 				= $response->json('status') ?? $response->status();
			$retorno->mensagem				= 'Não foi possível inativar o paciente.';
            $retorno->details 				= $response->json('msg') ?? 'Erro desconhecido';
			$retorno->payload				= $url;
        }
		return $retorno;
    }
	
	public static function bloquear($id,$plano_id)
    {	
         $token 							= Conexa::Token(false,$plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/v2/patients/$id/block";
        // Enviando a requisição POST
        $response = Http::withHeaders([
            'token' => $token->token,
            'Content-Type' => 'application/json',
		])->post($url, []);

        // Verificando o resultado da requisição
		
		Log::info("inactivate", ['response'=> $response->object()]);
		
        if ($response->successful()) {
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
            $retorno->status 				= $response->json('status') ?? $response->status();
			$retorno->mensagem				= 'Não foi possível inativar o paciente.';
            $retorno->details 				= $response->json('msg') ?? 'Erro desconhecido';
			$retorno->payload				= $url;
        }
		return $retorno;
    }
	
	public static function desbloquear($id,$plano_id)
    {	
         $token 							= Conexa::Token(false,$plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/v2/patients/$id/unblock";
        // Enviando a requisição POST
        $response = Http::withHeaders([
            'token' => $token->token,
            'Content-Type' => 'application/json',
		])->post($url, []);

        // Verificando o resultado da requisição
		
		Log::info("inactivate", ['response'=> $response->object()]);
		
        if ($response->successful()) {
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
            $retorno->status 				= $response->json('status') ?? $response->status();
			$retorno->mensagem				= 'Não foi possível inativar o paciente.';
            $retorno->details 				= $response->json('msg') ?? 'Erro desconhecido';
			$retorno->payload				= $url;
        }
		return $retorno;
    }
	
	public static function acceptTerm($payload)
    {	
         $token 							= Conexa::Token(false,$payload->plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/patients/accept/term";
        // Enviando a requisição POST
        $response = Http::withHeaders([
            'token' 		=> $token->token,
            'Content-Type' 	=> 'application/json',
		])->post($url, $payload);

        // Verificando o resultado da requisição
		
		Log::info("acceptTerm", ['response'=> $response->object()]);
		
        if ($response->successful()) {
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
            $retorno->status 				= $response->json('status') ?? $response->status();
			$retorno->mensagem				= 'Não foi registrar o termo do paciente.';
            $retorno->details 				= $response->json('msg') ?? 'Erro desconhecido';
			$retorno->payload				= $payload;
        }
		return $retorno;
    }
	
	//https://hml-api.conexasaude.com.br/integration/enterprise/patients/{id}/term/accept
	
	public static function termAccept($id,$plano_id)
    {	
         $token 							= Conexa::Token(false,$plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/patients/$id/term/accept";
        // Enviando a requisição POST
        $response = Http::withHeaders([
            'token' => $token->token,
            'Content-Type' => 'application/json',
		])->get($url);

        // Verificando o resultado da requisição
		
		Log::info("termAccept", ['response'=> $response->object()]);
		
        if ($response->successful()) {
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
            $retorno->status 				= $response->json('status') ?? $response->status();
			$retorno->mensagem				= 'Não foi registrar o termo do paciente.';
            $retorno->details 				= $response->json('msg') ?? 'Erro desconhecido';
			$retorno->payload				= $url;
        }
		return $retorno;
    }
	
	//https://hml-api.conexasaude.com.br/integration/enterprise/patients/generate-magiclink-access-app/{id}
	
	public static function generateMagicLinkAccessapp($id,$plano_id)
    {	
         $token 							= Conexa::Token(false,$plano_id); /* sandbox=true*/
		 $url 								= $token->url . "/patients//generate-magiclink-access-app/$id";
        // Enviando a requisição POST
        $response = Http::withHeaders([
            'token' => $token->token,
            'Content-Type' => 'application/json',
		])->get($url);

        // Verificando o resultado da requisição
		
		Log::info("generateMagicLinkAccessapp", ['response'=> $response->object()]);
		
        if ($response->successful()) {
            $retorno						= $response->object();
			$retorno->ok 					= 'S';
        } else {
			$retorno 						= new stdClass();
			$retorno->ok 					= 'N';
            $retorno->status 				= $response->json('status') ?? $response->status();
			$retorno->mensagem				= 'Não foi registrado o termo do paciente.';
            $retorno->details 				= $response->json('msg') ?? 'Erro desconhecido';
			$retorno->payload				= $url;
        }
		return $retorno;
    }
	
	// Integração Full - Pronto Atendimento

		public static function criarAtendimentoImediato($payload, $plano_id = 0)
		{
			$token 							= Conexa::Token(false, $plano_id);
			$url 							= $token->url . '/appointment/immediate';

			$response 						= Http::withHeaders(['token' => $token->token])->post($url, $payload);

			if ($response->successful()) {
				$retorno 					= $response->object();
				$retorno->ok 				= 'S';
			} else {
				$retorno 					= new stdClass();
				$retorno->ok 				= 'N';
				$retorno->status 			= $response->json('status') ?? $response->status();
				$retorno->mensagem 			= $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function anexarArquivoAtendimento($idProtocol, $payload, $plano_id = 0)
		{
			$token 							= Conexa::Token(false, $plano_id);
			$url 							= $token->url . "/appointment/immediate/attach-file/{$idProtocol}";

			$response 						= Http::withHeaders(['token' => $token->token])->post($url, $payload);

			if ($response->successful()) {
				$retorno 					= $response->object();
				$retorno->ok 				= 'S';
			} else {
				$retorno 					= new stdClass();
				$retorno->ok 				= 'N';
				$retorno->status 			= $response->json('status') ?? $response->status();
				$retorno->mensagem 			= $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function obterAtendimentoImediatoPaciente($patientId, $plano_id = 0)
		{
			$token 							= Conexa::Token(false, $plano_id);
			$url 							= $token->url . "/appointment/immediate/active/{$patientId}";

			$response 						= Http::withHeaders(['token' => $token->token])->get($url);

			if ($response->successful()) {
				$retorno 					= $response->object();
				$retorno->ok 				= 'S';
			} else {
				$retorno 					= new stdClass();
				$retorno->ok 				= 'N';
				$retorno->status 			= $response->json('status') ?? $response->status();
				$retorno->mensagem 			= $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function cancelarAtendimentoImediato($patientId, $plano_id = 0)
		{
			$token 							= Conexa::Token(false, $plano_id);
			$url 							= $token->url . "/appointment/immediate/cancel/{$patientId}";

			$response 						= Http::withHeaders(['token' => $token->token])->post($url);

			if ($response->successful()) {
				$retorno 					= $response->object();
				$retorno->ok 				= 'S';
			} else {
				$retorno 					= new stdClass();
				$retorno->ok 				= 'N';
				$retorno->status 			= $response->json('status') ?? $response->status();
				$retorno->mensagem 			= $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function obterUltimaChamada($patientId, $plano_id = 0)
		{
			$token = Conexa::Token(false, $plano_id);
			$url = $token->url . "/v2/appointment/last/call/{$patientId}";

			$response = Http::withHeaders(['token' => $token->token])->get($url);

			if ($response->successful()) {
				$retorno = $response->object();
				$retorno->ok = 'S';
			} else {
				$retorno = new stdClass();
				$retorno->ok = 'N';
				$retorno->status = $response->json('status') ?? $response->status();
				$retorno->mensagem = $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}


		// Integração Full - Agendado com Especialidade Médica

		public static function listarEspecialidades($plano_id = 0,$encaixe=false)
		{
			$token 						= Conexa::Token(false, $plano_id);
			$url 						= $token->url . '/appointment/specialties';

			$response 					= Http::withHeaders(['token' => $token->token])->get($url);
			$retorno 					= new stdClass();
			if ($response->successful()) 
			{
				$lespecialidades         = $response->object();
				$especialidades			 = array();
				foreach ($lespecialidades as $especialidade)
				{
					$especialidade->encaixe 	= false;
					$especialidades[] 			= $especialidade;
				}
				$retorno->object 		= $especialidades;
				$retorno->ok 			= 'S';
			} else {
				$retorno->ok 			= 'N';
				$retorno->status 		= $response->json('status') ?? $response->status();
				$retorno->mensagem 		= $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function listarMedicosPorEspecialidade($specialtyId, $page, $name = null, $patientId = null, $sortType = null, $plano_id = 0)
		{
			$token 						= Conexa::Token(false, $plano_id);
			$url 						= $token->url . "/doctors/specialty/{$specialtyId}/{$page}";
			$queryParams 				= array_filter(compact('name', 'patientId', 'sortType'));

			$response 					= Http::withHeaders(['token' => $token->token])->get($url, $queryParams);

			if ($response->successful()) {
				$retorno 				= $response->object();
				$retorno->ok 			= 'S';
			} else {
				$retorno 				= new stdClass();
				$retorno->ok 			= 'N';
				$retorno->status 		= $response->json('status') ?? $response->status();
				$retorno->mensagem 		= $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function obterHorariosDisponiveisMedico($doctorId, $startDate, $endDate, $plano_id = 0)
		{
			$token 					= Conexa::Token(false, $plano_id);
			$url 					= $token->url . "/doctors/" . $doctorId . "/schedule/available";
			
			$inicio 				= Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
			$fim    				= Carbon::createFromFormat('Y-m-d', $endDate)->startOfDay();
			
			// Segurança: se fim < inicio, inverte ou retorna erro (aqui vou inverter)
			if ($fim->lt($inicio)) 
			{
				[$inicio, $fim] 	= [$fim, $inicio];
			}
			
			$cursor 				= $inicio->copy();         // startDate corrente da API
			$maxIteracoes 			= 120;                     // fail-safe: evita travar
			$iter 					= 0;
			$agendas 				= array();
			$agendasPorData		 	= array();
			
			while ($cursor->lte($fim)) 
			{
				
				$iter++;
				if ($iter > $maxIteracoes) 
				{
					break; // evita “loop infinito” 
				}
		
				Log::info("startDate", ['startDate'=> $cursor->format('d/m/Y')]);
		
				$response = Http::withHeaders(['token' => $token->token])->get($url, [
					'startDate' => $cursor->format('d/m/Y')
				]);

				if (!$response->successful()) 
				{
					break;
				}
				
				$items			= array();
				$obj 			= $response->object();
				$items 			= $obj->object ?? $obj ?? [];
				
				// A API pode retornar array ou objeto; aqui tentamos capturar o "miolo"
				if (is_object($items)) $items = (array) $items;
				if (!is_array($items)) $items = [];

				// Se a API retornar vazio, avança 4 dias para não ficar preso
				if (count($items) === 0) 
				{
					$cursor->addDays(4);
					continue;
				}

				// Achar a "última data" no retorno (max date) pra continuar dali
				// Processa e agrega dentro do intervalo
				$ultimaDataRetornada = null;
				
				Log::info("items", ['items'=> $items]);
				
				foreach ($items as $it) 
				{
					if (is_object($it)) $it = (array) $it;
					if (!is_array($it)) continue;

					$dateStr = $it['date'] ?? null;
					if (!$dateStr) continue;

					// date vem como d/m/Y
					try {
						$dia = Carbon::createFromFormat('d/m/Y', $dateStr)->startOfDay();
					} catch (\Throwable $e) {
						continue;
					}

					// Mantém a maior data retornada
					if (!$ultimaDataRetornada || $dia->gt($ultimaDataRetornada)) {
						$ultimaDataRetornada = $dia->copy();
					}

					// Filtra pelo intervalo solicitado
					if ($dia->lt($inicio) || $dia->gt($fim)) {
						continue;
					}

					$times = $it['availableTimes'] ?? [];
					if (!is_array($times)) $times = [];

					// Dedup por data + merge de horários (sem duplicar horários)
					if (!isset($agendasPorData[$dateStr])) {
						$agendasPorData[$dateStr] = [
							'date' => $dateStr,
							'availableTimes' => [],
						];
					}

					$agendasPorData[$dateStr]['availableTimes'] = array_values(array_unique(array_merge(
						$agendasPorData[$dateStr]['availableTimes'],
						$times
					)));
					
					Log::info("agendasPorData", ['agendasPorData'=> $agendasPorData]);
				}

				// Se não conseguiu descobrir a última data, avança 4 dias (fallback)
				if (!$ultimaDataRetornada) 
				{
					$cursor->addDays(4);
					continue;
				}

				// Próxima página começa no dia seguinte ao último dia retornado
				$proximoCursor = $ultimaDataRetornada->copy()->addDay()->startOfDay();

				// Proteção extra anti-loop: garante avanço real
				if ($proximoCursor->lte($cursor)) 
				{
					$proximoCursor = $cursor->copy()->addDay();
				}

				$cursor = $proximoCursor;
			}

			// Retorna em array "normal", ordenado por data asc
			$agendas = array_values($agendasPorData);

			usort($agendas, function ($a, $b) {
				$da = Carbon::createFromFormat('d/m/Y', $a['date']);
				$db = Carbon::createFromFormat('d/m/Y', $b['date']);
				if ($da->eq($db)) return 0;
				return $da->lt($db) ? -1 : 1;
			});
			
			$retorno 				= new stdClass();
			$retorno->object 		= $agendas;
			$retorno->ok 			= 'S';
			return $retorno;
		}

		public static function criarAgendamentoMedico($payload, $plano_id = 0)
		{
			$token = Conexa::Token(false, $plano_id);
			$url = $token->url . '/v2/appointment/scheduled/simple?professionalType=DOCTOR';

			$response = Http::withHeaders(['token' => $token->token])->post($url, $payload);

			if ($response->successful()) {
				$retorno = $response->object();
				$retorno->ok = 'S';
			} else {
				$retorno = new stdClass();
				$retorno->ok = 'N';
				$retorno->status = $response->json('status') ?? $response->status();
				$retorno->mensagem = $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}


		// Integração Full - Agendado com Outras Especialidades

		public static function listarProfissionaisSaudePorNome($page, $professionalType, $patientId, $name = null, $theme = null, $occupationArea = null, $specialty = null, $approach = null, $ageRange = null, $searchByTriage = null, $executeCount = null, $sortType = null, $plano_id = 0)
		{
			$token = Conexa::Token(false, $plano_id);
			$url = $token->url . "/v2/healthcare-professionals/name/{$page}";
			$queryParams = array_filter(compact(
				'professionalType', 'patientId', 'name', 'theme', 'occupationArea', 'specialty', 'approach', 'ageRange', 'searchByTriage', 'executeCount', 'sortType'
			));

			$response = Http::withHeaders(['token' => $token->token])->get($url, $queryParams);

			if ($response->successful()) {
				$retorno = $response->object();
				$retorno->ok = 'S';
			} else {
				$retorno = new stdClass();
				$retorno->ok = 'N';
				$retorno->status = $response->json('status') ?? $response->status();
				$retorno->mensagem = $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function obterHorariosDisponiveisProfissional($id, $startDate, $professionalType, $patientId = null, $plano_id = 0)
		{
			$token = Conexa::Token(false, $plano_id);
			$url = $token->url . "/v2/healthcare-professionals/{$id}/schedule/available";
			$queryParams = array_filter(compact('startDate', 'professionalType', 'patientId'));

			$response = Http::withHeaders(['token' => $token->token])->get($url, $queryParams);

			if ($response->successful()) {
				$retorno = $response->object();
				$retorno->ok = 'S';
			} else {
				$retorno = new stdClass();
				$retorno->ok = 'N';
				$retorno->status = $response->json('status') ?? $response->status();
				$retorno->mensagem = $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		public static function criarAgendamentoProfissional($payload, $professionalType, $plano_id = 0)
		{
			$token = Conexa::Token(false, $plano_id);
			$url = $token->url . "/v2/appointment/scheduled/simple?professionalType={$professionalType}";

			$response = Http::withHeaders(['token' => $token->token])->post($url, $payload);

			if ($response->successful()) {
				$retorno = $response->object();
				$retorno->ok = 'S';
			} else {
				$retorno = new stdClass();
				$retorno->ok = 'N';
				$retorno->status = $response->json('status') ?? $response->status();
				$retorno->mensagem = $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

		// Avaliação

		public static function salvarAvaliacaoAtendimento($payload, $plano_id = 0)
		{
			$token = Conexa::Token(false, $plano_id);
			$url = $token->url . '/nps/save';

			$response = Http::withHeaders(['token' => $token->token])->post($url, $payload);

			if ($response->successful()) {
				$retorno = $response->object();
				$retorno->ok = 'S';
			} else {
				$retorno = new stdClass();
				$retorno->ok = 'N';
				$retorno->status = $response->json('status') ?? $response->status();
				$retorno->mensagem = $response->json('msg') ?? 'Erro desconhecido';
			}

			return $retorno;
		}

}