<?php

namespace App\Http\Controllers;

use App\Helpers\Epharma;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class EpharmaController extends Controller
{
    /**
     * Teste de conectividade com a API
     */
    public function testeConectividade(): JsonResponse
    {
        try {
            $credenciais 		= Epharma::obterCredenciais();
            $token 				= Epharma::obterToken();
            
            return response()->json([
                'success' => true,
                'message' => 'Configuração da API ePharma está correta',
                'data' => [
                    'base_url' => $credenciais['base_url'],
                    'token_configurado' => !empty($token),
                    'token_preview' => substr($token, 0, 20) . '...'
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro na configuração: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Cadastrar beneficiário
     */
    public function cadastrarBeneficiario(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plano' => 'required|integer',
            'nome' => 'required|string|max:255',
            'cpf' => 'required|string|size:11',
            'data_nascimento' => 'required|date',
            'cartao_titular' => 'required|string',
            'cartao_usuario' => 'required|string',
            'email' => 'nullable|email|max:255',
            'celular' => 'nullable|string|max:20',
            'genero' => 'nullable|in:M,F',
            'cep' => 'nullable|string|size:8',
            'uf' => 'nullable|string|size:2',
            'cidade' => 'nullable|string|max:100',
            'data_inicio_vigencia' => 'nullable|date',
            'data_fim_vigencia' => 'nullable|date',
            'status' => 'nullable|integer|between:1,6',
            'sku_codigos' => 'nullable|array',
            'sku_codigos.*' => 'integer',
            'questionarios' => 'nullable|array',
            'questionarios.*.configuracao_id' => 'required_with:questionarios|integer',
            'questionarios.*.beneficiario_questao_id' => 'required_with:questionarios|integer',
            'questionarios.*.questionario_id' => 'required_with:questionarios|integer',
            'questionarios.*.questao_id' => 'required_with:questionarios|integer',
            'questionarios.*.valor' => 'required_with:questionarios|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $dados = $validator->validated();

            // Validar CPF
            if (!Epharma::validarCpf($dados['cpf'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'CPF inválido'
                ], 422);
            }

            // Validação adicional usando o helper
            $errors = Epharma::validarDadosObrigatorios($dados);
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validação falhou',
                    'errors' => $errors
                ], 422);
            }

            // Formatar CPF e telefone
            $dados['cpf'] = Epharma::formatarCpf($dados['cpf']);
            if (!empty($dados['celular'])) {
                $dados['celular'] = Epharma::formatarTelefone($dados['celular']);
            }

            // Montar estrutura do beneficiário
            $beneficiario = Epharma::montarBeneficiario(
                $dados,
                $dados['sku_codigos'] ?? [],
                $dados['questionarios'] ?? []
            );

            // Enviar para ePharma
            $response = Epharma::cadastrarBeneficiario($beneficiario, $dados['plano']);

            return response()->json([
                'success' => true,
                'message' => 'Beneficiário cadastrado com sucesso',
                'data' => $response
            ], 201);

        } catch (Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar beneficiário: ' . $e->getMessage(),
                'error_code' => $e->getCode()
            ], $statusCode);
        }
    }

    /**
     * Informações sobre campos disponíveis
     */
    public function informacoesCampos(): JsonResponse
    {
        $mapeamento = Epharma::obterMapeamentoCampos();
        
        $campos = [
            'mapeamento_campos' => $mapeamento,
            'status_beneficiario' => [
                1 => 'Ativo',
                2 => 'Expirado',
                3 => 'Suspenso',
                4 => 'Dispensado',
                5 => 'Bloqueado',
                6 => 'Transferido'
            ],
            'exemplo_request' => [
                'plano' => 43461,
                'nome' => 'Nome do Beneficiário',
                'cpf' => '12345678901',
                'data_nascimento' => '1990-01-15',
                'cartao_titular' => '12345678901',
                'cartao_usuario' => '12345678901',
                'email' => 'email@exemplo.com',
                'celular' => '11987654321',
                'genero' => 'M',
                'cep' => '01234567',
                'uf' => 'SP',
                'cidade' => 'São Paulo',
                'status' => 1,
                'sku_codigos' => [623846, 623845],
                'questionarios' => [
                    [
                        'configuracao_id' => 275,
                        'beneficiario_questao_id' => 95,
                        'questionario_id' => 95,
                        'questao_id' => 1885,
                        'valor' => 'Resposta exemplo'
                    ]
                ]
            ]
        ];

        return response()->json($campos);
    }

    /**
     * Limpar cache do token
     */
    public function limparCache(): JsonResponse
    {
        try {
            Epharma::limparCacheToken();
            
            return response()->json([
                'success' => true,
                'message' => 'Cache do token limpo com sucesso'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao limpar cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Teste completo do fluxo
     */
    public function testeFluxoCompleto(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'plano' => 'required|integer',
                'nome' => 'required|string',
                'cpf' => 'required|string',
                'data_nascimento' => 'required|date',
                'cartao_titular' => 'required|string',
                'cartao_usuario' => 'required|string',
                'tipo_beneficiario' => 'required|in:T,D',
                'email' => 'nullable|email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $dados = $validator->validated();
            $resultados = [];

            // 1. Teste de conectividade
            $resultados['conectividade'] = Epharma::testarConexao();

            if (!$resultados['conectividade']) {
                throw new Exception('Falha na conectividade com a API ePharma');
            }

            // 2. Validar dados
            $errors = Epharma::validarDadosObrigatorios($dados);
            $resultados['validacao'] = [
                'valido' => empty($errors),
                'erros' => $errors
            ];

            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'data' => $resultados
                ], 422);
            }

            // 3. Formatar dados
            $dados['cpf'] = Epharma::formatarCpf($dados['cpf']);
            if (!empty($dados['celular'])) {
                $dados['celular'] = Epharma::formatarTelefone($dados['celular']);
            }

            // 4. Montar estrutura
            $beneficiario = Epharma::montarBeneficiario($dados);
            $resultados['estrutura_montada'] = $beneficiario;

            // 5. Enviar para API
            $response = Epharma::cadastrarBeneficiario($beneficiario, $dados['plano']);
            $resultados['cadastro'] = $response;

            return response()->json([
                'success' => true,
                'message' => 'Teste de fluxo completo executado com sucesso',
                'data' => $resultados
            ]);

        } catch (Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            
            return response()->json([
                'success' => false,
                'message' => 'Erro no teste de fluxo: ' . $e->getMessage(),
                'error_code' => $e->getCode(),
                'data' => $resultados ?? []
            ], $statusCode);
        }
    }

    /**
     * Cadastrar beneficiário simples (apenas dados pessoais)
     */
    public function cadastrarBeneficiarioSimples(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plano' => 'required|integer',
            'nome' => 'required|string|max:255',
            'cpf' => 'required|string|size:11',
            'data_nascimento' => 'required|date',
            'cartao_titular' => 'required|string',
            'cartao_usuario' => 'required|string',
            'tipo_beneficiario' => 'required|in:T,D',
            'email' => 'nullable|email|max:255',
            'celular' => 'nullable|string|max:20',
            'genero' => 'nullable|in:M,F',
            'cep' => 'nullable|string|size:8',
            'uf' => 'nullable|string|size:2',
            'cidade' => 'nullable|string|max:100',
            'data_inicio_vigencia' => 'nullable|date',
            'data_fim_vigencia' => 'nullable|date',
            'status' => 'nullable|integer|between:1,6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $dados = $validator->validated();

            // Validar CPF
            if (!Epharma::validarCpf($dados['cpf'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'CPF inválido'
                ], 422);
            }

            // Validação adicional
            $errors = Epharma::validarDadosObrigatorios($dados);
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validação falhou',
                    'errors' => $errors
                ], 422);
            }

            // Formatar dados
            $dados['cpf'] = Epharma::formatarCpf($dados['cpf']);
            if (!empty($dados['celular'])) {
                $dados['celular'] = Epharma::formatarTelefone($dados['celular']);
            }

            // Montar estrutura simples (sem SKUs e questionários)
            $beneficiario = Epharma::montarBeneficiarioSimples($dados);

            // Enviar para ePharma
            $response = Epharma::cadastrarBeneficiario($beneficiario, $dados['plano']);

            return response()->json([
                'success' => true,
                'message' => 'Beneficiário cadastrado com sucesso (somente dados pessoais)',
                'data' => $response
            ], 201);

        } catch (Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao cadastrar beneficiário: ' . $e->getMessage(),
                'error_code' => $e->getCode()
            ], $statusCode);
        }
    }
    public function exemploTeste(): JsonResponse
    {
        try {
            $dadosExemplo = [
                'plano' => 43461,
                'plano_codigo' => '173285',
                'nome' => 'SERGIO MORAIS DOS SANTOS',
                'cpf' => '69721203653',
                'data_nascimento' => '1968-04-28',
                'data_inicio_vigencia' => '2025-08-16',
                'data_fim_vigencia' => '2099-12-31',
                'matricula' => '69721203653',
                'cartao_titular' => '69721203653',
                'cartao_usuario' => '69721203653',
                'tipo_beneficiario' => 'T',
                'email' => 'teste@exemplo.com',
                'celular' => '31990809951',
                'genero' => 'M',
                'cep' => '32671654',
                'logradouro' => 'Rua 1',
                'numero' => '1',
                'bairro' => 'Espirito Santo',
                'cidade' => 'BETIM',
                'uf' => 'MG',
                'status' => 1
            ];

            // Montar estrutura
            $beneficiario = Epharma::montarBeneficiario($dadosExemplo);

            // Enviar para API
            $response = Epharma::cadastrarBeneficiario($beneficiario, $dadosExemplo['plano']);

            return response()->json([
                'success' => true,
                'message' => 'Exemplo executado com sucesso',
                'dados_enviados' => $dadosExemplo,
                'estrutura_api' => $beneficiario,
                'resposta_api' => $response
            ]);

        } catch (Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            
            return response()->json([
                'success' => false,
                'message' => 'Erro no exemplo: ' . $e->getMessage(),
                'error_code' => $e->getCode()
            ], $statusCode);
        }
    }
	
	
}