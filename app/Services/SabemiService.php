<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Beneficiario;
use App\Models\Contrato;
use App\Models\SabemiEndosso;
use App\Models\SabemiMovimentacao; // Nova tabela de log

/**
 * Classe de Serviço para integração com a API Sabemi.
 * Ajustada para usar a tabela `beneficiarios` para controle de integração.
 */
class SabemiService
{
    protected $baseUrl;
    protected $token;
    protected $codigoCorretor;
    protected $codigoGrupo;
    protected $codigoApolice;

    public function __construct()
    {
        // Configurações devem vir de um arquivo de configuração (ex: config/services.php)
        $this->baseUrl 			= config('services.sabemi.base_url');
        $this->token 			= config('services.sabemi.token');
        $this->codigoCorretor 	= config('services.sabemi.corretor');
        $this->codigoGrupo 		= config('services.sabemi.grupo');
        $this->codigoApolice 	= config('services.sabemi.apolice');
    }

    protected function getHeaders()
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' 	=> 'application/json',
            'Accept' 		=> 'application/json',
        ];
    }

    // ============================================================
    // MÉTODOS DE ENDOSSO (Pesquisa, Abertura, Fechamento)
    // ============================================================

    /**
     * Pesquisa se existe um endosso aberto para o contrato.
     * @param int $codigoContrato
     * @return array
     */
    public function pesquisarEndosso(int $codigoContrato): array
    {
        // Implementação da API MovimentacaoPesquisaEndosso
        // ... (Mesma lógica da versão anterior)
        return ['sucesso' => true, 'codigo_endosso' => 12345, 'status' => 'ABERTO']; // Simulação
    }

    /**
     * Abre um novo endosso para o contrato.
     * @param int $codigoContrato
     * @return array
     */
    public function abrirEndosso(int $codigoContrato): array
    {
        // Implementação da API MovimentacaoNovoEndosso
        // ... (Mesma lógica da versão anterior)
        return ['sucesso' => true, 'codigo_endosso' => 12345, 'mensagem' => 'Novo endosso aberto com sucesso.']; // Simulação
    }

    /**
     * Obtém o código do endosso (pesquisa ou abre um novo).
     * @param int $codigoContrato
     * @return array
     */
    public function obterCodigoEndosso(int $codigoContrato): array
    {
        // Implementação da lógica de pesquisa e abertura
        // ... (Mesma lógica da versão anterior)
        return ['sucesso' => true, 'codigo_endosso' => 12345, 'novo' => false, 'mensagem' => 'Endosso aberto encontrado.']; // Simulação
    }

    /**
     * Fecha o endosso.
     * @param int $codigoEndosso
     * @return array
     */
    public function fecharEndosso(int $codigoEndosso): array
    {
        // Implementação da API MovimentacaoFechamentoEndosso
        // ... (Mesma lógica da versão anterior)
        return ['sucesso' => true, 'mensagem' => 'Endosso fechado com sucesso.']; // Simulação
    }

    // ============================================================
    // MÉTODOS DE MOVIMENTAÇÃO
    // ============================================================

    /**
     * Prepara o payload de movimentação para um beneficiário.
     * Ajustado para usar o modelo Beneficiario e Contrato.
     * @param Beneficiario $beneficiario
     * @param string $tipoMovimentacao (I, E, A)
     * @param string|null $vigenciaRetroativa
     * @return array
     */
    public function prepararPayloadBeneficiario(Beneficiario $beneficiario, string $tipoMovimentacao, ?string $vigenciaRetroativa = null): array
    {
        $cliente 			= $beneficiario->cliente; // Assumindo relacionamento Beneficiario->Cliente
        $contrato 			= $beneficiario->contrato; // Assumindo relacionamento Beneficiario->Contrato
        
        // Simulação de obtenção de dados do plano (necessário ajustar para o seu modelo real)
        // Assumindo que o plano_produto está ligado ao contrato->plano
        // $planoProduto = $contrato->plano->produtos->whereIn('produto_id', [7, 11, 12, 13])->first();
        
        // if (!$planoProduto) {
        //     Log::error("Plano Sabemi não encontrado para o contrato: {$contrato->id}");
        //     return [];
        // }

        // DADOS MOCKADOS PARA EXEMPLO
        $capitalSegurado 	= 10000.00;
        $premioMensal 		= 1.20;
        
        // Estrutura de Endereço e Contato (Simplificada)
        $endereco = [
            'Endereco' 	=> $cliente->endereco, // Assumindo que endereço está no cliente
            'Numero' 	=> $cliente->numero,
            'Bairro' 	=> $cliente->bairro,
            'Cidade'	=> $cliente->cidade,
            'UF' 		=> $cliente->uf,
            'CEP' 		=> $cliente->cep,
        ];

        $contato = [
            'Email' 	=> $cliente->email,
            'Telefone' 	=> $cliente->telefone,
        ];

        // Dados do cliente (segurado)
        $payloadSegurado = [
            'Cpf' 					=> preg_replace('/[^0-9]/', '', $cliente->cpfcnpj),
            'Nome' 					=> $cliente->nome,
            'Nascimento' 			=> $cliente->data_nascimento->format('Y-m-d'), // Assumindo que data_nascimento existe
            'Sexo' 					=> $cliente->sexo, // Assumindo que sexo existe
            'Capital' 				=> $capitalSegurado, 
            'Premio' 				=> $premioMensal, 
            'TipoMovimentacao' 		=> $tipoMovimentacao,
            'VigenciaRetroativa' 	=> $vigenciaRetroativa,
            'CodigoContratoSabemi' 	=> $beneficiario->codigo_contrato_sabemi, // ID_Prime (necessário para Alteração/Exclusão)
            'Afastado' 				=> 'N', 
            'Grupo' 				=> $this->codigoGrupo,
            'Endereco' 				=> $endereco,
            'Contato' 				=> $contato,
        ];

        // Lógica de remoção de campos para Inclusão (não precisa de ID_Prime)
        if ($tipoMovimentacao === 'I') {
            unset($payloadSegurado['CodigoContratoSabemi']); 
        }

        return $payloadSegurado;
    }

    /**
     * Envia a movimentação para a Sabemi.
     * @param int $codigoEndosso
     * @param array $movimentacoes
     * @return array
     */
    public function enviarMovimentacao(int $codigoEndosso, array $movimentacoes): array
    {
        Log::info("Enviando movimentação para o endosso: {$codigoEndosso}. Total de registros: " . count($movimentacoes));
        
        $payload = [
            'CodigoEndosso' => $codigoEndosso,
            'Corretor' 		=> $this->codigoCorretor,
            'Segurados' 	=> $movimentacoes,
            'Itens' 		=> [],
        ];

        // Simulação de envio para a API
        // Em uma implementação real, o código abaixo seria descomentado:
        /*
        $response = Http::withHeaders($this->getHeaders())
            ->post("{$this->baseUrl}/api/MovimentacaoSeguroColetivo", $payload);

        if ($response->successful()) {
            $data = $response->json();
            // ... (Lógica de sucesso/erro)
        }
        */

        // SIMULAÇÃO DE RESPOSTA DA SABEMI
        $simulacaoSucesso = true; // Altere para false para simular erro
        $simulacaoResposta = [
            'Sucesso' 	=> $simulacaoSucesso,
            'Mensagem' 	=> $simulacaoSucesso ? 'Movimentação processada com sucesso.' : 'Erro de validação de CPF.',
            'Detalhes' 	=> [
                'CodigoContratoSabemi' 	=> 'ID_PRIME_' . rand(1000, 9999), // Simula o ID_Prime retornado
                'TotalProcessado' 		=> count($movimentacoes),
                'TotalSucesso' 			=> $simulacaoSucesso ? count($movimentacoes) : 0,
                'TotalErro' 			=> $simulacaoSucesso ? 0 : count($movimentacoes),
            ]
        ];

        if ($simulacaoSucesso) {
            return [
                'sucesso' 	=> true,
                'mensagem' 	=> 'Movimentação enviada com sucesso.',
                'detalhes' 	=> $simulacaoResposta
            ];
        } else {
            return [
                'sucesso' 	=> false,
                'mensagem' 	=> $simulacaoResposta['Mensagem'],
                'detalhes' 	=> $simulacaoResposta
            ];
        }
    }
}
