<?php

namespace App\Helpers;

use App\Services\CelCash\CelCashAuthService;
use App\Services\CelCash\CelCashCustomerService;
use App\Services\CelCash\CelCashSubscriptionService;
use App\Services\CelCash\CelCashChargeService;
use App\Services\CelCash\CelCashTransactionService;
use App\Services\CelCash\CelCashMigrationService;
use App\Services\CelCash\CelCashWebhookService;
use App\Services\CelCash\CelCashBoletoService;
use App\Services\CelCash\CelCashConciliacaoService;

/**
 * Fachada de compatibilidade.
 * Toda lógica foi movida para App\Services\CelCash\*
 * Esta classe mantém a API pública idêntica para não quebrar código existente.
 */
class CelCash
{

    public static function AuthorizationBasic($id)
    {
        return CelCashAuthService::AuthorizationBasic($id);
    }

    public static function AuthorizationPadrao()
    {
        return CelCashAuthService::AuthorizationPadrao();
    }

    public static function Token($id=1)
    {
        return CelCashAuthService::Token($id);
    }

    public static function TokenAgendamento($id=2)
    {
        return CelCashAuthService::TokenAgendamento($id);
    }

    public static function GetCustomer($cpfcnpj,$galaxId=1)
    {
        return CelCashCustomerService::GetCustomer($cpfcnpj, $galaxId);
    }

    public static function ListCustomers($query,$galaxId=1)
    {
        return CelCashCustomerService::ListCustomers($query, $galaxId);
    }

    public static function CelCashMigrarClientes($galaxId)
    {
        return CelCashMigrationService::CelCashMigrarClientes($galaxId);
    }

    public static function CelCashMigrarCliente($celcash,$galaxId=1)
    {
        return CelCashMigrationService::CelCashMigrarCliente($celcash, $galaxId);
    }

    public static function GetSubscription($galaxPayIds,$galaxId=1)
    {
        return CelCashSubscriptionService::GetSubscription($galaxPayIds, $galaxId);
    }

    public static function GetCharges($query,$galaxId=1)
    {
        return CelCashChargeService::GetCharges($query, $galaxId);
    }

    public static function GetSubscriptions($query,$galaxId=1)
    {
        return CelCashSubscriptionService::GetSubscriptions($query, $galaxId);
    }

    public static function CelCashMigrarVendedor($celcash,$galaxId=1)
    {
        return CelCashMigrationService::CelCashMigrarVendedor($celcash, $galaxId);
    }

    public static function CelCashMigrarVendas($galaxId)
    {
        return CelCashMigrationService::CelCashMigrarVendas($galaxId);
    }

    public static function CelCashMigrarContratos($galaxId)
    {
        return CelCashMigrationService::CelCashMigrarContratos($galaxId);
    }

    public static function CelCashMigrarContrato($celcash,$galaxId=1,$tipocontrato='C')
    {
        return CelCashMigrationService::CelCashMigrarContrato($celcash, $galaxId, $tipocontrato);
    }

    public static function CelCashMigrarTransaction($celcash,$galaxId=1,$tipocontrato='C')
    {
        return CelCashMigrationService::CelCashMigrarTransaction($celcash, $galaxId, $tipocontrato);
    }

    public static function celcashWebhook($celcash)
    {
        return CelCashWebhookService::celcashWebhook($celcash);
    }

    public static function obterCarne($galaxPayId,$galaxId=1,$tipo='onePDFSubscription')
    {
        return CelCashBoletoService::obterCarne($galaxPayId, $galaxId, $tipo);
    }

    public static function obterBoletos($galaxPayId,$galaxId=1)
    {
        return CelCashBoletoService::obterBoletos($galaxPayId, $galaxId);
    }

    public static function storeContrato($id)
    {
        return CelCashSubscriptionService::storeContrato($id);
    }

    public static function storeSubscriptions($payload)
    {
        return CelCashSubscriptionService::storeSubscriptions($payload);
    }

    public static function updateContratoWithCharge($celcash)
    {
        return CelCashChargeService::updateContratoWithCharge($celcash);
    }

    public static function updateParcelaWithChargeTransaction($celcash,$response="")
    {
        return CelCashTransactionService::updateParcelaWithChargeTransaction($celcash, $response);
    }

    public static function updateContratoWithSubscription($celcash)
    {
        return CelCashSubscriptionService::updateContratoWithSubscription($celcash);
    }

    public static function storeContratoCharges($parcela_id)
    {
        return CelCashChargeService::storeContratoCharges($parcela_id);
    }

    public static function storeCharges($payload)
    {
        return CelCashChargeService::storeCharges($payload);
    }

    public static function storeChargesAgendamento($payload)
    {
        return CelCashChargeService::storeChargesAgendamento($payload);
    }

    public static function putCustomerIF($cellcash,$cliente,$galaxid=1)
    {
        return CelCashCustomerService::putCustomerIF($cellcash, $cliente, $galaxid);
    }

    public static function getStoreCustomers($id)
    {
        return CelCashCustomerService::getStoreCustomers($id);
    }

    public static function getStoreCustomersAgendamento($id)
    {
        return CelCashCustomerService::getStoreCustomersAgendamento($id);
    }

    public static function storeCliente($id)
    {
        return CelCashCustomerService::storeCliente($id);
    }

    public static function storeClienteAgendamento($id)
    {
        return CelCashCustomerService::storeClienteAgendamento($id);
    }

    public static function storeAgendamentoCharges($id)
    {
        return CelCashChargeService::storeAgendamentoCharges($id);
    }

    public static function storeCustomers($payload, $galaxId=1)
    {
        return CelCashCustomerService::storeCustomers($payload, $galaxId);
    }

    public static function updateCustomers($payload,$galaxPayId,$galaxid=1)
    {
        return CelCashCustomerService::updateCustomers($payload, $galaxPayId, $galaxid);
    }

    public static function storeCustomersAgendamento($payload)
    {
        return CelCashCustomerService::storeCustomersAgendamento($payload);
    }

    public static function getCustomers($query)
    {
        return CelCashCustomerService::getCustomers($query);
    }

    public static function getCustomersAgendamento($query)
    {
        return CelCashCustomerService::getCustomersAgendamento($query);
    }

    public static function buscarMensagem($statcode, $response)
    {
        return CelCashConciliacaoService::buscarMensagem($statcode, $response);
    }

    public static function cancelarContrato($id)
    {
        return CelCashSubscriptionService::cancelarContrato($id);
    }

    public static function cancelSubscriptions($id)
    {
        return CelCashSubscriptionService::cancelSubscriptions($id);
    }

    public static function alterarTransaction($id,$body, $typeId='galaxPayId')
    {
        return CelCashTransactionService::alterarTransaction($id, $body, $typeId);
    }

    public static function alterarCharges($id,$body, $typeId='galaxPayId', $galaxId=1)
    {
        return CelCashChargeService::alterarCharges($id, $body, $typeId, $galaxId);
    }

    public static function adicionarTransaction($id,$body, $typeId='galaxPayId')
    {
        return CelCashTransactionService::adicionarTransaction($id, $body, $typeId);
    }

    public static function cancelTransaction($id,$galaxid=1,$typeId="galaxPayId")
    {
        return CelCashTransactionService::cancelTransaction($id, $galaxid, $typeId);
    }

    public static function getTransaction($query,$galaxid=1)
    {
        return CelCashTransactionService::getTransaction($query, $galaxid);
    }

    public static function cancelCharges($id,$galaxid=1,$typeId="galaxPayId")
    {
        return CelCashChargeService::cancelCharges($id, $galaxid, $typeId);
    }

    public static function celcashPagamentoEmpresa($galaxId=1)
    {
        return CelCashBoletoService::celcashPagamentoEmpresa($galaxId);
    }

    public static function celcashPagamentoConsulta($galaxId=2)
    {
        return CelCashBoletoService::celcashPagamentoConsulta($galaxId);
    }

    public static function CelCashParcelasAvulsa($contrato_id)
    {
        return CelCashBoletoService::CelCashParcelasAvulsa($contrato_id);
    }

    public static function CelCashParcelaAvulsa($parcela_id)
    {
        return CelCashBoletoService::CelCashParcelaAvulsa($parcela_id);
    }

    public static function celcashPagamentoAvulso($galaxId=1)
    {
        return CelCashBoletoService::celcashPagamentoAvulso($galaxId);
    }

    public static function CelCashConsultaConciliar($galaxPayId)
    {
        return CelCashConciliacaoService::CelCashConsultaConciliar($galaxPayId);
    }

    public static function CelCashParcelaConciliar($id)
    {
        return CelCashConciliacaoService::CelCashParcelaConciliar($id);
    }

    public static function getTransactionForDuplicateCheck($cpf, $galaxPayId, $dataVencimento, $valor)
    {
        return CelCashTransactionService::getTransactionForDuplicateCheck($cpf, $galaxPayId, $dataVencimento, $valor);
    }

    public static function listarTransacoesVencimento($payDayFrom,$payDayTo,$customerGalaxPayId)
    {
        return CelCashTransactionService::listarTransacoesVencimento($payDayFrom, $payDayTo, $customerGalaxPayId);
    }

    public static function listarTransacoesStatus($updateStatusFrom,$updateStatusTo,$start=0,$limite=100)
    {
        return CelCashTransactionService::listarTransacoesStatus($updateStatusFrom, $updateStatusTo, $start, $limite);
    }

    public static function listarTransacoesPorData($customerGalaxPayIds, $limite = 100)
    {
        return CelCashTransactionService::listarTransacoesPorData($customerGalaxPayIds, $limite);
    }

    public static function extrairDadosTransacoes($transacoes)
    {
        return CelCashTransactionService::extrairDadosTransacoes($transacoes);
    }

    public static function verificarTransacaoExistente($cpf, $galaxPayId, $dataVencimento, $valor)
    {
        return CelCashTransactionService::verificarTransacaoExistente($cpf, $galaxPayId, $dataVencimento, $valor);
    }

    public static function balancearDadosTransacoesParcelas($contratoId, $transacoesExtraidas)
    {
        return CelCashConciliacaoService::balancearDadosTransacoesParcelas($contratoId, $transacoesExtraidas);
    }

    public static function balancoContrato($contrato_id, $cpf)
    {
        return CelCashConciliacaoService::balancoContrato($contrato_id, $cpf);
    }
}
