<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use stdClass;
use Exception;

class Epharma
{
    /**
     * Obtém as credenciais da API ePharma
     */
    public static function obterCredenciais(): array
    {
        return [
            'base_url' 	=> config('services.epharma.api_url'),
            'client_id' => "etgtIB0O43saFI24MRkm2o3mr3l35UY",
            'username' 	=> "aaa21609-0816-43bc-a07d-309349b0d788",
            'password' 	=> "aaa21609-0816-43bc-a07d-309349b0d788",
            'api_version' => "v3", // Versão da API
        ];
    }

    /**
     * Obtém o token de autenticação OAuth 2.0
     */
    public static function obterToken(): string
    {
        // Verifica se já existe um token em cache
        //$cachedToken = Cache::get('epharma_access_token');
        //if ($cachedToken) {
        //    return $cachedToken;
        //}

        $credenciais = self::obterCredenciais();
        
        if (empty($credenciais['client_id']) || empty($credenciais['username']) || empty($credenciais['password'])) {
            throw new Exception('Credenciais da API ePharma não configuradas');
        }

        try {
            $response = Http::asForm()
                ->post($credenciais['base_url'] . '/oauth/token', [
                    'grant_type' => 'password',
                    'client_id' => $credenciais['client_id'],
                    'username' => $credenciais['username'],
                    'password' => $credenciais['password'],
                ]);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('ePharma Token Error', [
                    'status' => $response->status(),
                    'body' => $errorBody
                ]);
                
                throw new Exception("Erro ao obter token ePharma [{$response->status()}]: {$errorBody}");
            }

            $data = $response->json();
            $token = $data['access_token'];
            
            // Calcula o tempo de expiração (expires_in - 60 segundos de margem)
            $expiresIn = ($data['expires_in'] ?? 7200) - 60;
            
            // Armazena o token no cache
            Cache::put('epharma_access_token', $token, $expiresIn);
            
            Log::info('ePharma Token obtido com sucesso', [
                'expires_in' => $data['expires_in'],
                'token_type' => $data['token_type']
            ]);

            return $token;

        } catch (Exception $e) {
            Log::error('ePharma Token Exception', [
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Realiza chamada genérica para API ePharma
     */
    public static function chamarApi(string $endpoint, array $data = [], string $method = 'POST'): array
    {
        try {
            $credenciais = self::obterCredenciais();
            $url = $credenciais['base_url'] . $endpoint;
            $token = self::obterToken();
            
            Log::info('ePharma API Call', [
                'token' => $token
            ]);

            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$token}"
            ];

            Log::info('ePharma API Call', [
                'method' => $method,
                'url' => $url,
                'data' => $data
            ]);

            $response = match (strtoupper($method)) {
                'GET' => Http::withHeaders($headers)->get($url),
                'POST' => Http::withHeaders($headers)->post($url, $data),
                'PUT' => Http::withHeaders($headers)->put($url, $data),
                'DELETE' => Http::withHeaders($headers)->delete($url),
                default => throw new Exception("Método HTTP não suportado: {$method}")
            };

            if ((!$response->successful()) && ($response->status() != 400)) {
                $errorBody = $response->body();
                Log::error('ePharma API Error', [
                    'status' => $response->status(),
                    'body' => $errorBody,
                    'url' => $url
                ]);
                
                throw new Exception("Erro na API ePharma [{$response->status()}]: {$errorBody}");
            }

            $responseData = $response->json();
            
            Log::info('ePharma API Success', [
                'url' => $url,
                'response' => $responseData
            ]);

            return $responseData;

        } catch (Exception $e) {
            Log::error('ePharma API Exception', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Cadastra ou atualiza um beneficiário
     */
    public static function cadastrarBeneficiario(array $dadosBeneficiario, int $plano): array
    {
		 Log::info('ePharma API Success', [
                'dadosBeneficiario' => $dadosBeneficiario
            ]);
			
        return self::chamarApi("/api/ManutencaoBeneficiario/BeneficiariosCartaoCliente", $dadosBeneficiario, 'POST');
    }

    /**
     * Monta a estrutura de campos do beneficiário
     */
    public static function montarCamposBeneficiario(array $dados): array
    {
        $campos = [];

        // Mapeamento dos campos conforme documentação
        $mapeamento = [
            'nome' => 3,
            'data_inicio_vigencia' => 4,
            'data_fim_vigencia' => 5,
            'cartao_usuario' => 6,
            'cartao_titular' => 7,
            'data_nascimento' => 9,
            'genero' => 10,
            'uf' => 17,
            'cidade' => 16,
            'cep' => 18,
            'celular' => 21,
            'status' => 25,
            'cpf' => 28,
            'data_alteracao' => 33,
            'email' => 50,
        ];

        foreach ($dados as $campo => $valor) {
            if (isset($mapeamento[$campo]) && $valor !== null) {
                $campos[] = [
                    'colunaAliasId' => $mapeamento[$campo],
                    'tabelaAliasId' => 1,
                    'valor' => (string) $valor
                ];
            }
        }

        return $campos;
    }

    /**
     * Monta dados do beneficiário para envio conforme API v3
     * Estrutura baseada na documentação oficial da Epharma
     */
    public static function montarBeneficiario(
        array $dadosPessoais
    ): array {
        // Formata as datas para o padrão dd/MM/yyyy
        $camposData = ['data_nascimento', 'data_inicio_vigencia', 'data_fim_vigencia', 'data_alteracao'];
        foreach ($camposData as $campo) {
            if (!empty($dadosPessoais[$campo])) {
                $dadosPessoais[$campo] = self::formatarData($dadosPessoais[$campo]);
            }
        }
        
        // Estrutura do beneficiário conforme documentação API v3
        $beneficiarioData = [
            'planoCodigo' => $dadosPessoais['plano_codigo'] ?? $dadosPessoais['plano'] ?? '',
            'inicioVigencia' => $dadosPessoais['data_inicio_vigencia'] ?? '',
            'fimVigencia' => $dadosPessoais['data_fim_vigencia'] ?? '',
            'matricula' => $dadosPessoais['matricula'] ?? $dadosPessoais['cartao_titular'],
            'tipoBeneficiario' => $dadosPessoais['tipo_beneficiario'] ?? 'T',
            'cartaoTitular' => $dadosPessoais['cartao_titular'] ?? '',
            'cartaoUsuario' => $dadosPessoais['cartao_usuario'] ?? '',
            'dadosBeneficiario' => [
                'nomeBeneficiario' => $dadosPessoais['nome'] ?? '',
                'cpf' => self::formatarCpf($dadosPessoais['cpf'] ?? ''),
                'dataNascimento' => $dadosPessoais['data_nascimento'] ?? '',
                'sexo' => $dadosPessoais['genero'] ?? 'F'
            ]
        ];
        
        // Estrutura de endereço
        $endereco = [
            'cep' => $dadosPessoais['cep'] ?? '',
            'logradouro' => $dadosPessoais['logradouro'] ?? 'Rua 1',
            'numero' => $dadosPessoais['numero'] ?? '1',
            'complemento' => $dadosPessoais['complemento'] ?? '',
            'bairro' => $dadosPessoais['bairro'] ?? 'Espirito Santo',
            'cidade' => $dadosPessoais['cidade'] ?? '',
            'uf' => $dadosPessoais['uf'] ?? ''
        ];
        
        // Estrutura de telefones
        $telefones = [
            'celular' => $dadosPessoais['celular'] ?? '',
            'residencial' => '',
            'comercial' => ''
        ];
         
        // Adiciona endereço se houver dados
        if (!empty($endereco)) {
            $beneficiarioData['endereco'] = $endereco;
        }
        
        // Adiciona telefones se houver dados
        if (!empty($telefones)) {
            $beneficiarioData['telefones'] = $telefones;
        }
        
        // Estrutura final conforme documentação - beneficiario como array
        return [
            'beneficiario' => [$beneficiarioData]
        ];
    }

    /**
     * Monta dados do beneficiário apenas com dados pessoais (sem SKUs/questionários)
     * Adaptado para API v3
     */
    public static function montarBeneficiarioSimples(array $dadosPessoais): array
    {
        // Utiliza o método montarBeneficiario com arrays vazios para SKUs e questionários
        return self::montarBeneficiario($dadosPessoais);
    }

    /**
     * Validação básica dos dados obrigatórios conforme API v3
     */
    public static function validarDadosObrigatorios(array $dados): array
    {
        $errors = [];
        $camposObrigatorios = [
            'nome' => 'Nome do beneficiário é obrigatório',
            'cpf' => 'CPF é obrigatório',
            'data_nascimento' => 'Data de nascimento é obrigatória',
            'cartao_titular' => 'Cartão do titular é obrigatório',
            'cartao_usuario' => 'Cartão do usuário é obrigatório',
            'data_inicio_vigencia' => 'Data de início da vigência é obrigatória',
            'tipo_beneficiario' => 'Tipo do beneficiário é obrigatório',
        ];
        
        // Verifica se plano_codigo ou plano está presente
        if (empty($dados['plano_codigo']) && empty($dados['plano'])) {
            $errors[] = 'Código do plano é obrigatório';
        }

        foreach ($camposObrigatorios as $campo => $mensagem) {
            if (empty($dados[$campo])) {
                $errors[] = $mensagem;
            }
        }

        // Validação de CPF
        if (!empty($dados['cpf']) && !self::validarCpf($dados['cpf'])) {
            $errors[] = 'CPF inválido';
        }

        // Validação de email
        if (!empty($dados['email']) && !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email inválido';
        }

        // Validação de gênero
        if (!empty($dados['genero']) && !in_array($dados['genero'], ['M', 'F'])) {
            $errors[] = 'Gênero deve ser M (Masculino) ou F (Feminino)';
        }

        // Validação de tipo de beneficiário
        if (!empty($dados['tipo_beneficiario']) && !in_array($dados['tipo_beneficiario'], ['T', 'D'])) {
            $errors[] = 'Tipo de beneficiário deve ser T (Titular) ou D (Dependente)';
        }

        return $errors;
    }

    /**
     * Validação de CPF
     */
    public static function validarCpf(string $cpf): bool
    {
        $cpf = self::formatarCpf($cpf);
        
        if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Formata data para o padrão da API (dd/MM/yyyy)
     */
    public static function formatarData(string $data): string
    {
        try {
            $date = new \DateTime($data);
            return $date->format('d/m/Y');
        } catch (Exception $e) {
            return $data; // Retorna a data original se não conseguir formatar
        }
    }

    /**
     * Formatar CPF para envio
     */
    public static function formatarCpf(string $cpf): string
    {
        return preg_replace('/\D/', '', $cpf);
    }

    /**
     * Formatar telefone para envio
     */
    public static function formatarTelefone(string $telefone): string
    {
        return preg_replace('/\D/', '', $telefone);
    }

    /**
     * Limpa o cache do token (útil para forçar nova autenticação)
     */
    public static function limparCacheToken(): void
    {
        Cache::forget('epharma_access_token');
    }

    /**
     * Verifica se a API está acessível
     */
    public static function testarConexao(): bool
    {
        try {
            self::obterToken();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Interpreta status do beneficiário
     */
    public static function interpretarStatusBeneficiario(int $status): string
    {
        return match ($status) {
            1 => 'Ativo',
            2 => 'Expirado',
            3 => 'Suspenso',
            4 => 'Dispensado',
            5 => 'Bloqueado',
            6 => 'Transferido',
            default => 'Status desconhecido'
        };
    }

    /**
     * Retorna mapeamento de campos disponíveis para API v3
     */
    public static function obterMapeamentoCampos(): array
    {
        return [
            'planoCodigo' => 'Código do plano',
            'inicioVigencia' => 'Data do início da vigência',
            'fimVigencia' => 'Data de fim da vigência',
            'matricula' => 'Matrícula do beneficiário',
            'tipoBeneficiario' => 'Tipo do beneficiário (T=Titular, D=Dependente)',
            'cartaoTitular' => 'Número do cartão do titular',
            'cartaoUsuario' => 'Número do cartão do usuário',
            'dadosBeneficiario' => [
                'nomeBeneficiario' => 'Nome do beneficiário',
                'cpf' => 'CPF',
                'dataNascimento' => 'Data de nascimento',
                'sexo' => 'Gênero do beneficiário (M/F)'
            ],
            'endereco' => [
                'cep' => 'CEP',
                'logradouro' => 'Logradouro',
                'numero' => 'Número',
                'complemento' => 'Complemento',
                'bairro' => 'Bairro',
                'cidade' => 'Cidade',
                'uf' => 'UF'
            ],
            'telefones' => [
                'celular' => 'Telefone celular',
                'residencial' => 'Telefone residencial',
                'comercial' => 'Telefone comercial'
            ]
        ];
    }
	
		/* aqui */
	public static function ativarDesativarBeneficiario($beneficiario_id,$produto_id,$ativar=true)
	{
		if ($ativar)
		{
			$associate 					= Epharma::associateBeneficiario($beneficiario_id,$produto_id,$ativar);
		} else {
            $beneficiario               = \App\Models\Beneficiario::find($beneficiario_id);

            if (isset($beneficiario->id))
            {
				$associate 				= Epharma::associateBeneficiario($beneficiario_id,$produto_id,$ativar);
	        }
		}
		
		Log::info('ePharma Associar', [
                'associate' => $associate
        ]);
			
		if ($associate->ok == 'S')
		{
			$associate					= Cas::ativarDesativarProduto($beneficiario_id,$produto_id,$ativar,$associate->id);
		}
		return $associate;
	}
	
	public static function associateBeneficiario($id,$produto_id=3,$ativar=true)
    {
		$retorno 						= new stdClass();
        $retorno->ok                    = "N";

		$beneficiario                   = \App\Models\Beneficiario::with('cliente')->find($id);

        if (!isset($beneficiario->id))
        {
            $retorno->mensagem 			= 'Beneficiário não encontrado';
			return $retorno;
        }
		
		if ($ativar)
		{
			$permite					= Cas::permiteProdutoBeneficio($id,$produto_id);
			if ($permite->ok=='N')
			{
				$retorno->mensagem 		= $permite->mensagem; "Produto $produto_id não é permitido para o Beneficiario";
				return $retorno;
			}
			$inicioVigencia				= date('Y-m-d');
			$fimVigencia				= "2999-12-31";
		} else {
			$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$id)
																	     ->where('produto_id','=',$produto_id)
																		 ->first();
			if (!isset($beneficiarioproduto->id))
			{
				$retorno->mensagem 	= 'Beneficiário ainda não foi ativado para o produto. Inativação indisponivel.';
				return $retorno;
			}		
			$inicioVigencia				= substr($beneficiarioproduto->data_ativacao,0,10);
			$fimVigencia				= date('Y-m-d');			
		}
		
		$matricula						= preg_replace('/\D/', '', $beneficiario->cliente->cpfcnpj);
		$cpf 							= $matricula;
		$cartaoUsuario					= $matricula;
		
		if ($beneficiario->tipo == 'T')
		{
			$cartaoTitular				= $matricula;
		} else {
			$pbeneficiario              = \App\Models\Beneficiario::with('cliente')->where('id','=',$beneficiario->parent_id)->first();
			if (!isset($pbeneficiario->id))
			{
				$retorno->mensagem 		= 'Não encontrado o titular do beneficiário';
				return $retorno;
			}
			$cartaoTitular				=  preg_replace('/\D/', '', $pbeneficiario->cliente->cpfcnpj);
		}
		
		$beneficiarioData = [
            'planoCodigo' 				=> 244926,
            'inicioVigencia' 			=> Epharma::formatarData($inicioVigencia),
            'fimVigencia' 				=> Epharma::formatarData($fimVigencia),
            'matricula' 				=> $matricula,
            'tipoBeneficiario' 			=> $beneficiario->tipo,
            'cartaoTitular' 			=> $cartaoTitular,
            'cartaoUsuario' 			=> $cartaoUsuario,
            'dadosBeneficiario' 		=> [
                'nomeBeneficiario' 		=> $beneficiario->cliente->nome,
                'cpf' 					=> $cpf,
                'dataNascimento' 		=> Epharma::formatarData($beneficiario->cliente->data_nascimento),
                'sexo' 					=> $beneficiario->cliente->sexo
            ]
        ];
        
        // Estrutura de endereço
        $endereco = [
            'cep' 						=> preg_replace('/\D/', '', $beneficiario->cliente->cep),
            'logradouro' 				=> $beneficiario->cliente->logradouro,
            'numero' 					=> $beneficiario->cliente->numero,
            'complemento' 				=> $beneficiario->cliente->complemento,
            'bairro' 					=> $beneficiario->cliente->bairro,
            'cidade' 					=> $beneficiario->cliente->cidade,
            'uf' 						=> $beneficiario->cliente->estado
        ];
        
        // Estrutura de telefones
        $telefones = [
            'celular' 				=>  preg_replace('/\D/', '', $beneficiario->cliente->telefone),
            'residencial' 			=> '',
            'comercial' 			=> ''
        ];
         
        // Adiciona endereço se houver dados
        if (!empty($endereco)) {
            $beneficiarioData['endereco'] = $endereco;
        }
        
        // Adiciona telefones se houver dados
        if (!empty($telefones)) {
            $beneficiarioData['telefones'] = $telefones;
        } 
		
		$payload 						= ['beneficiario' => [$beneficiarioData]];
		
		$associate						= Epharma::associate($payload,$ativar);
		
		if ($associate->ok == 'S')
		{
			return $associate;
		}
		
		$retorno->mensagem 				= $associate->mensagem;
		return $retorno;
		
	}
	
	public static function associate($payload,$ativar)
    {
		$retorno 					= new stdClass();
        $retorno->ok                = "N";
		 
        $credenciais 				= Epharma::obterCredenciais();
        $url 						= $credenciais['base_url'] . "/api/ManutencaoBeneficiario/BeneficiariosCartaoCliente";
        $token 						= Epharma::obterToken();
            
        Log::info('ePharma API Call', [
            'token' => $token
        ]);

        $headers 					= [
									'Content-Type'  => 'application/json',
									'Authorization' => "Bearer {$token}"
									];

        Log::info('ePharma API Call', [
             'method' 	=> 'POST',
             'url' 		=> $url,
			 'token'	=> $token,
             'data' 	=> $payload
        ]);

		try {
			$hresponse                  = Http::withHeaders($headers)->post($url, $payload);
			$statcode					= $hresponse->status();
			$response 					= $hresponse->object();
		} catch (\Illuminate\Http\Client\ConnectionException $e) {
			$retorno->error 			= true;
			$retorno->statcode			= 500;
			$retorno->mensagem 			= $e;
			return $retorno;
		} catch (RequestException $e) {
			$retorno->error 			= true;
			$retorno->statcode			= 500;
			$retorno->mensagem 			= $e;
			return $retorno;
		}
	    
        Log::error('ePharma API Response', [
                  'status' 	=> $statcode,
                  'body' 	=> $response,
                  'url' 	=> $url
		]);
		 
		if ($statcode != 200)
		{
			if (isset($response->message))
			{
				$retorno->mensagem		= $response->message;
			} else {
				$retorno->mensagem		= "Ocorreu erro não identificado";
			}
			$retorno->response 			= $response;
			$retorno->status 			= $statcode;
		    return $retorno;
		}
		
        $retorno              			= $response;
		$item0 							= $response->data[0]; 
		
		if ($item0->status === "Erro") 
        {
		    $retorno->ok                = "N";
			$retorno->response 			= $response;
			$retorno->status 			= $statcode;
			$retorno->mensagem 			= "";
			if (!empty($item0->inconsistencias)) 
			{
				foreach ($item0->inconsistencias as $inc) 
				{
					$retorno->mensagem	.= $inc;
				}
			}
        } else {
			$retorno->id 				= $item0->beneficiario ?? 0;
		    $retorno->ok                = "S";
		}

        return $retorno;
		
	}	

}