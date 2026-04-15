<?php

namespace App\Services\Cas;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use App\Helpers\CelCash;
use App\Helpers\ChatHot;
use App\Helpers\Cas;
use App\Helpers\Epharma;
use App\Jobs\ChatHotJob;
use App\Jobs\CelCashParcelasAvulsaJob;
use Carbon\Carbon;
use PDF;
use DB;
use stdClass;
use Exception;

class AgendamentoBusinessService
{

	public static function situacaoAgendamento($value)
	{
		/*
		solicitado_data_hora
		agendar_data_hora 
		preconfirmado_data_hora
		confirmado_data_hora
		cancelado_data_hora
		pagamento_data_hora
		*/
		
		switch ($value) 
		{
			case "S": return "Solicitado";
			/* necessário atualizar solicitacao_data_hora */
			case "R": return "Pré-agendado";
			/* 	preconfirmado_data_hora */ 
			case "A": return "Aguard confirmacao/Pagto";	
			/* necessário atualizar solicitacao_data_hora */
			case "C": return "Confirmado";
			case "Y": return "Não pagou";			  
			case "X": return "Cancelado";
			case "N": return "Não compareceu";
			case "G": return "Reagendado";
			case "Z": return "Concluído";			  
		}
		return $value;	
	}

	public static function substituirMensagemAgendamento($agendamento_id,$mensagem)
	{
		
		$retorno               						= new stdClass;
		$retorno->mensagem							= $mensagem;
		$retorno->arquivo 							= "";
		$arquivo 									= "";
		
		$agendamento            			    	= \App\Models\ClinicaBeneficiario::with('clinica','especialidade','beneficiario')->find($agendamento_id);
		
		if (!isset($agendamento->id))
        {
			return $retorno;
		}
		
		if (substr_count($mensagem, '[tipo]') > 0)
		{
			if ($agendamento->tipo == 'C')
			{
				$tipo 								= 'Consulta';
			} else {
				$tipo 								= 'Exame';
			}
			$mensagem								= str_replace("[tipo]",$tipo,$mensagem);
		}
		
		if (substr_count($mensagem, '[especialidade]') > 0)
		{
			$mensagem								= str_replace("[especialidade]",$agendamento->especialidade->nome,$mensagem);
		}
		
		if (substr_count($mensagem, '[data_hora]') > 0)
		{
			if (!is_null($agendamento->agendamento_data_hora))
			{
				$data 								= substr($agendamento->agendamento_data_hora,0,10);
				list($ano,$mes,$dia) 				= explode("-",$data);
				$data_hora							= "$dia/$mes/$ano" . ' ás ' . substr($agendamento->agendamento_data_hora,11,5);
				$mensagem							= str_replace("[data_hora]",$data_hora,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[vencimento]') > 0)
		{
			if (!is_null($agendamento->vencimento))
			{
				list($ano,$mes,$dia) 				= explode("-",$agendamento->vencimento);
				$vencimento							= "$dia/$mes/$ano";
				$mensagem							= str_replace("[vencimento]",$vencimento,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[pagamento]') > 0)
		{
			if (!is_null($agendamento->pagamento))
			{
				list($ano,$mes,$dia) 				= explode("-",$agendamento->pagamento);
				$pagamento							= "$dia/$mes/$ano";
				$mensagem							= str_replace("[pagamento]",$pagamento,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[clinica]') > 0)
		{
			if (isset($agendamento->clinica->nome))
			{
				$mensagem							= str_replace("[clinica]",$agendamento->clinica->nome,$mensagem);
			}
		}
		
		
		if (substr_count($mensagem, '[beneficiario]') > 0)
		{
			$beneficiario              				= \App\Models\Beneficiario::with('cliente')->find($agendamento->beneficiario_id);
			if (isset($beneficiario->id))
			{
				$mensagem							= str_replace("[beneficiario]",$beneficiario->cliente->nome,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[clinicaendereco]') > 0)
		{
			if (isset($agendamento->clinica->nome))
			{
				$clinicaendereco 					= $agendamento->clinica->nome;
				$clinicaendereco 					.= "\nCEP: ";
				$clinicaendereco 					.= $agendamento->clinica->cep;
				$clinicaendereco 					.= "\nEndereço: ";
				$clinicaendereco 					.= $agendamento->clinica->logradouro . ", " . $agendamento->clinica->numero . " " . Cas::nulltoSpace($agendamento->clinica->complemento);
				$clinicaendereco 					.= "\n" .  $agendamento->clinica->bairro . " | " . $agendamento->clinica->cidade . " | " . $agendamento->clinica->estado;
				$mensagem							= str_replace("[clinicaendereco]",$clinicaendereco,$mensagem);
			}
			
		}
		
		if (substr_count($mensagem, '[endereco]') > 0)
		{
			if (isset($agendamento->clinica->nome))
			{
				$endereco 							 = "\nCEP: ".  $agendamento->clinica->cep;
				$endereco 							.= "\nEndereço: ";
				$endereco 							.= $agendamento->clinica->logradouro . ", " . $agendamento->clinica->numero . " " . Cas::nulltoSpace($agendamento->clinica->complemento);
				$endereco 							.= "\n" .  $agendamento->clinica->bairro . " | " . $agendamento->clinica->cidade . " | " . $agendamento->clinica->estado;
				$mensagem							 = str_replace("[endereco]",$endereco,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[telefone]') > 0)
		{
			if (isset($agendamento->clinica->nome))
			{
				$mensagem							= str_replace("[telefone]",$agendamento->clinica->telefone,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[valor]') > 0)
		{
			$valor 									= "R$ ". str_replace(".",",",$agendamento->valor);
			$mensagem								= str_replace("[valor]",$valor,$mensagem);
		}
		
		if (substr_count($mensagem, '[pix]') > 0)
		{
			if (Cas::nulltoSpace($agendamento->pixpage) !="")
			{
				$mensagem							= str_replace("[pix]",$agendamento->pixpage,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[pixqrcode]') > 0)
		{
			if (Cas::nulltoSpace($agendamento->pixqrCode) !="")
			{
				$mensagem							= str_replace("[pixqrcode]",$agendamento->pixqrCode,$mensagem);
			}
		}
		
		if (substr_count($mensagem, '[link_voucher]') > 0)
		{
			if (Cas::nulltoSpace($agendamento->url_voucher) !="")
			{
				$mensagem							= str_replace("[link_voucher]",$agendamento->url_voucher,$mensagem);
				$arquivo							= $agendamento->url_voucher;
			}
		}
		
		if (substr_count($mensagem, '[link_pagamento]') > 0)
		{
			if ($agendamento->boletobankNumber > 0)
			{
				$mensagem							= str_replace("[link_pagamento]",$agendamento->boletopdf,$mensagem);
				$arquivo							= $agendamento->boletopdf;
			} else {
				if (Cas::nulltoSpace($agendamento->paymentLink) !="")
				{
					$mensagem						= str_replace("[link_pagamento]",$agendamento->paymentLink,$mensagem);
				}
			}
		}
		
		$retorno->mensagem							= $mensagem;
		$retorno->arquivo 							= $arquivo;
		
		return $retorno;
		
	}

	public static function harmonizarMensagemAgendamento($agendamento_id,$mensagem,$tipo="")
	{
		if ((substr_count($mensagem, '[tipo]') > 0) or
		    (substr_count($mensagem, '[especialidade]') > 0) or
			(substr_count($mensagem, '[beneficiario]') > 0) or
		    (substr_count($mensagem, '[data_hora]') > 0) or
		    (substr_count($mensagem, '[clinicaendereco]') > 0) or
			(substr_count($mensagem, '[clinica]') > 0) or
			(substr_count($mensagem, '[endereco]') > 0) or
			(substr_count($mensagem, '[telefone]') > 0) or
		    (substr_count($mensagem, '[vencimento]') > 0) or
		    (substr_count($mensagem, '[pagamento]') > 0) or
			(substr_count($mensagem, '[valor]') > 0) or
			(substr_count($mensagem, '[pix]') > 0) or
			(substr_count($mensagem, '[pixqrcode]') > 0) or
		    (substr_count($mensagem, '[link_voucher]') > 0) or
		    (substr_count($mensagem, '[link_pagamento]') > 0))
		{
			$mensagem							= Cas::substituirMensagemAgendamento($agendamento_id,$mensagem);
		}
		
		return $mensagem;
	}

	public static function enviarMensagemAgendamento($agendamento_id,$beneficiario_id,$numero,$mensagem,$enviado_por,$token='5519998557120')
	{
		if ($mensagem !="")
		{
			
			$payload               				= new stdClass;
			$payload->beneficiario_id 			= $beneficiario_id;
			$payload->numero					= preg_replace('/\D/', '', $numero);
			$harmonizar							= Cas::harmonizarMensagemAgendamento($agendamento_id,$mensagem);
			if (isset($harmonizar->mensagem))
			{
				$payload->mensagem				= $harmonizar->mensagem;
				$payload->arquivo				= $harmonizar->arquivo;
			} else {
				$payload->mensagem				= $harmonizar;
				$payload->arquivo				= "";
			}
			$payload->enviado_por 				= $enviado_por;
			$payload->token 					= $token;
			Cas::chatHotJob($payload);
		}
	}

	public static function cancelar_preagendamentos_expirados($hours=24)
	{
		
		$timeLimit 								= Carbon::now()->subHours($hours)->format('Y-m-d H:i:s');
		$preagendamentos 						= DB::table('clinica_beneficiario')
													->where('asituacao_id', '=',2)
													->where('preagendamento_data_hora', '<', $timeLimit)
													->get();
													
		foreach ($preagendamentos as $preagendamento)
		{
			 $agendamento                       	= \App\Models\ClinicaBeneficiario::find($preagendamento->id);
			 if (isset($agendamento->id))
			 {
				 $agendamento->asituacao_id     	= 8;
				 $agendamento->cmotivo_id			= 1;
				 $agendamento->cancelado_data_hora	= date('Y-m-d H:i:s');
				 $agendamento->cancelado_por 		= 1;
				 $agendamento->save();
			 }
		}
		
		return $preagendamentos;
	}

	public static function compararAgendamentos($agendamentoA, $agendamento, $incluirCamposVazios = true)
	{
		// Campos que devem ser comparados
		$camposComparacao = [
			'clinica_id', 
			'especialidade_id', 
			'dmedico', 
			'solicitado_data_hora', 
			'solicitado_por',
			'preagendamento_data_hora', 
			'preagendamento_por', 
			'agendamento_por', 
			'confirmado_data_hora',
			'confirmado_por', 
			'cancelado_data_hora', 
			'cancelado_por', 
			'pagamento_data_hora', 
			'pagamento_por',
			'saldo', 
			'valor', 
			'desconto', 
			'valor_a_pagar', 
			'vencimento', 
			'pagamento', 
			'baixa', 
			'confirma',
			'cobranca', 
			'forma', 
			'situacao', 
			'parcelas', 
			'cidade', 
			'estado', 
			'peso', 
			'altura',
			'medicamento', 
			'observacao', 
			'agendamento_data_hora', 
			'asituacao_id', 
			'cmotivo_id'
		];

		// Nomes amigáveis para os campos
		$nomesAmigaveis = [
			'clinica_id' => 'Clínica',
			'especialidade_id' => 'Especialidade',
			'dmedico' => 'Médico',
			'solicitado_data_hora' => 'Data/Hora Solicitação',
			'solicitado_por' => 'Solicitado Por',
			'preagendamento_data_hora' => 'Data/Hora Pré-agendamento',
			'preagendamento_por' => 'Pré-agendado Por',
			'agendamento_por' => 'Agendado Por',
			'confirmado_data_hora' => 'Data/Hora Confirmação',
			'confirmado_por' => 'Confirmado Por',
			'cancelado_data_hora' => 'Data/Hora Cancelamento',
			'cancelado_por' => 'Cancelado Por',
			'pagamento_data_hora' => 'Data/Hora Pagamento',
			'pagamento_por' => 'Pago Por',
			'saldo' => 'Saldo',
			'valor' => 'Valor',
			'desconto' => 'Desconto',
			'valor_a_pagar' => 'Valor a Pagar',
			'vencimento' => 'Vencimento',
			'pagamento' => 'Pagamento',
			'baixa' => 'Baixa',
			'confirma' => 'Confirma',
			'cobranca' => 'Cobrança',
			'forma' => 'Forma',
			'situacao' => 'Situação',
			'parcelas' => 'Parcelas',
			'cidade' => 'Cidade',
			'estado' => 'Estado',
			'peso' => 'Peso',
			'altura' => 'Altura',
			'medicamento' => 'Medicamento',
			'observacao' => 'Observação',
			'agendamento_data_hora' => 'Data/Hora Agendamento',
			'asituacao_id' => 'Situação ID',
			'cmotivo_id' => 'Motivo ID'
		];

		// Campos que são datas
		$camposDatas = [
			'solicitado_data_hora', 
			'preagendamento_data_hora', 
			'confirmado_data_hora',
			'cancelado_data_hora', 
			'pagamento_data_hora', 
			'agendamento_data_hora',
			'vencimento', 
			'pagamento', 
			'baixa'
		];

		// Campos monetários
		$camposMonetarios = ['saldo', 'valor', 'desconto', 'valor_a_pagar'];
		

		// Converter para arrays se necessário
		$dadosA = self::converterParaArrayAgendamento($agendamentoA);
		$dadosB = self::converterParaArrayAgendamento($agendamento);

		$mudancas = [];
		$contadorMudancas = 0;

		foreach ($camposComparacao as $campo) {
			
			$valorA = $dadosA[$campo] ?? null;
			$valorB = $dadosB[$campo] ?? null;

			// Normalizar valores para comparação
			$valorANormalizado = self::normalizarValorAgendamentoCorrigido($valorA,$campo, $camposMonetarios,$camposDatas);
			$valorBNormalizado = self::normalizarValorAgendamentoCorrigido($valorB,$campo, $camposMonetarios,$camposDatas);

			// Verificar se houve mudança
			if ($valorANormalizado !== $valorBNormalizado) {
				// Verificar se deve incluir campos vazios
				if (!$incluirCamposVazios && 
					(empty($valorANormalizado) || empty($valorBNormalizado))) {
					continue;
				}

				$contadorMudancas++;
				$nomeCampo = $nomesAmigaveis[$campo] ?? $campo;
				
				// Formatar valores para exibição
				$valorAFormatado = self::formatarValorParaExibicaoAgendamento($campo, $valorA, $camposDatas, $camposMonetarios);
				$valorBFormatado = self::formatarValorParaExibicaoAgendamento($campo, $valorB, $camposDatas, $camposMonetarios);

				// Criar texto da mudança
				if ((is_string($valorAFormatado)) and (is_string($valorBFormatado)))
				{
					$mudancas[] = "• {$nomeCampo}: " . $valorAFormatado . " → " . $valorBFormatado;
				} else {
					$mudancas[] = "• {$nomeCampo}: Mudou valor";
				}
			}
		}

		// Retornar resultado
		if (empty($mudancas)) {
			return "Nenhuma alteração detectada nos dados do agendamento.";
		}

		$cabecalho = "";
		//$cabecalho .= "Total de campos alterados: {$contadorMudancas}\n";
		//$cabecalho .= "Data da comparação: " . date('d/m/Y H:i:s') . "\n\n";

		return $cabecalho . implode("\n", $mudancas);
	}

	/**
	 * Versão simplificada que retorna apenas os campos alterados
	 */
	public static function compararAgendamentosSimples($agendamentoA, $agendamento)
	{
		$camposComparacao = [
			'clinica_id', 
			'especialidade_id', 
			'dmedico', 
			'solicitado_data_hora', 
			'solicitado_por',
			'preagendamento_data_hora', 
			'preagendamento_por', 
			'agendamento_por', 
			'confirmado_data_hora',
			'confirmado_por', 
			'cancelado_data_hora', 
			'cancelado_por', 
			'pagamento_data_hora', 
			'pagamento_por',
			'saldo', 
			'valor', 
			'desconto', 
			'valor_a_pagar', 
			'vencimento', 
			'pagamento', 
			'baixa', 
			'confirma',
			'cobranca', 
			'forma', 
			'situacao', 
			'parcelas', 
			'cidade', 
			'estado', 
			'peso', 
			'altura',
			'medicamento', 
			'observacao', 
			'agendamento_data_hora', 
			'asituacao_id', 
			'cmotivo_id'
		];

		$nomesAmigaveis = [
			'clinica_id' => 'Clínica', 
			'especialidade_id' => 'Especialidade', 
			'dmedico' => 'Médico',
			'solicitado_data_hora' => 'Data/Hora Solicitação', 
			'solicitado_por' => 'Solicitado Por',
			'preagendamento_data_hora' => 'Data/Hora Pré-agendamento', 
			'preagendamento_por' => 'Pré-agendado Por',
			'agendamento_por' => 'Agendado Por', 
			'confirmado_data_hora' => 'Data/Hora Confirmação',
			'confirmado_por' => 'Confirmado Por', 
			'cancelado_data_hora' => 'Data/Hora Cancelamento',
			'cancelado_por' => 'Cancelado Por', 
			'pagamento_data_hora' => 'Data/Hora Pagamento',
			'pagamento_por' => 'Pago Por', 
			'saldo' => 'Saldo', 
			'valor' => 'Valor', 
			'desconto' => 'Desconto',
			'valor_a_pagar' => 'Valor a Pagar', 
			'vencimento' => 'Vencimento', 
			'pagamento' => 'Pagamento',
			'baixa' => 'Baixa', 
			'confirma' => 'Confirma', 
			'cobranca' => 'Cobrança', 
			'forma' => 'Forma',
			'situacao' => 'Situação', 
			'parcelas' => 'Parcelas', 
			'cidade' => 'Cidade',
			'estado' => 'Estado', 
			'peso' => 'Peso', 
			'altura' => 'Altura', 
			'medicamento' => 'Medicamento',
			'observacao' => 'Observação', 
			'agendamento_data_hora' => 'Data/Hora Agendamento',
			'asituacao_id' => 'Situação ID', 
			'cmotivo_id' => 'Motivo ID'
		];

		$dadosA = self::converterParaArrayAgendamento($agendamentoA);
		$dadosB = self::converterParaArrayAgendamento($agendamento);

		$mudancas = [];

		foreach ($camposComparacao as $campo) {
			$valorA = self::normalizarValorAgendamento($dadosA[$campo] ?? null);
			$valorB = self::normalizarValorAgendamento($dadosB[$campo] ?? null);

			if ($valorA !== $valorB) {
				$nomeCampo = $nomesAmigaveis[$campo] ?? $campo;
				$mudancas[] = "• {$nomeCampo} alterado";
			}
		}

		return empty($mudancas) ? 
			"Nenhuma alteração detectada." : 
			implode("\n", $mudancas);
	}

	/**
	 * Retorna apenas os nomes dos campos que foram alterados
	 */
	public static function getCamposAlteradosAgendamento($agendamentoA, $agendamento)
	{
		$camposComparacao = [
			'clinica_id', 
			'especialidade_id', 
			'dmedico', 
			'solicitado_data_hora', 
			'solicitado_por',
			'preagendamento_data_hora', 
			'preagendamento_por', 
			'agendamento_por', 
			'confirmado_data_hora',
			'confirmado_por', 
			'cancelado_data_hora', 
			'cancelado_por', 
			'pagamento_data_hora', 
			'pagamento_por',
			'saldo', 
			'valor', 
			'desconto', 
			'valor_a_pagar', 
			'vencimento', 
			'pagamento', 
			'baixa', 
			'confirma',
			'cobranca', 
			'forma', 
			'situacao', 
			'parcelas', 
			'cidade', 
			'estado', 
			'peso', 
			'altura',
			'medicamento', 
			'observacao', 
			'agendamento_data_hora', 
			'asituacao_id', 
			'cmotivo_id'
		];

		$dadosA = self::converterParaArrayAgendamento($agendamentoA);
		$dadosB = self::converterParaArrayAgendamento($agendamento);

		$camposAlterados = [];

		foreach ($camposComparacao as $campo) {
			$valorA = self::normalizarValorAgendamento($dadosA[$campo] ?? null);
			$valorB = self::normalizarValorAgendamento($dadosB[$campo] ?? null);

			if ($valorA !== $valorB) {
				$camposAlterados[] = $campo;
			}
		}

		return $camposAlterados;
	}

	/**
	 * Versão detalhada com histórico
	 */
	public static function compararAgendamentosComHistorico($agendamentoA, $agendamento, $usuario = null)
	{
		$resultado = self::compararAgendamentos($agendamentoA, $agendamento);
		
		if ($usuario) {
			$resultado .= "\n\nAlteração realizada por: {$usuario}";
		}

		$resultado .= "\nData/Hora: " . date('d/m/Y H:i:s');
		
		return $resultado;
	}

	/**
	 * Converte objeto ou array para array associativo
	 */
	public static function converterParaArrayAgendamento($dados)
	{
		if (is_object($dados)) {
			if (method_exists($dados, 'toArray')) {
				return $dados->toArray();
			}
			return (array) $dados;
		}

		return is_array($dados) ? $dados : [];
	}

	/**
	 * Normaliza valor para comparação - VERSÃO CORRIGIDA
	 */
	public static function normalizarValorAgendamentoCorrigido($valor, $campo, $camposMonetarios, $camposDatas)
	{
		if (is_null($valor)) {
			return '';
		}

		if (is_bool($valor)) {
			return $valor ? '1' : '0';
		}

		// CORREÇÃO: Tratamento especial para valores monetários
		if (in_array($campo, $camposMonetarios)) {
			if ($valor === '' || $valor === null) {
				return '0.00';
			}
			
			// Converter para float e depois para string com 2 decimais
			$valorFloat = (float) $valor;
			return number_format($valorFloat, 2, '.', '');
		}

		// CORREÇÃO: Tratamento especial para datas
		if (in_array($campo, $camposDatas)) {
			if (empty($valor)) {
				return '';
			}
			
			// Normalizar formato de data para comparação
			$timestamp = strtotime($valor);
			if ($timestamp !== false) {
				return date('Y-m-d H:i:s', $timestamp);
			}
			
			return trim((string) $valor);
		}

		if (is_numeric($valor)) {
			return (string) $valor;
		}

		return $valor;
	}

	/**
	 * Normaliza valor para comparação
	 */
	public static function normalizarValorAgendamento($valor)
	{
		if (is_null($valor)) {
			return '';
		}

		if (is_bool($valor)) {
			return $valor ? '1' : '0';
		}

		if (is_numeric($valor)) {
			return (string) $valor;
		}

		return trim($valor);
	}

	/**
	 * Formata valor para exibição conforme o tipo do campo
	 */
	public static function formatarValorParaExibicaoAgendamento($campo, $valor, $camposDatas, $camposMonetarios)
	{
		if (is_null($valor) || $valor === '') {
			return '[Vazio]';
		}

		// Campos de data
		if (in_array($campo, $camposDatas)) {
			return self::formatarDataAgendamento($valor);
		}

		// Campos monetários
		if (in_array($campo, $camposMonetarios)) {
			return self::formatarValorMonetarioAgendamento($valor);
		}

		// Campos booleanos
		if (is_bool($valor)) {
			return $valor ? 'Sim' : 'Não';
		}

		// Campos com IDs - buscar nomes se possível
		if (str_ends_with($campo, '_id')) {
			return self::buscarNomePorIdAgendamento($campo, $valor);
		}

		return $valor;
	}

	/**
	 * Formata data para exibição
	 */
	public static function formatarDataAgendamento($data)
	{
		try {
			if (empty($data)) {
				return '[Vazio]';
			}

			// Se já está no formato brasileiro, manter
			if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $data)) {
				return $data;
			}

			// Converter do formato banco (Y-m-d H:i:s) para brasileiro
			$timestamp = strtotime($data);
			if ($timestamp !== false) {
				return date('d/m/Y H:i:s', $timestamp);
			}

			return (string) $data;
		} catch (Exception $e) {
			return (string) $data;
		}
	}

	/**
	 * Formata valor monetário
	 */
	public static function formatarValorMonetarioAgendamento($valor)
	{
		if (is_null($valor) || $valor === '') {
			return '[Vazio]';
		}

		return 'R$ ' . number_format((float) $valor, 2, ',', '.');
	}

	/**
	 * Busca nome por ID em tabelas relacionadas
	 */
	public static function buscarNomePorIdAgendamento($campo, $id)
	{
		if (empty($id)) {
			return '[Vazio]';
		}

		try {
			switch ($campo) {
				case 'clinica_id':
					$nome = DB::table('clinicas')->where('id', $id)->value('nome');
					return $nome ? "{$nome} (ID: {$id})" : "ID: {$id}";

				case 'especialidade_id':
					$nome = DB::table('especialidades')->where('id', $id)->value('nome');
					return $nome ? "{$nome} (ID: {$id})" : "ID: {$id}";

				case 'asituacao_id':
					$nome = DB::table('asituacoes')->where('id', $id)->value('nome');
					return $nome ? "{$nome} (ID: {$id})" : "ID: {$id}";

				case 'cmotivo_id':
					$nome = DB::table('agendamento_cmotivos')->where('id', $id)->value('nome');
					return $nome ? "{$nome} (ID: {$id})" : "ID: {$id}";

				case 'solicitado_por':
				case 'preagendamento_por':
				case 'agendamento_por':
				case 'confirmado_por':
				case 'cancelado_por':
				case 'pagamento_por':
					$nome = DB::table('users')->where('id', $id)->value('name');
					return $nome ? "{$nome} (ID: {$id})" : "ID: {$id}";

				default:
					return "ID: {$id}";
			}
		} catch (Exception $e) {
			return "ID: {$id}";
		}
	}
}
