<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\BeneficiarioController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClinicaController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\ParcelaController;
use App\Http\Controllers\PeriodicidadeController;
use App\Http\Controllers\PlanoController;
use App\Http\Controllers\SituacaoController;
use App\Http\Controllers\MotivoController;
use App\Http\Controllers\AsituacaoController;
use App\Http\Controllers\VendedorController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\EspecialidadeController;
use App\Http\Controllers\AgendamentoController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PermissaoController;
use App\Http\Controllers\CelCashController;
use App\Http\Controllers\CartaoController;
use App\Http\Controllers\ConexaSaudeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\CasAppController;
use App\Http\Controllers\EpharmaController;
use App\Http\Controllers\ImagemController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SabemiDashboardController;
use App\Http\Controllers\ConciliacaoController;

Route::prefix('epharma')->group(function () {
    // Teste de conectividade
    Route::get('/teste-conectividade', [EpharmaController::class, 'testeConectividade']);
    
    // Cadastro de beneficiário completo (com SKUs e questionários)
    Route::post('/beneficiario', [EpharmaController::class, 'cadastrarBeneficiario']);
    
    // Cadastro de beneficiário simples (apenas dados pessoais)
    Route::post('/beneficiario-simples', [EpharmaController::class, 'cadastrarBeneficiarioSimples']);
    
    // Informações sobre campos disponíveis
    Route::get('/informacoes-campos', [EpharmaController::class, 'informacoesCampos']);
    
    // Limpar cache do token
    Route::delete('/cache', [EpharmaController::class, 'limparCache']);
    
    // Teste completo do fluxo
    Route::post('/teste-fluxo-completo', [EpharmaController::class, 'testeFluxoCompleto']);
    
    // Exemplo pré-configurado
    Route::post('/exemplo-teste', [EpharmaController::class, 'exemploTeste']);
});

Route::get('carne', [CelCashController::class, 'carne_view']);
Route::get('assinatura', [ContratoController::class, 'obter_assinatura']);
Route::post('assinatura', [ContratoController::class, 'gravar_assinatura']);
Route::post('parcelas/boleto/{id}', [ParcelaController::class, 'gerar_boleto']);
Route::post('/testar/transacao/existente', [CelCashController::class, 'testarTransacaoExistente']);
Route::post('/listar/transacoes/data', [CelCashController::class, 'listarTransacoesPorData']);
Route::get('/listar/transacoes/status', [CelCashController::class, 'listarTransacoesStatus']);
Route::post('/ajustar/transacoes/pagas', [CelCashController::class, 'ajustarTransacoesParcelasPagas']);
Route::post('app/login', [AppController::class, 'login']);

Route::post('casappbr/login', [CasAppController::class, 'login']);

//Route::middleware(['throttle:5,1', 'bindings'])->group(function () {
	Route::post('/auth/password-reset/start', [CasAppController::class, 'esqueci_minha_senha']);
	Route::post('/auth/password-reset/verify', [CasAppController::class, 'recuperar_minha_senha']);
	Route::post('/auth/password-reset/complete', [CasAppController::class, 'alterar_minha_senha']);
//});

Route::get('/conexa/paciente', [ConexaSaudeController::class, 'buscarPaciente']);
Route::get('/conexa/paciente/status', [ConexaSaudeController::class, 'buscarPacienteStatus']);
Route::get('/conexa/paciente/lista', [ConexaSaudeController::class, 'listarPacientes']);
Route::post('/conexa/paciente', [ConexaSaudeController::class, 'createOrUpdatePatient']);
Route::post('/conexa/paciente/activate/{id}', [ConexaSaudeController::class, 'activate']);
Route::post('/conexa/paciente/inactivate/{id}', [ConexaSaudeController::class, 'inactivate']);
Route::post('/conexa/paciente/accept/term', [ConexaSaudeController::class, 'acceptTerm']);
Route::get('/conexa/paciente/term/accept/{id}', [ConexaSaudeController::class, 'termAccept']);
Route::get('/conexa/paciente/magiclinkapp/{id}', [ConexaSaudeController::class, 'generateMagicLinkAccessapp']);
Route::post('/contrato/cancelado',[ContratoController::class, 'cancelar_parcela_beneficiario']);

Route::post('/token/{id}', [CelCashController::class, 'get_token']);
Route::post('/webhook/celcash', [CelCashController::class, 'webhook_celcash']);
Route::get('/webhook/celcash', [CelCashController::class, 'webhook_celcash']);
Route::post('/webhook/celcashc', [CelCashController::class, 'webhook_celcashc']);
Route::get('/webhook/celcashc', [CelCashController::class, 'webhook_celcashc']);
Route::get('/cep', [ClienteController::class, 'obter_cep']);

Route::post('/parcelas/excluir/duplicados', [ParcelaController::class, 'parcelas_excluir_duplicados']);

Route::group(array('prefix' => 'customer'), function () {
    Route::get('/obter', [CelCashController::class, 'get_customer']);
    Route::get('/list', [CelCashController::class, 'list_customers']);
});

Route::group(array('prefix' => 'subscription'), function () {
    Route::get('/obter', [CelCashController::class, 'get_subscription']);
    Route::get('/list', [CelCashController::class, 'list_subscriptions']);
});

Route::group(array('prefix' => 'cartao'), function () {
    Route::post('/consulta',[CartaoController::class, 'obter_cartao']);
    Route::post('/dependentes',[CartaoController::class, 'obter_dependentes']);
	Route::post('/titular',[CartaoController::class, 'store_titular']);
});

Route::post('enviar/mensagem', [BeneficiarioController::class, 'enviarMensagem']);
Route::get('beneficiarios/inativar', [BeneficiarioController::class, 'inativarBeneficiarioComParcelasVencidas']);
Route::get('beneficiarios/ativar', [BeneficiarioController::class, 'ativarBeneficiarioContratosValidos']);
Route::get('parcelas/cancel', [ParcelaController::class, 'cancel']);
Route::get('parcelas/boleto/{id}', [ParcelaController::class, 'boleto_view']);
Route::post('parcelas/boleto/{id}', [ParcelaController::class, 'gerar_boleto']);
Route::get('agendamentos/boleto/{id}', [AgendamentoController::class, 'boleto_view']);
Route::get('agendamentos/voucher/{id}', [AgendamentoController::class, 'voucher_view']);
Route::get('parcelas/boletos/{id}', [ParcelaController::class, 'boletos_view']);
Route::get('contratos/carne/{id}', [ContratoController::class, 'carne_view']);
Route::get('contratos/pdf/{id}', [ContratoController::class, 'contratopdf_view']);
Route::get('/cas/menu', [UserController::class, 'getMenu']);
Route::get('/perfil/menu', [UserController::class, 'menu']);
Route::get('preagendamentos/expirados', [AgendamentoController::class, 'preagendamento_expirados']);
Route::get('sincronizar/pagamentos/empresas', [AgendamentoController::class, 'sincronizar_pagamento_empresas']);
Route::get('aguardando/confirmacao/pagamento', [AgendamentoController::class, 'aguardando_confirmacao_pagamento']);
Route::post('/app/senha/linksms', [AppController::class, 'senhalink_sms']);
Route::post('voucher/{id}', [AgendamentoController::class, 'gerar_voucher']);


Route::middleware('auth:api')->group(function () {

	Route::group(array('prefix' => 'app'), function () { 
		Route::get('/detalhe/{id}', [AppController::class, 'view_agendamento']);
		Route::get('/agendamentos/{id}', [AppController::class, 'index_agendamentos']);
		Route::get('/dependentes/{id}', [AppController::class, 'index_dependentes']);
		Route::get('/beneficiarios/{id}', [AppController::class, 'index_beneficiarios']);
		Route::get('/cespecialidades', [AppController::class, 'index_cespecialidades']);
		Route::get('/beneficiario/{id}', [AppController::class, 'view_beneficiario']);
		Route::post('/alterarsenha/{id}', [AppController::class, 'alterar_senha']);
		Route::get('/beneficio/{id}', [AppController::class, 'view_beneficio']);
		Route::get('/imagem/{id}', [AppController::class, 'view_imagem']);
		Route::get('/produto/{id}', [AppController::class, 'view_produto']);
		Route::post('/dependente', [AppController::class, 'store_dependente']);
		Route::post('/beneficiario/{id}', [AppController::class, 'update_beneficiario']);
		Route::get('/local/{id}', [AppController::class, 'local_beneficiario']);
		Route::get('/cidades', [AppController::class, 'local_cidades']);
		Route::post('/upload/exame/{id}', [AppController::class, 'upload_exame']);
		Route::delete('/exame/{id}', [AppController::class, 'delete_exame']);
		Route::post('/preagendamento/{id}', [AppController::class, 'pre_agendamento']);
		Route::post('/agendamento/cancelar/{id}', [AppController::class, 'cancelar_agendamento']);
		Route::post('/agendamento/confirmar/{id}', [AppController::class, 'confirmar_agendamento']);
		Route::post('/selecionardata/{id}', [AppController::class, 'selecionar_data']);
		Route::get('/parcelas/{id}', [AppController::class, 'index_parcelas']);
		Route::post('beneficiarios/produtos/link', [AppController::class, 'ativarGerarLinkMagico']);
		Route::get('/permissao/beneficiario', [AppController::class, 'verificarAdimplentePermissao']);
		Route::get('/motivos/{id}', [AppController::class, 'index_motivos']);
		Route::prefix('casappbr')->group(function () {
			Route::post('/alterar_senha/{id}', [CasAppController::class, 'alterar_senha']);
			Route::get('/home', [CasAppController::class, 'home']);
			Route::get('/dadospessoais/{id}', [CasAppController::class, 'view_dados_pessoais']);
			Route::post('/dadospessoais/{id}', [CasAppController::class, 'update_dados_pessoais']);
			Route::get('/dependente/{id}', [CasAppController::class, 'view_dependente']);
			Route::post('/dependente/{id}', [CasAppController::class, 'update_dependente']);
			Route::get('/especialidade', [CasAppController::class, 'index_especialidades']);
			
			Route::post('/ativarbeneficio/{id}', [CasAppController::class, 'ativarBeneficio']);
			Route::get('/linkmagicoconexa/{id}', [CasAppController::class, 'linkMagicoConexa']);
			
			Route::get('/local/{id}', [CasAppController::class, 'local_beneficiario']);
			Route::get('/cidades', [CasAppController::class, 'local_cidades']);
		
			Route::get('/agendamento/{id}', [CasAppController::class, 'index_agendamentos']);
			Route::get('/agendamento/buscar/{id}', [CasAppController::class, 'view_agendamento']);
			Route::post('/agendamento/{id}', [CasAppController::class, 'agendamento']);
			Route::get('/cmotivos', [CasAppController::class, 'index_cmotivos']);
		
			Route::post('/agendamento/cancelar/{id}', [CasAppController::class, 'agendamento_cancelar']);
			
			Route::get('/mensalidade/{id}', [CasAppController::class, 'index_mensalidades']);
			
			Route::get('/empresa/{id}', [CasAppController::class, 'view_empresa']);
		
			// Integração Full Conexa - Pronto Atendimento
			Route::post('/appointments/immediate', [CasAppController::class, 'criarAtendimentoImediato']);
			Route::post('/appointments/immediate/{idProtocol}/files', [CasAppController::class, 'anexarArquivoAtendimento']);
			Route::get('/appointments/immediate/active/{patientId}', [CasAppController::class, 'obterAtendimentoImediatoPaciente']);
			Route::post('/appointments/immediate/cancel/{patientId}', [CasAppController::class, 'cancelarAtendimentoImediato']);
			Route::get('/appointments/last-call/{patientId}', [CasAppController::class, 'obterUltimaChamada']);

			// Integração Full Conexa - Agendado com Especialidade Médica
			Route::get('/specialties', [CasAppController::class, 'listarEspecialidades']);
			Route::get('/doctors/specialty/{specialtyId}/{page}', [CasAppController::class, 'listarMedicosPorEspecialidade']);
			Route::get('/doctor/{doctorId}/schedule', [CasAppController::class, 'obterHorariosDisponiveisMedico']);
			Route::post('/appointments/scheduled/doctor', [CasAppController::class, 'criarAgendamentoMedico']);

			// Integração Full Conexa - Agendado com Outras Especialidades
			Route::get('/professionals/name/{page}', [CasAppController::class, 'listarProfissionaisSaudePorNome']);
			Route::get('/professionals/{id}/schedule', [CasAppController::class, 'obterHorariosDisponiveisProfissional']);
			Route::post('/appointments/scheduled/professional', [CasAppController::class, 'criarAgendamentoProfissional']);

			// Integração Full Conexa - Avaliação
			Route::post('/nps/save', [CasAppController::class, 'salvarAvaliacaoAtendimento']);
		});
	});

	Route::prefix('contratos')->group(function () {
		Route::get('/parcelas/comparativo/{contratoId}/{cellCashId}', 
			[ContratoController::class, 'getComparativoParcelas']
		)->name('contratos.parcelas.comparativo');
	});
	
	Route::get('/atendimentos', [AgendamentoController::class, 'atendimentos_index']);
	Route::get('/atendimentos/clinicas', [AgendamentoController::class, 'atendimentos_clinicas']);
	Route::put('/atendimentos/status/{id}', [AgendamentoController::class, 'atendimentos_status']);
	Route::post('/atendimentos/validar-voucher', [AgendamentoController::class, 'atendimentos_voucher']);
	
	Route::get('/dashboard/clientes', [DashboardController::class, 'clientes']);
	Route::get('/dashboard/beneficiarios', [DashboardController::class, 'beneficiarios']);
	Route::get('/dashboard/contratos', [DashboardController::class, 'contratos']);
	Route::get('/dashboard/clinicas', [DashboardController::class, 'clinicas']);
	Route::get('/dashboard/acompanhamento-mensal', [DashboardController::class, 'acompanhamento_mensal']);
	Route::get('/dashboard/agendamentos-mensais', [DashboardController::class, 'agendamentos_mensais']);
	Route::get('/dashboard/pagamentos-mensais', [DashboardController::class, 'pagamentos_mensais']);
	Route::get('/dashboard/pagamentos-consultas-exames', [DashboardController::class, 'pagamentos_consultas_exames']);
    
    // Dashboard Sabemi
    Route::get('/sabemi/dashboard/all', [SabemiDashboardController::class, 'index']);
    Route::get('/sabemi/endossos', [SabemiDashboardController::class, 'endossos']);
    Route::post('/sabemi/base-ativa/filtro', [SabemiDashboardController::class, 'baseAtiva']);
    Route::get('/sabemi/base-ativa/{id}', [SabemiDashboardController::class, 'detalhesBeneficiario']);
    Route::post('/sabemi/divergencias/filtro', [SabemiDashboardController::class, 'divergencias']);
    Route::post('/sabemi/registrar/{id}', [SabemiDashboardController::class, 'registrarBeneficiario']);
		
    Route::post('agendamentos/mensagem/whatsapp/{id}', [AgendamentoController::class, 'enviar_mensagem_whatsapp']);
	Route::post('agendamentos/conciliar-pagamento/{id}', [AgendamentoController::class, 'conciliar_consulta']);
	Route::post('agendamentos/cancelar-cobranca/{id}', [AgendamentoController::class, 'cancelar_cobranca']);
	Route::post('agendamentos/confirmar-pagamento', [AgendamentoController::class, 'confirmar_pagamento']);
	Route::post('agendamentos/cancelar-pagamento-saldo/{id}', [AgendamentoController::class, 'cancelar_pagamento_saldo']);
	Route::post('agendamentos/validartokenotp', [AgendamentoController::class, 'validar_token_otp']);
	Route::get('/transacoes/periodo', [CelCashController::class, 'transacoesData']);
	Route::post('/transacoes/conciliar', [CelCashController::class, 'transacoesConciliar']);
	Route::get('/transacoes/divergencias', [CelCashController::class, 'transacoesDivergencias']);
	Route::put('/transacoes/divergencias/atualizar-crm/{id}', [CelCashController::class, 'atualizarDivergencia']);
		
	Route::get('/perfil', [UserController::class, 'show']);
	Route::post('perfil/foto', [UserController::class, 'update_foto']);
	Route::post('perfil/senha', [UserController::class, 'update_senha']);
	Route::post('perfil', [UserController::class, 'update']);
	Route::post('parcelas/parcela/whatsapp/{id}', [ParcelaController::class, 'enviar_parcelaWhatsapp']);
	Route::post('contratos/assinatura/whatsapp/{id}', [ContratoController::class, 'enviar_assinaturaWhatsapp']);
	Route::post('contratos/contrato/whatsapp/{id}', [ContratoController::class, 'enviar_contratoWhatsapp']);
	Route::post('parcelas/pagamento/{id}', [ParcelaController::class, 'pagamento']);
	Route::post('parcelas/pagamento/fora/{id}', [ParcelaController::class, 'pagamentoFora']);
	Route::post('parcelas/observacao/{id}', [ParcelaController::class, 'observacao']);
	Route::post('parcelas/cancelar/pagamento/{id}', [ParcelaController::class, 'cancelar_pagamento']);
	Route::post('parcelas/cancelar/baixa/{id}', [ParcelaController::class, 'cancelar_baixa']);
	Route::post('parcelas/cancelar/cobranca/{id}', [ParcelaController::class, 'cancelar_cobranca']);
	Route::post('parcelas/excel', [ParcelaController::class, 'excel']);
	Route::post('contratos/excel', [ContratoController::class, 'excel']);
	Route::post('agendamentos/excel', [AgendamentoController::class, 'excel']);
	Route::post('parcelas/ajustar', [ParcelaController::class, 'ajustar']);
    Route::post('parcelas/filtro', [ParcelaController::class, 'filtro']);
	Route::post('parcelas/conciliacao/{id}', [ParcelaController::class, 'conciliar_parcela']);
	Route::post('contratos/filtro', [ContratoController::class, 'filtro']);
	Route::get('contratos/renovar/{id}', [ContratoController::class, 'obter_parcelas_todas']);
	Route::post('contratos/renovar/{id}', [ContratoController::class, 'renovar']);
	Route::post('contratos/renovacao/confirmar/{id}', [ContratoController::class, 'renovar_confirmar']);
	Route::post('contratos/modificar/confirmar/{id}', [ContratoController::class, 'modificar_confirmar']);
	Route::post('contratos/suspender/{id}', [ContratoController::class, 'suspender_contrato']);
	Route::get('contratos/renegociar/{id}', [ContratoController::class, 'obter_parcelas_vencidas']);
	
	Route::get('contratos/planos/listar', [ContratoController::class, 'contrato_planos_listar']);
	Route::post('contratos/planos', [ContratoController::class, 'contrato_plano_store']);
	Route::get('contratos/planos/{id}', [ContratoController::class, 'contrato_plano_view']);
	Route::put('contratos/planos/{id}', [ContratoController::class, 'contrato_plano_update']);
	Route::delete('contratos/planos/{id}', [ContratoController::class, 'contrato_plano_destroy']);
	
	Route::post('contratos/renegociar/salvar', [ContratoController::class, 'negociar_parcelas_vencidas']);
	Route::post('contratos/cancelarparcela/{id}', [ContratoController::class, 'cancelar_parcela_falhou']);
	Route::post('beneficiarios/importar/titular', [BeneficiarioController::class, 'importar_titular']);
	Route::post('beneficiarios/importar/lote', [BeneficiarioController::class, 'importar_titular_lote']);
	Route::post('beneficiarios/produtos/ativardesativar', [BeneficiarioController::class, 'ativarDesativarProdutos']);
	Route::post('beneficiarios/beneficiarios/ativardesativar', [BeneficiarioController::class, 'ativarDesativarBeneficiarios']);
	Route::post('beneficiarios/produtos/link', [BeneficiarioController::class, 'gerarLinkMagico']);
	Route::delete('contratos/cancelar/{id}', [ContratoController::class, 'cancelar']);
	Route::post('beneficiarios/filtro', [BeneficiarioController::class, 'filtro']);
	Route::post('beneficiarios/inativar-todos/{id}', [BeneficiarioController::class, 'inativar_todos']);
	Route::post('beneficiarios/ativadesativa/filtro', [BeneficiarioController::class, 'filtro_ativardesativar']);
	Route::post('beneficiarios/ativadesativa/excel', [BeneficiarioController::class, 'excel_ativardesativar']);
	Route::post('beneficiarios/excel', [BeneficiarioController::class, 'excel']);
    Route::apiResource('beneficiarios', BeneficiarioController::class);
	Route::get('beneficiarios/titular/{id}', [BeneficiarioController::class, 'index_titular']);
	Route::get('mensagens/listar', [BeneficiarioController::class, 'listar_mensagem']);
	Route::post('mensagens/enviar/{id}', [BeneficiarioController::class, 'enviar_mensagem_whatsapp']);
	Route::post('mensagens/enviaremmassa', [BeneficiarioController::class, 'enviar_mensagem_whatsapp_massa']);
    Route::apiResource('clientes', ClienteController::class);
	Route::post('clientes/filtro', [ClienteController::class, 'index']);
	Route::post('clientes/updatestore', [ClienteController::class, 'storeUpdate']);
	Route::post('clientes/{id}/upload-logo', [ClienteController::class, 'upload_logo']);
	Route::delete('clientes/{id}/logo', [ClienteController::class, 'delete_logo']);
	Route::post('beneficiarios/updatestore', [BeneficiarioController::class, 'storeUpdate']);
	Route::apiResource('clinicas', ClinicaController::class);
	Route::apiResource('medicos', MedicoController::class);
    Route::get('clinicaespecialidade/{id}', [ClinicaController::class, 'clinica_especialidade_index']);
    Route::post('clinicaespecialidade/{id}', [ClinicaController::class, 'clinica_especialidade_store']);
	Route::post('clinicaespecialidade/valores/{id}', [ClinicaController::class, 'clinica_especialidade_valor']);
    Route::apiResource('contratos', ContratoController::class);
	Route::get('/parcelas/nova', [ParcelaController::class, 'nova_parcela']);
    Route::apiResource('parcelas', ParcelaController::class);
    Route::apiResource('periodicidades', PeriodicidadeController::class);
    Route::apiResource('planos', PlanoController::class);
    Route::apiResource('situacoes', SituacaoController::class);
	Route::apiResource('motivos', MotivoController::class);
	Route::apiResource('asituacoes', AsituacaoController::class);
	Route::post('asituacoes/{id}/upload-audio', [AsituacaoController::class, 'upload_audio']);
	Route::delete('asituacoes/{id}/delete-audio', [AsituacaoController::class, 'delete_audio']);
    Route::apiResource('vendedores', VendedorController::class);
    Route::apiResource('produtos', ProdutoController::class);
	Route::apiResource('imagens', ImagemController::class);
	Route::apiResource('tokens', TokenController::class);
    Route::apiResource('especialidades', EspecialidadeController::class);
	Route::post('agendamentos/filtro', [AgendamentoController::class, 'index']);
	Route::post('agendamentos/upload-imagem', [AgendamentoController::class, 'upload_imagem']);
	Route::delete('agendamentos/excluir-imagem/{id}', [AgendamentoController::class, 'excluir_imagem']);
    Route::apiResource('agendamentos', AgendamentoController::class);
	Route::apiResource('usuarios', UsuarioController::class);
	
	Route::post('agendamentos/aupdate/{id}', [AgendamentoController::class, 'update']);
	Route::get('agendamentos/clinicas/pesquisa', [AgendamentoController::class, 'clinica_search']);
	Route::get('agendamento/historico/{id}', [AgendamentoController::class, 'historico']);
	Route::get('agendamentos/historico/{id}', [AgendamentoController::class, 'agendamentos_historico']);
	Route::get('agendamentos/beneficiarios/pesquisa', [AgendamentoController::class, 'beneficiario_search']);
    Route::get('/combos', [VendedorController::class, 'combos']);
    Route::get('/cpfcnpj', [ClienteController::class, 'cpfcnpj']);
	Route::get('/beneficiario', [ClienteController::class, 'beneficiario']);
	
	Route::group(array('prefix' => 'permissoes'), function () { 
	   Route::get('/perfil', [PermissaoController::class, 'index_perfil']);
	   Route::get('/menu', [PermissaoController::class, 'treeview']);
	   Route::post('/menu', [PermissaoController::class, 'menu_store']);
	   Route::get('/permissao', [PermissaoController::class, 'permissao_index']);
	   Route::post('/permissao', [PermissaoController::class, 'permissao_store']);
	});
	
	Route::group(array('prefix' => 'menus'), function () { 
		Route::get('/pais/{id}', [MenuController::class, 'index_parent']);
		Route::get('/', [MenuController::class, 'treeview']);
		Route::get('/{id}', [MenuController::class, 'show']);
		Route::post('/', [MenuController::class, 'store']);
		Route::post('{id}', [MenuController::class, 'update']);
		Route::delete('{id}', [MenuController::class, 'destroy']);
	});
	
	// Rotas de Conciliação
	Route::get('/conciliacao', [ConciliacaoController::class, 'index']);
	Route::post('/conciliacao', [ConciliacaoController::class, 'index']);
	Route::get('/conciliacao/exportar', [ConciliacaoController::class, 'exportar']);
	Route::get('/conciliacao/filtros', [ConciliacaoController::class, 'filtros']);
	Route::get('/conciliacao/resumo', [ConciliacaoController::class, 'resumo']);
	Route::post('/conciliacao/filtro', [ConciliacaoController::class, 'index']);

	// Rotas internas de cobrança
	Route::prefix('internal')->group(function () {
		Route::post('/billing/send-collection/{id}', [ParcelaController::class, 'sendCollection']);
	});

	// ============================================================
	// MENU CONEXA — Rotinas de Sincronização em Lote
	// ============================================================
	Route::prefix('conexa')->group(function () {
		// Ativar todos os beneficiários adimplentes na Conexa
		Route::post('/ativar-adimplentes', [ConexaSaudeController::class, 'ativarAdimplentes']);
		// Desativar beneficiários inadimplentes na Conexa
		Route::post('/desativar-inadimplentes', [ConexaSaudeController::class, 'desativarInadimplentes']);
		// Buscar titulares adimplentes para seleção na interface (?q=nome_ou_cpf)
		Route::get('/titulares-adimplentes', [ConexaSaudeController::class, 'titularesAdimplentes']);
		// Ativar titulares específicos { "beneficiario_ids": [1,2,3] }
		Route::post('/ativar-titulares-especificos', [ConexaSaudeController::class, 'ativarTitularesEspecificos']);
	});
   
});

Route::group(array('prefix' => '/login'), function () {   
	Route::post('refreshToken', [LoginController::class, 'refreshToken']);
	Route::post('/entrar', [LoginController::class, 'login']);
});

