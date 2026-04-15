<?php

namespace App\Providers;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(now()->addDays(2));
        Passport::refreshTokensExpireIn(now()->addDays(2));
        Passport::personalAccessTokensExpireIn(now()->addMonths(1));

        Passport::tokensCan([
            'view.beneficiarios' 	=> 'Visualizar beneficiários',
            'edit.beneficiarios' 	=> 'Criar/Editar beneficiários',
            'delete.beneficiarios' 	=> 'Excluir beneficiários',
            'view.clientes' 		=> 'Visualizar clientes',
            'edit.clientes' 		=> 'Criar/Editar clientes',
            'delete.clientes' 		=> 'Excluir clientes',
            'view.contratos' 		=> 'Visualizar contratos',
            'edit.contratos' 		=> 'Criar/Editar contratos',
            'delete.contratos' 		=> 'Excluir contratos',
            'view.parcelas' 		=> 'Visualizar parcelas',
            'edit.parcelas' 		=> 'Criar/Editar parcelas',
            'delete.parcelas' 		=> 'Excluir parcelas',
            'view.periodicidades' 	=> 'Visualizar periodicidades',
            'edit.periodicidades' 	=> 'Criar/Editar periodicidades',
            'delete.periodicidades' => 'Excluir periodicidades',
            'view.planos' 			=> 'Visualizar planos',
            'edit.planos' 			=> 'Criar/Editar planos',
            'delete.planos' 		=> 'Excluir planos',
            'view.situacoes' 		=> 'Visualizar situações',
            'edit.situacoes' 		=> 'Criar/Editar situações',
            'delete.situacoes' 		=> 'Excluir situações',
			'view.asituacoes' 		=> 'Visualizar agendamento situações',
            'edit.asituacoes' 		=> 'Criar/Editar agendamento situações',
            'delete.asituacoes' 	=> 'Excluir agendamento situações',
            'view.vendedores' 		=> 'Visualizar vendedores',
            'edit.vendedores' 		=> 'Criar/Editar vendedores',
            'delete.vendedores' 	=> 'Excluir vendedores',
            'view.produtos' 		=> 'Visualizar produtos',
            'edit.produtos' 		=> 'Criar/Editar produtos',
            'delete.produtos' 	    => 'Excluir produtos',
            'view.especialidades' 	=> 'Visualizar especialidades',
            'edit.especialidades' 	=> 'Criar/Editar especialidades',
            'delete.especialidades' => 'Excluir especialidades',
            'view.clinicas' 	    => 'Visualizar clinicas',
            'edit.clinicas' 	    => 'Criar/Editar clinicas',
            'delete.clinicas'       => 'Excluir clinicas',
			'view.medicos' 	    	=> 'Visualizar Medicos',
            'edit.medicos' 	    	=> 'Criar/Editar Medicos',
            'delete.medicos'        => 'Excluir Medicos',
            'view.agendamentos' 	=> 'Visualizar agendamentos',
            'edit.agendamentos' 	=> 'Criar/Editar agendamentos',
            'delete.agendamentos'   => 'Excluir agendamentos',
			'view.usuarios' 		=> 'Visualizar usuarios',
            'edit.usuarios' 		=> 'Criar/Editar usuarios',
            'delete.usuarios'   	=> 'Excluir usuarios',
			'view.menus' 			=> 'Visualizar menus',
            'edit.menus' 			=> 'Criar/Editar menus',
            'delete.menus'   		=> 'Excluir menus',
			'view.ativardesativar' 	=> 'Visualizar ativadesativa',
            'edit.ativardesativar' 	=> 'Criar/Editar ativadesativa', 
            'delete.ativardesativar'  => 'Excluir ativadesativa',
			'view.permissoes' 		=> 'Visualizar permissoes',
            'edit.permissoes' 		=> 'Criar/Editar permissoes',
            'delete.permissoes'   	=> 'Excluir permissoes',
			'view.motivos' 			=> 'Visualizar motivos',
            'edit.motivos' 			=> 'Criar/Editar motivos', 
            'delete.motivos'  		=> 'Excluir motivos',
			'view.mensagens' 		=> 'Visualizarmensagens',
            'edit.mensagens' 		=> 'Criar/Editar mensagens', 
            'delete.mensagens'  	=> 'Excluir mensagens',
			'view.imagens' 			=> 'Visualizar imagens',
            'edit.imagens' 			=> 'Criar/Editar imagens', 
            'delete.imagens'  		=> 'Excluir imagens',
			'view.tokens' 			=> 'Visualizar tokens',
            'edit.tokens' 			=> 'Criar/Editar tokens', 
            'delete.tokens'  		=> 'Excluir tokens',
            'view.conciliacaop' 	=> 'Visualizar conciliacao',
            'edit.conciliacaop' 	=> 'Criar/Editar conciliacao',
            'delete.conciliacaop' 	=> 'Excluir conciliacao',
			'view.transacoes' 		=> 'Visualizar transacoes',
            'edit.transacoes' 		=> 'Criar/Editar transacoes',
            'delete.transacoes' 	=> 'Excluir transacoes',
			'view.apuracoes' 		=> 'Visualizar apurações',
            'edit.apuracoes' 		=> 'Criar/Editar apurações',
            'delete.apuracoes' 		=> 'Excluir apuracoes',
			'view.consultas' 		=> 'Visualizar consultas',
            'edit.consultas' 		=> 'Criar/Editar consultas',
            'delete.consultas' 		=> 'Excluir consultas',
			'view.atendimentos' 	=> 'Visualizar atendimentos',
            'edit.atendimentos' 	=> 'Criar/Editar atedimentos',
            'delete.atendimentos' 	=> 'Excluir atendimentos',
			'view.sdashboard' 		=> 'Visualizar sabemi',
            'edit.sdashboard' 		=> 'Criar/Editar sabemi',
            'delete.sdashboard' 	=> 'Excluir sabemi',
			'view.sdivergencias' 	=> 'Visualizar sabemi',
            'edit.sdivergencias' 	=> 'Criar/Editar sabemi',
            'delete.sdivergencias' 	=> 'Excluir sabemi',
			'view.sendossos' 		=> 'Visualizar sabemi',
            'edit.sendossos' 		=> 'Criar/Editar sabemi',
            'delete.sendossos' 		=> 'Excluir sabemi',
			'view.sbase-ativa' 		=> 'Visualizar sabemi',
            'edit.sbase-ativa' 		=> 'Criar/Editar sabemi',
            'delete.sbase-ativa' 	=> 'Excluir sabemi',
			'view.conexa' 			=> 'Visualizar conexa',
            'edit.conexa' 			=> 'Criar/Editar conexa',
            'delete.conexa' 		=> 'Excluir conexa',
        ]);

         Gate::define('viewPulse', function ($user) {
            return in_array($user->email, [
                'admin@cartaoamigosaude.com.br', 'allan.diogo@americafinanceira.com.br'
            ]);
        });

        LogViewer::auth(function ($request) {
            return $request->user()
                && in_array($request->user()->email, [
                    'admin@cartaoamigosaude.com.br', 'allan.diogo@americafinanceira.com.br',
                ]);
        });
    }
}
