<?php

namespace App\Helpers;

use App\Services\Cas\FormatacaoService;
use App\Services\Cas\CasAuthService;
use App\Services\Cas\FiltroService;
use App\Services\Cas\ContratoBusinessService;
use App\Services\Cas\ParcelaBusinessService;
use App\Services\Cas\BeneficiarioService;
use App\Services\Cas\AgendamentoBusinessService;
use App\Services\Cas\MensagemService;
use App\Services\Cas\PdfService;
use App\Services\Cas\PermissaoService;
use App\Services\Cas\VendedorService;
use App\Services\Cas\TransacaoService;

/**
 * Fachada de compatibilidade.
 * Toda lógica foi movida para App\Services\Cas\*
 * Esta classe mantém a API pública idêntica para não quebrar código existente.
 */
class Cas
{

    public static function nulltoSpace($value)
    {
        return FormatacaoService::nulltoSpace($value);
    }

    public static function temData($value)
    {
        return FormatacaoService::temData($value);
    }

    public static function validarCpf($cpf)
    {
        return FormatacaoService::validarCpf($cpf);
    }

    public static function limparCpf($value)
    {
        return FormatacaoService::limparCpf($value);
    }

    public static function removerAcentosEMaiusculo($texto)
    {
        return FormatacaoService::removerAcentosEMaiusculo($texto);
    }

    public static function formatCnpjCpf($value)
    {
        return FormatacaoService::formatCnpjCpf($value);
    }

    public static function formatarTelefone($value)
    {
        return FormatacaoService::formatarTelefone($value);
    }

    public static function formatarCPFCNPJ($value,$tipo)
    {
        return FormatacaoService::formatarCPFCNPJ($value, $tipo);
    }

    public static function getMessageValidTexto($message,$caracter="\n")
    {
        return FormatacaoService::getMessageValidTexto($message, $caracter);
    }

    public static function obterEscopos($user)
    {
        return CasAuthService::obterEscopos($user);
    }

    public static function oauthToken($login,$senha,$escopos)
    {
        return CasAuthService::oauthToken($login, $senha, $escopos);
    }

    public static function refreshToken($request)
    {
        return CasAuthService::refreshToken($request);
    }

    public static function obterCombo($value,$id=0)
    {
        return FiltroService::obterCombo($value, $id);
    }

    public static function obterCep($cep)
    {
        return FiltroService::obterCep($cep);
    }

    public static function montar_filtro($query, $payload)
    {
        return FiltroService::montar_filtro($query, $payload);
    }

    public static function obterSituacaoContrato($status)
    {
        return ContratoBusinessService::obterSituacaoContrato($status);
    }

    public static function obterSituacaoParcela($data_vencimento,$data_pagamento,$data_baixa)
    {
        return ParcelaBusinessService::obterSituacaoParcela($data_vencimento, $data_pagamento, $data_baixa);
    }

    public static function podepagarParcela($situacao)
    {
        return ParcelaBusinessService::podepagarParcela($situacao);
    }

    public static function podebaixarParcela($situacao, $galaxPayId)
    {
        return ParcelaBusinessService::podebaixarParcela($situacao, $galaxPayId);
    }

    public static function podeebaixaParcela($situacao,$parcela_id,$contrato_id)
    {
        return ParcelaBusinessService::podeebaixaParcela($situacao, $parcela_id, $contrato_id);
    }

    public static function podeepagarParcela($situacao,$parcela_id,$contrato_id)
    {
        return ParcelaBusinessService::podeepagarParcela($situacao, $parcela_id, $contrato_id);
    }

    public static function podeinserirParcela($contrato_id)
    {
        return ParcelaBusinessService::podeinserirParcela($contrato_id);
    }

    public static function podeexcluirParcela($contrato_id,$parcela_id,$data_pagamento,$data_baixa, $galaxPayId, $nparcela)
    {
        return ParcelaBusinessService::podeexcluirParcela($contrato_id, $parcela_id, $data_pagamento, $data_baixa, $galaxPayId, $nparcela);
    }

    public static function podenegociarParcela($situacao)
    {
        return ParcelaBusinessService::podenegociarParcela($situacao);
    }

    public static function podeeditarParcela($situacao,$galaxPayId)
    {
        return ParcelaBusinessService::podeeditarParcela($situacao, $galaxPayId);
    }

    public static function podeboletoParcela($situacao,$boletobankNumber)
    {
        return ParcelaBusinessService::podeboletoParcela($situacao, $boletobankNumber);
    }

    public static function podeeboletoParcela($situacao,$boletobankNumber, $contrato_id)
    {
        return ParcelaBusinessService::podeeboletoParcela($situacao, $boletobankNumber, $contrato_id);
    }

    public static function podecancelCobranca($situacao, $galaxPayId)
    {
        return ParcelaBusinessService::podecancelCobranca($situacao, $galaxPayId);
    }

    public static function podefaturaContrato($situacao)
    {
        return ContratoBusinessService::podefaturaContrato($situacao);
    }

    public static function podeeditarContrato($situacao,$id=0)
    {
        return ContratoBusinessService::podeeditarContrato($situacao, $id);
    }

    public static function linkassinarContrato($situacao,$paymentLink)
    {
        return ContratoBusinessService::linkassinarContrato($situacao, $paymentLink);
    }

    public static function podecarneContrato($situacao,$contrato_id,$parcela_id=0)
    {
        return ContratoBusinessService::podecarneContrato($situacao, $contrato_id, $parcela_id);
    }

    public static function ecartaoContrato($contrato_id)
    {
        return ContratoBusinessService::ecartaoContrato($contrato_id);
    }

    public static function getStoreClienteBeneficiario($beneficiario)
    {
        return BeneficiarioService::getStoreClienteBeneficiario($beneficiario);
    }

    public static function storeClienteTitular($titular)
    {
        return BeneficiarioService::storeClienteTitular($titular);
    }

    public static function storeUpdateCliente($request)
    {
        return BeneficiarioService::storeUpdateCliente($request);
    }

    public static function storeClienteTitularLote($beneficiarios)
    {
        return BeneficiarioService::storeClienteTitularLote($beneficiarios);
    }

    public static function situacaoAgendamento($value)
    {
        return AgendamentoBusinessService::situacaoAgendamento($value);
    }

    public static function permiteProdutoBeneficio($beneficiario_id,$produto_id)
    {
        return BeneficiarioService::permiteProdutoBeneficio($beneficiario_id, $produto_id);
    }

    public static function beneficiarioProduto($beneficiario_id,$produto_id)
    {
        return BeneficiarioService::beneficiarioProduto($beneficiario_id, $produto_id);
    }

    public static function planoBeneficiarioTipo($beneficiario_id,$ativar=0)
    {
        return BeneficiarioService::planoBeneficiarioTipo($beneficiario_id, $ativar);
    }

    public static function ativarDesativarBeneficiario($beneficiario_id,$ativar)
    {
        return BeneficiarioService::ativarDesativarBeneficiario($beneficiario_id, $ativar);
    }

    public static function ativarDesativarProdutos($payload)
    {
        return BeneficiarioService::ativarDesativarProdutos($payload);
    }

    public static function ativarDesativarProduto($beneficiario_id,$produto_id,$ativar=true,$id="")
    {
        return BeneficiarioService::ativarDesativarProduto($beneficiario_id, $produto_id, $ativar, $id);
    }

    public static function gerarLinkMagico($beneficiario_id,$produto_id,$ip)
    {
        return BeneficiarioService::gerarLinkMagico($beneficiario_id, $produto_id, $ip);
    }

    public static function obterPlanoBeneficios($beneficiario_id)
    {
        return BeneficiarioService::obterPlanoBeneficios($beneficiario_id);
    }

    public static function obterBeneficios($plano_id,$tipo_beneficiario,$beneficiario_id)
    {
        return BeneficiarioService::obterBeneficios($plano_id, $tipo_beneficiario, $beneficiario_id);
    }

    public static function limparCacheMenu()
    {
        return PermissaoService::limparCacheMenu();
    }

    public static function buscarParentId($id)
    {
        return PermissaoService::buscarParentId($id);
    }

    public static function verificadorPermissoes($menu_id, $perfil_id, $opcao_id = 0)
    {
        return PermissaoService::verificadorPermissoes($menu_id, $perfil_id, $opcao_id);
    }

    public static function obterIdPermissaoListar()
    {
        return PermissaoService::obterIdPermissaoListar();
    }

    public static function obterFilhasMenu($parent_id)
    {
        return PermissaoService::obterFilhasMenu($parent_id);
    }

    public static function inserirListarMenu($menu_id, $perfil_id, $opcao_id)
    {
        return PermissaoService::inserirListarMenu($menu_id, $perfil_id, $opcao_id);
    }

    public static function exportCsv($payload, $beneficiarios)
    {
        return MensagemService::exportCsv($payload, $beneficiarios);
    }

    public static function isValidEmail($email)
    {
        return FormatacaoService::isValidEmail($email);
    }

    public static function ativarBeneficiarioContratosValidos()
    {
        return BeneficiarioService::ativarBeneficiarioContratosValidos();
    }

    public static function inativarBeneficiarioComParcelasVencidas($dias=9)
    {
        return BeneficiarioService::inativarBeneficiarioComParcelasVencidas($dias);
    }

    public static function cancelar_preagendamentos_expirados($hours=24)
    {
        return AgendamentoBusinessService::cancelar_preagendamentos_expirados($hours);
    }

    public static function obterQuemFez($id)
    {
        return TransacaoService::obterQuemFez($id);
    }

    public static function substituirMensagemAgendamento($agendamento_id,$mensagem)
    {
        return AgendamentoBusinessService::substituirMensagemAgendamento($agendamento_id, $mensagem);
    }

    public static function harmonizarMensagemAgendamento($agendamento_id,$mensagem,$tipo="")
    {
        return AgendamentoBusinessService::harmonizarMensagemAgendamento($agendamento_id, $mensagem, $tipo);
    }

    public static function enviarMensagemAgendamento($agendamento_id,$beneficiario_id,$numero,$mensagem,$enviado_por,$token='5519998557120')
    {
        return AgendamentoBusinessService::enviarMensagemAgendamento($agendamento_id, $beneficiario_id, $numero, $mensagem, $enviado_por, $token);
    }

    public static function chatHotJob($payload)
    {
        return MensagemService::chatHotJob($payload);
    }

    public static function chatHotMensagem($payload)
    {
        return MensagemService::chatHotMensagem($payload);
    }

    public static function gerarContratoPDF($id,$ip)
    {
        return PdfService::gerarContratoPDF($id, $ip);
    }

    public static function gerarVoucherPDF($id)
    {
        return PdfService::gerarVoucherPDF($id);
    }

    public static function obterIDVendedor($id)
    {
        return VendedorService::obterIDVendedor($id);
    }

    public static function obterTokenVendedor($id)
    {
        return VendedorService::obterTokenVendedor($id);
    }

    public static function cancelar_contrato_parcela()
    {
        return TransacaoService::cancelar_contrato_parcela();
    }

    public static function cancelar_parcela_beneficiario($id)
    {
        return TransacaoService::cancelar_parcela_beneficiario($id);
    }

    public static function formatarTextoContrato($html,$id)
    {
        return PdfService::formatarTextoContrato($html, $id);
    }

    public static function formatarContratoParaPDF($texto)
    {
        return PdfService::formatarContratoParaPDF($texto);
    }

    public static function obter_parcelaAbertas($contrato_id)
    {
        return ParcelaBusinessService::obter_parcelaAbertas($contrato_id);
    }

    public static function calcularJurosBoleto($valorBoleto, $dataVencimentoOuDiasAtraso, $dataAtual = null, $taxaJurosMensal = 5.0, $percentualMulta = 2.0)
    {
        return ParcelaBusinessService::calcularJurosBoleto($valorBoleto, $dataVencimentoOuDiasAtraso, $dataAtual, $taxaJurosMensal, $percentualMulta);
    }

    public static function ajustarDiaVencimento($data)
    {
        return ParcelaBusinessService::ajustarDiaVencimento($data);
    }

    public static function compararAgendamentos($agendamentoA, $agendamento, $incluirCamposVazios = true)
    {
        return AgendamentoBusinessService::compararAgendamentos($agendamentoA, $agendamento, $incluirCamposVazios);
    }

    public static function compararAgendamentosSimples($agendamentoA, $agendamento)
    {
        return AgendamentoBusinessService::compararAgendamentosSimples($agendamentoA, $agendamento);
    }

    public static function getCamposAlteradosAgendamento($agendamentoA, $agendamento)
    {
        return AgendamentoBusinessService::getCamposAlteradosAgendamento($agendamentoA, $agendamento);
    }

    public static function compararAgendamentosComHistorico($agendamentoA, $agendamento, $usuario = null)
    {
        return AgendamentoBusinessService::compararAgendamentosComHistorico($agendamentoA, $agendamento, $usuario);
    }

    public static function converterParaArrayAgendamento($dados)
    {
        return AgendamentoBusinessService::converterParaArrayAgendamento($dados);
    }

    public static function normalizarValorAgendamentoCorrigido($valor, $campo, $camposMonetarios, $camposDatas)
    {
        return AgendamentoBusinessService::normalizarValorAgendamentoCorrigido($valor, $campo, $camposMonetarios, $camposDatas);
    }

    public static function normalizarValorAgendamento($valor)
    {
        return AgendamentoBusinessService::normalizarValorAgendamento($valor);
    }

    public static function formatarValorParaExibicaoAgendamento($campo, $valor, $camposDatas, $camposMonetarios)
    {
        return AgendamentoBusinessService::formatarValorParaExibicaoAgendamento($campo, $valor, $camposDatas, $camposMonetarios);
    }

    public static function formatarDataAgendamento($data)
    {
        return AgendamentoBusinessService::formatarDataAgendamento($data);
    }

    public static function formatarValorMonetarioAgendamento($valor)
    {
        return AgendamentoBusinessService::formatarValorMonetarioAgendamento($valor);
    }

    public static function buscarNomePorIdAgendamento($campo, $id)
    {
        return AgendamentoBusinessService::buscarNomePorIdAgendamento($campo, $id);
    }

    public static function obterComo($transaction)
    {
        return TransacaoService::obterComo($transaction);
    }

    public static function obterParcelaComo($transaction,$achar_como)
    {
        return TransacaoService::obterParcelaComo($transaction, $achar_como);
    }

    public static function obterParcelaMyId($transaction)
    {
        return TransacaoService::obterParcelaMyId($transaction);
    }

    public static function obterParcelachargeMyId($transaction)
    {
        return TransacaoService::obterParcelachargeMyId($transaction);
    }

    public static function obterParcelagalaxPayId($transaction)
    {
        return TransacaoService::obterParcelagalaxPayId($transaction);
    }

    public static function atualizarCriarParcela($transaction,$parcela,$transacao_data_id,$atualizar='N')
    {
        return TransacaoService::atualizarCriarParcela($transaction, $parcela, $transacao_data_id, $atualizar);
    }

    public static function transacaoNencontrada($transaction)
    {
        return TransacaoService::transacaoNencontrada($transaction);
    }

    public static function atualizarTransaction($transactions,$transacao_data_id,$atualizar='N')
    {
        return TransacaoService::atualizarTransaction($transactions, $transacao_data_id, $atualizar);
    }

    public static function listarTransacoesStatus($payload)
    {
        return TransacaoService::listarTransacoesStatus($payload);
    }

    public static function atualizarDivergencia($id)
    {
        return TransacaoService::atualizarDivergencia($id);
    }

    public static function obterDadosBeneficiario($cpf)
    {
        return BeneficiarioService::obterDadosBeneficiario($cpf);
    }

    public static function stripPrefix(string $value, string $prefix)
    {
        return FormatacaoService::stripPrefix($value, $prefix);
    }
}
