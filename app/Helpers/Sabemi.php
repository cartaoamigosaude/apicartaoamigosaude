<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\ConnectException;
use Exception;
use stdClass;

class Sabemi
{
	// Credenciais e endpoints
	private static $urlBase 			= config('services.sabemi.api_url_hml');
	private static $urlBaseProduction 	= config('services.sabemi.api_url'); // Produção
	private static $username 			= 'TESTEAPII';
	private static $password 			= '19901990';
	private static $token 				= null;
	private static $tokenExpires 		= null;

	/**
	 * Obtém o token de autenticação da API Sabemi
	 *
	 * @param boolean $sandbox - Usar ambiente de homologação (true) ou produção (false)
	 * @return stdClass - Retorna objeto com token ou erro
	 */
	public static function getToken($sandbox = true)
	{
		$retorno 				= new stdClass();
		$retorno->ok 			= 'N';
		$retorno->token	 		= null;

		// Verifica se token ainda é válido
		if (self::$token && self::$tokenExpires && time() < self::$tokenExpires) 
		{
			$retorno->ok 		= 'S';
			$retorno->token 	= self::$token;
			return $retorno;
		}

		try {
			$url = ($sandbox ? self::$urlBase : self::$urlBaseProduction) . '/token';

			// Enviando requisição POST para obter token
			$response = Http::asForm()->post($url, [
				'username' => self::$username,
				'password' => self::$password,
				'grant_type' => 'password'
			]);

			Log::info("Sabemi getToken", ['url' => $url, 'status' => $response->status()]);

			if ($response->successful()) {
				$data = $response->object();
				self::$token = $data->access_token ?? null;
				self::$tokenExpires = time() + ($data->expires_in ?? 3600); // Token válido por 1 hora

				if (self::$token) {
					$retorno->ok 		= 'S';
					$retorno->token 	= self::$token;
					Log::info("Sabemi Token obtained successfully");
				} else {
					$retorno->mensagem = 'Token não retornado pela API';
					Log::error("Sabemi getToken - No token returned", ['response' => $response->body()]);
				}
			} else {
				$retorno->mensagem = 'Falha na autenticação com Sabemi: ' . $response->status();
				$retorno->status = $response->status();
				$retorno->details = $response->json('error_description') ?? $response->body();
				Log::error("Sabemi getToken Error", ['status' => $response->status(), 'response' => $response->body()]);
			}
		} catch (ConnectException $e) {
			$retorno->mensagem = 'Erro de conexão ao obter token Sabemi';
			Log::error("Sabemi getToken Connection Error", ['message' => $e->getMessage()]);
		} catch (Exception $e) {
			$retorno->mensagem = 'Erro ao obter token: ' . $e->getMessage();
			Log::error("Sabemi getToken General Error", ['message' => $e->getMessage()]);
		}

		return $retorno;
	}

	/**
	 * Inclui um novo seguro individual (vida)
	 *
	 * @param stdClass $payload - Dados do segurado
	 * @param boolean $sandbox - Usar ambiente de homologação
	 * @return stdClass - Retorna contrato criado ou erro
	 */
	public static function incluirSeguro($payload, $sandbox = true)
	{
		$retorno = new stdClass();
		$retorno->ok = 'N';

		// Valida payload obrigatório
		if (!self::validarPayloadSeguro($payload, $retorno)) {
			return $retorno;
		}

		try {
			$token = self::getToken($sandbox);
			if ($token->ok != 'S') {
				$retorno->mensagem = $token->mensagem ?? 'Falha ao obter token';
				return $retorno;
			}

			$url = ($sandbox ? self::$urlBase : self::$urlBaseProduction) . '/api/SeguroIndividual';

			// Log do payload para debug
			Log::info("Sabemi incluirSeguro Payload", ['payload' => $payload]);

			$response = Http::withHeaders([
				'Authorization' => 'Bearer ' . $token->token,
				'Content-Type' => 'application/json'
			])->post($url, $payload);

			Log::info("Sabemi incluirSeguro Response", ['status' => $response->status(), 'response' => $response->object()]);

			if ($response->successful()) {
				$data = $response->object();
				$retorno->ok = 'S';
				$retorno->object = $data;
				$retorno->contrato_id = $data->idContrato ?? $data->id ?? null;
				$retorno->mensagem = 'Seguro incluído com sucesso';
				Log::info("Sabemi incluirSeguro Success", ['contrato_id' => $retorno->contrato_id]);
			} else {
				$retorno->status = $response->status();
				$retorno->mensagem = 'Falha ao incluir seguro';
				$retorno->details = $response->json() ?? $response->body();
				Log::error("Sabemi incluirSeguro Error", ['status' => $response->status(), 'response' => $response->body()]);
			}
		} catch (ConnectException $e) {
			$retorno->mensagem = 'Erro de conexão ao incluir seguro';
			Log::error("Sabemi incluirSeguro Connection Error", ['message' => $e->getMessage()]);
		} catch (Exception $e) {
			$retorno->mensagem = 'Erro ao incluir seguro: ' . $e->getMessage();
			Log::error("Sabemi incluirSeguro General Error", ['message' => $e->getMessage()]);
		}

		return $retorno;
	}

	/**
	 * Cancela um seguro individual
	 *
	 * @param int $codigoContrato - Código do contrato
	 * @param string $senha - Senha para cancelamento
	 * @param boolean $sandbox - Usar ambiente de homologação
	 * @return stdClass - Retorna resultado do cancelamento
	 */
	public static function cancelarSeguro($codigoContrato, $senha = '', $sandbox = true)
	{
		$retorno = new stdClass();
		$retorno->ok = 'N';

		if (!$codigoContrato) {
			$retorno->mensagem = 'Código do contrato é obrigatório';
			return $retorno;
		}

		try {
			$token = self::getToken($sandbox);
			if ($token->ok != 'S') {
				$retorno->mensagem = $token->mensagem ?? 'Falha ao obter token';
				return $retorno;
			}

			$url = ($sandbox ? self::$urlBase : self::$urlBaseProduction) . '/api/CancelamentoContrato/' . $codigoContrato . '/' . $senha;

			Log::info("Sabemi cancelarSeguro", ['url' => $url]);

			$response = Http::withHeaders([
				'Authorization' => 'Bearer ' . $token->token,
				'Content-Type' => 'application/json'
			])->get($url);

			Log::info("Sabemi cancelarSeguro Response", ['status' => $response->status(), 'response' => $response->object()]);

			if ($response->successful()) {
				$retorno->ok = 'S';
				$retorno->object = $response->object();
				$retorno->mensagem = 'Seguro cancelado com sucesso';
				Log::info("Sabemi cancelarSeguro Success");
			} else {
				$retorno->status = $response->status();
				$retorno->mensagem = 'Falha ao cancelar seguro';
				$retorno->details = $response->json() ?? $response->body();
				Log::error("Sabemi cancelarSeguro Error", ['status' => $response->status(), 'response' => $response->body()]);
			}
		} catch (ConnectException $e) {
			$retorno->mensagem = 'Erro de conexão ao cancelar seguro';
			Log::error("Sabemi cancelarSeguro Connection Error", ['message' => $e->getMessage()]);
		} catch (Exception $e) {
			$retorno->mensagem = 'Erro ao cancelar seguro: ' . $e->getMessage();
			Log::error("Sabemi cancelarSeguro General Error", ['message' => $e->getMessage()]);
		}

		return $retorno;
	}

	/**
	 * Consulta a situação de uma proposta
	 *
	 * @param string $numeroProposta - Número da proposta
	 * @param boolean $sandbox - Usar ambiente de homologação
	 * @return stdClass - Retorna dados da proposta ou erro
	 */
	public static function consultarProposta($numeroProposta, $sandbox = true)
	{
		$retorno = new stdClass();
		$retorno->ok = 'N';

		if (!$numeroProposta) {
			$retorno->mensagem = 'Número da proposta é obrigatório';
			return $retorno;
		}

		try {
			$token = self::getToken($sandbox);
			if ($token->ok != 'S') {
				$retorno->mensagem = $token->mensagem ?? 'Falha ao obter token';
				return $retorno;
			}

			// URL alternativa para consulta de proposta (de outro domínio conforme documentação)
			$url = config('services.sabemi.api_url_hml') . '/api/SeguroProposta/' . $numeroProposta;

			Log::info("Sabemi consultarProposta", ['url' => $url]);

			$response = Http::withHeaders([
				'Authorization' => 'Bearer ' . $token->token,
				'Content-Type' => 'application/json'
			])->get($url);

			Log::info("Sabemi consultarProposta Response", ['status' => $response->status(), 'response' => $response->object()]);

			if ($response->successful()) {
				$retorno->ok = 'S';
				$retorno->object = $response->object();
				$retorno->mensagem = 'Proposta consultada com sucesso';
				Log::info("Sabemi consultarProposta Success");
			} else {
				$retorno->status = $response->status();
				$retorno->mensagem = 'Falha ao consultar proposta';
				$retorno->details = $response->json() ?? $response->body();
				Log::error("Sabemi consultarProposta Error", ['status' => $response->status(), 'response' => $response->body()]);
			}
		} catch (ConnectException $e) {
			$retorno->mensagem = 'Erro de conexão ao consultar proposta';
			Log::error("Sabemi consultarProposta Connection Error", ['message' => $e->getMessage()]);
		} catch (Exception $e) {
			$retorno->mensagem = 'Erro ao consultar proposta: ' . $e->getMessage();
			Log::error("Sabemi consultarProposta General Error", ['message' => $e->getMessage()]);
		}

		return $retorno;
	}

	/**
	 * Obtém o certificado de um segurado
	 *
	 * @param int $idPrime - ID Prime do segurado
	 * @param string $cpf - CPF do segurado
	 * @param boolean $sandbox - Usar ambiente de homologação
	 * @return stdClass - Retorna certificado em BASE64 ou erro
	 */
	public static function obterCertificado($idPrime, $cpf, $sandbox = true)
	{
		$retorno = new stdClass();
		$retorno->ok = 'N';

		if (!$idPrime || !$cpf) {
			$retorno->mensagem = 'ID Prime e CPF são obrigatórios';
			return $retorno;
		}

		try {
			$token = self::getToken($sandbox);
			if ($token->ok != 'S') {
				$retorno->mensagem = $token->mensagem ?? 'Falha ao obter token';
				return $retorno;
			}

			$cpf = preg_replace('/\D/', '', $cpf);

			$url = ($sandbox ? self::$urlBase : self::$urlBaseProduction) . '/Api/Relatorio?Dados=RPT=CertificadoIndividual|Tipo=PDF|ID_Prime=' . $idPrime . '|NR_CPF=' . $cpf;

			Log::info("Sabemi obterCertificado", ['url' => $url]);

			$response = Http::withHeaders([
				'Authorization' => 'Bearer ' . $token->token,
				'Content-Type' => 'application/json'
			])->get($url);

			Log::info("Sabemi obterCertificado Response", ['status' => $response->status()]);

			if ($response->successful()) {
				$retorno->ok = 'S';
				$retorno->certificado_base64 = $response->body();
				$retorno->mensagem = 'Certificado obtido com sucesso';
				Log::info("Sabemi obterCertificado Success");
			} else {
				$retorno->status = $response->status();
				$retorno->mensagem = 'Falha ao obter certificado';
				$retorno->details = $response->body();
				Log::error("Sabemi obterCertificado Error", ['status' => $response->status(), 'response' => $response->body()]);
			}
		} catch (ConnectException $e) {
			$retorno->mensagem = 'Erro de conexão ao obter certificado';
			Log::error("Sabemi obterCertificado Connection Error", ['message' => $e->getMessage()]);
		} catch (Exception $e) {
			$retorno->mensagem = 'Erro ao obter certificado: ' . $e->getMessage();
			Log::error("Sabemi obterCertificado General Error", ['message' => $e->getMessage()]);
		}

		return $retorno;
	}

	/**
	 * Ativa um seguro individual (vida)
	 * Associa o beneficiário ao produto Sabemi
	 *
	 * @param object $beneficiario - Dados do beneficiário
	 * @param int $produto_id - ID do produto (5 por padrão)
	 * @param int $plano_id - ID do plano (3 por padrão)
	 * @param boolean $sandbox - Usar ambiente de homologação
	 * @return stdClass - Retorna resultado da ativação
	 */
	public static function ativarBeneficiario($beneficiario, $produto_id = 5, $plano_id = 3, $sandbox = true)
	{
		$retorno = new stdClass();
		$retorno->ok = 'N';

		// Prepara payload conforme documentação da API
		$payload = self::construirPayloadSeguro($beneficiario, $produto_id, $plano_id);

		if (!$payload) {
			$retorno->mensagem = 'Dados do beneficiário incompletos para construir o payload';
			return $retorno;
		}

		// Inclui o seguro
		$resultado = self::incluirSeguro($payload, $sandbox);

		if ($resultado->ok == 'S') {
			$retorno->ok = 'S';
			$retorno->contrato_id = $resultado->contrato_id;
			$retorno->mensagem = 'Beneficiário ativado com sucesso em Sabemi';
			$retorno->object = $resultado->object;
			Log::info("Sabemi ativarBeneficiario Success", ['contrato_id' => $resultado->contrato_id]);
		} else {
			$retorno->mensagem = $resultado->mensagem ?? 'Falha ao ativar beneficiário';
			$retorno->details = $resultado->details ?? null;
			Log::error("Sabemi ativarBeneficiario Error", ['mensagem' => $resultado->mensagem]);
		}

		return $retorno;
	}

	/**
	 * Desativa um seguro individual (vida)
	 * Cancela o contrato do segurado
	 *
	 * @param int $codigoContrato - Código do contrato
	 * @param boolean $sandbox - Usar ambiente de homologação
	 * @return stdClass - Retorna resultado da desativação
	 */
	public static function desativarBeneficiario($codigoContrato, $sandbox = true)
	{
		$retorno = new stdClass();
		$retorno->ok = 'N';

		if (!$codigoContrato) {
			$retorno->mensagem = 'Código do contrato é obrigatório';
			return $retorno;
		}

		// Cancela o seguro
		$resultado = self::cancelarSeguro($codigoContrato, '', $sandbox);

		if ($resultado->ok == 'S') {
			$retorno->ok = 'S';
			$retorno->mensagem = 'Beneficiário desativado com sucesso em Sabemi';
			$retorno->object = $resultado->object;
			Log::info("Sabemi desativarBeneficiario Success", ['contrato_id' => $codigoContrato]);
		} else {
			$retorno->mensagem = $resultado->mensagem ?? 'Falha ao desativar beneficiário';
			$retorno->details = $resultado->details ?? null;
			Log::error("Sabemi desativarBeneficiario Error", ['mensagem' => $resultado->mensagem]);
		}

		return $retorno;
	}

	/**
	 * Constrói o payload para inclusão de seguro conforme documentação
	 *
	 * @param object $beneficiario - Dados do beneficiário
	 * @param int $produto_id - ID do produto
	 * @param int $plano_id - ID do plano
	 * @return stdClass|null - Payload construído ou null
	 */
	private static function construirPayloadSeguro($beneficiario, $produto_id = 5, $plano_id = 3)
	{
		if (!isset($beneficiario->cpf) || !isset($beneficiario->nome)) {
			return null;
		}

		$payload = new stdClass();
		$payload->Produto = $produto_id;
		$payload->Plano = $plano_id;
		$payload->Corretor = $beneficiario->corretor_id ?? 2318;
		$payload->DataInicioVigencia = $beneficiario->data_inicio ?? date('Y-m-d') . 'T00:00:00';
		$payload->FrequenciaEmissao = 12; // Mensal
		$payload->TipoVencimento = 1;
		$payload->DiaVencimento = 1;
		$payload->AtividadePrincipal = 0;
		$payload->FormaPagamento = $beneficiario->forma_pagamento ?? 5;
		$payload->DPS = [];
		$payload->Beneficiarios = [];
		$payload->Agregados = [];
		$payload->NumeroSorte = [];

		// Dados do segurado
		$payload->Segurado = new stdClass();
		$payload->Segurado->Cpf = preg_replace('/\D/', '', $beneficiario->cpf);
		$payload->Segurado->Nome = $beneficiario->nome;
		$payload->Segurado->DataNascimento = $beneficiario->data_nascimento ?? '1979-02-20T00:00:00';
		$payload->Segurado->Capital = $beneficiario->capital ?? 1;
		$payload->Segurado->Genero = $beneficiario->genero ?? 1;
		$payload->Segurado->Email = $beneficiario->email ?? 'sememail@csprime.com.br';
		$payload->Segurado->TelefoneCelular = preg_replace('/\D/', '', $beneficiario->telefone ?? '11994508803');
		$payload->Segurado->TelefoneSMS = preg_replace('/\D/', '', $beneficiario->telefone ?? '11994508803');

		// Endereço
		$payload->Segurado->Endereco = new stdClass();
		$payload->Segurado->Endereco->Endereco = $beneficiario->endereco ?? 'Rua Padrão';
		$payload->Segurado->Endereco->Numero = $beneficiario->numero ?? '0';
		$payload->Segurado->Endereco->Complemento = $beneficiario->complemento ?? '';
		$payload->Segurado->Endereco->Bairro = $beneficiario->bairro ?? 'Centro';
		$payload->Segurado->Endereco->Cidade = $beneficiario->cidade ?? 'São Paulo';
		$payload->Segurado->Endereco->UF = $beneficiario->uf ?? 'SP';
		$payload->Segurado->Endereco->CEP = preg_replace('/\D/', '', $beneficiario->cep ?? '00000000');

		return $payload;
	}

	/**
	 * Valida payload de seguro
	 *
	 * @param stdClass $payload - Payload a validar
	 * @param stdClass $retorno - Objeto para retornar erro
	 * @return boolean - True se válido, false caso contrário
	 */
	private static function validarPayloadSeguro($payload, &$retorno)
	{
		$camposObrigatorios = ['Produto', 'Plano', 'DataInicioVigencia', 'Segurado'];

		foreach ($camposObrigatorios as $campo) {
			if (!isset($payload->$campo)) {
				$retorno->mensagem = "Campo obrigatório ausente: $campo";
				return false;
			}
		}

		if (isset($payload->Segurado)) {
			$camposSeguro = ['Cpf', 'Nome', 'DataNascimento'];
			foreach ($camposSeguro as $campo) {
				if (!isset($payload->Segurado->$campo)) {
					$retorno->mensagem = "Campo obrigatório do Segurado ausente: $campo";
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Formata data para o padrão esperado
	 *
	 * @param string $data - Data em qualquer formato
	 * @return string - Data formatada (YYYY-MM-DDTHH:MM:SS)
	 */
	public static function formatarData($data)
	{
		if (empty($data)) {
			return date('Y-m-d') . 'T00:00:00';
		}

		try {
			$dt = new \DateTime($data);
			return $dt->format('Y-m-d\TH:i:s');
		} catch (Exception $e) {
			Log::error("Sabemi formatarData Error", ['data' => $data, 'message' => $e->getMessage()]);
			return date('Y-m-d') . 'T00:00:00';
		}
	}

	/**
	 * Limpa CPF/CNPJ removendo caracteres especiais
	 *
	 * @param string $documento - CPF ou CNPJ
	 * @return string - Documento limpo
	 */
	public static function limparDocumento($documento)
	{
		return preg_replace('/\D/', '', $documento ?? '');
	}

	/**
	 * Limpa telefone removendo caracteres especiais
	 *
	 * @param string $telefone - Telefone
	 * @return string - Telefone limpo
	 */
	public static function limparTelefone($telefone)
	{
		return preg_replace('/\D/', '', $telefone ?? '');
	}
}
