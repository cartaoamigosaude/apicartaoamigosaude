<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function clientes(Request $request)
    {
       try {
            // Versão otimizada com uma única query
            $resultado = DB::table('clientes')
                ->selectRaw('
                    SUM(CASE WHEN tipo = "F" THEN 1 ELSE 0 END) as pessoasFisicas,
                    SUM(CASE WHEN tipo != "F" THEN 1 ELSE 0 END) as pessoasJuridicas,
                    COUNT(*) as total
                ')
                ->where('ativo', 1)
                ->first();

            return response()->json([
                'pessoasFisicas' => (int) $resultado->pessoasFisicas,
                'pessoasJuridicas' => (int) $resultado->pessoasJuridicas,
                'total' => (int) $resultado->total
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados dos clientes',
                'message' => $e->getMessage()
            ], 500);
        }
		
    }
	
	public function beneficiarios(Request $request)
    {
       try {
            // Busca contadores baseado no campo tipo
            $resumo = DB::table('beneficiarios')
                ->selectRaw('
                    SUM(CASE WHEN tipo = "T" THEN 1 ELSE 0 END) as titulares,
                    SUM(CASE WHEN tipo = "D" THEN 1 ELSE 0 END) as dependentes,
                    COUNT(*) as total
                ')
                ->where('ativo', 1)
                ->first();

            // Busca estatísticas de parentesco apenas dos dependentes (tipo = 'D')
            $parentescosStats = DB::table('beneficiarios as b')
                ->join('parentescos as p', 'b.parentesco_id', '=', 'p.id')
                ->select(
                    'p.nome as parentesco',
                    DB::raw('COUNT(*) as quantidade')
                )
                ->where('b.ativo', 1)
                ->where('b.tipo', 'D')
                ->groupBy('p.id', 'p.nome')
                ->orderBy('quantidade', 'desc')
                ->get();

            // Calcula percentuais baseado no total de dependentes
            $percentualParentescos = [];
            $totalDependentes = (int) $resumo->dependentes;
            
            foreach ($parentescosStats as $stat) {
                $percentual = $totalDependentes > 0 ? round(($stat->quantidade / $totalDependentes) * 100, 1) : 0;
                
                $percentualParentescos[] = [
                    'parentesco' => $stat->parentesco,
                    'quantidade' => (int) $stat->quantidade,
                    'percentual' => $percentual
                ];
            }

            return response()->json([
                'titulares' => (int) $resumo->titulares,
                'dependentes' => (int) $resumo->dependentes,
                'total' => (int) $resumo->total,
                'percentualParentescos' => $percentualParentescos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados dos beneficiários',
                'message' => $e->getMessage()
            ], 500);
        }
		
    }
	
	public function contratos(Request $request)
    {
		try {
            // Conta contratos ativos (active ou waitingPayment)
            $totalAtivos = DB::table('contratos')
                ->whereIn('status', ['active', 'waitingPayment'])
                ->count();

            // Conta contratos inadimplentes
			/* ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('parcelas as p')
                        ->whereColumn('p.contrato_id', 'c.id')
                        ->where('p.data_vencimento', '<', DB::raw('DATE_SUB(CURDATE(), INTERVAL 9 DAY)')) // vencida há mais de 9 dias
                        ->whereNull('p.data_pagamento') // não paga
                        ->whereNull('p.data_baixa'); // não baixada
                }) */
            // Condição: possuir parcelas vencidas há mais de 9 dias
            $inadimplentes = DB::table('contratos as c')
                ->whereIn('c.status', ['active', 'waitingPayment'])
                ->where('c.situacao_pagto','=','I')
                ->count();

            // Adimplentes = Total Ativos - Inadimplentes
            $adimplentes = $totalAtivos - $inadimplentes;
			
			 // Calcula percentuais
            $percentualAdimplentes = $totalAtivos > 0 ? round(($adimplentes / $totalAtivos) * 100, 1) : 0;
            $percentualInadimplentes = $totalAtivos > 0 ? round(($inadimplentes / $totalAtivos) * 100, 1) : 0;
			
			
            // Busca estatísticas das formas de pagamento dos contratos ativos
            $formasPagamentoStats = DB::table('contratos')
                ->select('mainPaymentMethodId', DB::raw('COUNT(*) as quantidade'))
                ->whereIn('status', ['active', 'waitingPayment'])
                ->whereNotNull('mainPaymentMethodId')
                ->groupBy('mainPaymentMethodId')
                ->orderBy('quantidade', 'desc')
                ->get();

            // Mapeamento das formas de pagamento
            $mapeamentoFormas = [
                'boleto' => 'Boleto',
                'creditcard' => 'Cartão de Crédito',
                'debit' => 'Débito Automático',
                'pix' => 'PIX'
            ];

            // Calcula percentuais e mapeia os nomes
            $formasPagamento = [];
            
            foreach ($formasPagamentoStats as $stat) {
                $percentual = $totalAtivos > 0 ? round(($stat->quantidade / $totalAtivos) * 100, 1) : 0;
                
                // Mapeia o nome da forma de pagamento
                $nomeForma = $mapeamentoFormas[$stat->mainPaymentMethodId] ?? 
                            ucfirst($stat->mainPaymentMethodId);
                
                $formasPagamento[] = [
                    'forma' => $nomeForma,
                    'quantidade' => (int) $stat->quantidade,
                    'percentual' => $percentual
                ];
            }

            return response()->json([
                'totalAtivos' => $totalAtivos,
                'adimplentes' => $adimplentes,
				'percentualAdimplentes' => $percentualAdimplentes,
                'inadimplentes' => $inadimplentes,
                'percentualInadimplentes' => $percentualInadimplentes,
                'formasPagamento' => $formasPagamento
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados dos contratos',
                'message' => $e->getMessage()
            ], 500);
		}	
        
	}
	
	public function clinicas(Request $request)
    {
       try {
            // Versão otimizada com uma única query
            $resultado = DB::table('clinicas')
                ->selectRaw('
                    SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) as ativas,
                    SUM(CASE WHEN ativo != 1 THEN 1 ELSE 0 END) as inativas,
                    COUNT(*) as total
                ')
                ->first();

            return response()->json([
                'ativas' => (int) $resultado->ativas,
                'inativas' => (int) $resultado->inativas,
                'total' => (int) $resultado->total
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados das clinicas',
                'message' => $e->getMessage()
            ], 500);
        }
		
    }
	
	public function acompanhamento_mensal(Request $request)
    {
		try {
            // Arrays para armazenar os dados
            $meses = [];
            $novosContratos = [];
            $contratosCancelados = [];
            $contratosEncerrados = [];
            
            // Nomes dos meses em português
            $nomesMeses = [
                1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 
                5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 
                9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'
            ];
            
            // Loop pelos últimos 4 meses (3 anteriores + atual)
            for ($i = 3; $i >= 0; $i--) {
                $mesAtual = now()->subMonths($i);
                $inicioMes = $mesAtual->copy()->startOfMonth();
                $fimMes = $mesAtual->copy()->endOfMonth();
                
                // Adiciona o nome do mês ao array
                $meses[] = $nomesMeses[$mesAtual->month];
                
                // Novos contratos (created_at no mês + status active/waitingPayment)
                $novos = DB::table('contratos')
                    ->whereBetween('created_at', [$inicioMes, $fimMes])
                    ->whereIn('status', ['active', 'waitingPayment'])
                    ->count();
                
                // Contratos cancelados (updated_at no mês + status canceled/stopped)
                $cancelados = DB::table('contratos')
                    ->whereBetween('updated_at', [$inicioMes, $fimMes])
                    ->whereIn('status', ['canceled', 'stopped'])
                    ->count();
                
                // Contratos encerrados (updated_at no mês + status closed)
                $encerrados = DB::table('contratos')
                    ->whereBetween('updated_at', [$inicioMes, $fimMes])
                    ->where('status', 'closed')
                    ->count();
                
                $novosContratos[] = $novos;
                $contratosCancelados[] = $cancelados;
                $contratosEncerrados[] = $encerrados;
            }
            
            // Meta para 4 meses (você pode personalizar conforme necessário)
            $meta = [0, 0, 0, 0];
            
            return response()->json([
                'meses' => $meses,
                'novosContratos' => $novosContratos,
                'contratosCancelados' => $contratosCancelados,
                'contratosEncerrados' => $contratosEncerrados,
                'meta' => $meta
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar evolução dos contratos',
                'message' => $e->getMessage()
            ], 500);
        }
	}
	
	public function agendamentos_mensais(Request $request)
    {
		try {
            // Arrays para armazenar os dados
            $meses = [];
            $solicitados = [];
            $agendados = [];
            $confirmados = [];
            
            // Nomes dos meses em português
            $nomesMeses = [
                1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 
                5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 
                9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'
            ];
            
            // Loop pelos últimos 4 meses (3 anteriores + atual)
            for ($i = 3; $i >= 0; $i--) {
                $mesAtual = now()->subMonths($i);
                $inicioMes = $mesAtual->copy()->startOfMonth();
                $fimMes = $mesAtual->copy()->endOfMonth();
                
                // Adiciona o nome do mês ao array
                $meses[] = $nomesMeses[$mesAtual->month];
                
                // Solicitados (solicitado_data_hora no mês)
                $totalSolicitados = DB::table('clinica_beneficiario')
                    ->whereBetween('solicitado_data_hora', [$inicioMes, $fimMes])
                    ->whereNotNull('solicitado_data_hora')
                    ->count();
                
                // Agendados (agendamento_data_hora no mês)
                $totalAgendados = DB::table('clinica_beneficiario')
                    ->whereBetween('agendamento_data_hora', [$inicioMes, $fimMes])
                    ->whereNotNull('agendamento_data_hora')
                    ->count();
                
                // Confirmados (confirmado_data_hora no mês)
                $totalConfirmados = DB::table('clinica_beneficiario')
                    ->whereBetween('confirmado_data_hora', [$inicioMes, $fimMes])
                    ->whereNotNull('confirmado_data_hora')
                    ->count();
                
                $solicitados[] = $totalSolicitados;
                $agendados[] = $totalAgendados;
                $confirmados[] = $totalConfirmados;
            }
            
            // Meta para 4 meses (você pode personalizar conforme necessário)
            $meta = [0, 0, 0, 0];
            
            return response()->json([
                'meses' => $meses,
                'solicitados' => $solicitados,
                'agendados' => $agendados,
                'confirmados' => $confirmados,
                'meta' => $meta
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar evolução dos atendimentos clínicos',
                'message' => $e->getMessage()
            ], 500);
        }
	}
	
	public function pagamentos_mensais(Request $request)
    {
		try {
            // Arrays para armazenar os dados
            $meses = [];
            $boleto = [];
            $cartaoCredito = [];
            $maquininha = [];
            $dinheiro = [];
            
            // Nomes dos meses em português (minúsculas conforme exemplo)
            $nomesMeses = [
                1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 
                5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago', 
                9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'
            ];
            
            // Loop pelos últimos 4 meses (3 anteriores + atual)
            for ($i = 3; $i >= 0; $i--) {
                $mesAtual = now()->subMonths($i);
                $inicioMes = $mesAtual->copy()->startOfMonth();
                $fimMes = $mesAtual->copy()->endOfMonth();
                
                // Adiciona o nome do mês ao array
                $meses[] = $nomesMeses[$mesAtual->month];
                
                // Query otimizada com soma de todas as formas em uma única consulta
                $resultado = DB::table('parcelas')
                    ->selectRaw('
                        SUM(CASE WHEN formapagamento = "boleto" THEN valor_pago ELSE 0 END) as total_boleto,
                        SUM(CASE WHEN formapagamento = "creditcard" THEN valor_pago ELSE 0 END) as total_cartao,
                        SUM(CASE WHEN formapagamento = "maquininha" THEN valor_pago ELSE 0 END) as total_maquininha,
                        SUM(CASE WHEN formapagamento = "dinheiro" THEN valor_pago ELSE 0 END) as total_dinheiro
                    ')
                    ->whereBetween('data_pagamento', [$inicioMes, $fimMes])
                    ->whereNotNull('data_pagamento')
                    ->whereIn('formapagamento', ['boleto', 'creditcard', 'maquininha', 'dinheiro'])
                    ->first();
                
                $boleto[] = (float) ($resultado->total_boleto ?: 0);
                $cartaoCredito[] = (float) ($resultado->total_cartao ?: 0);
                $maquininha[] = (float) ($resultado->total_maquininha ?: 0);
                $dinheiro[] = (float) ($resultado->total_dinheiro ?: 0);
            }
            
            return response()->json([
                'meses' => $meses,
                'boleto' => $boleto,
                'cartaoCredito' => $cartaoCredito,
                'maquininha' => $maquininha,
                'dinheiro' => $dinheiro
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados de pagamentos mensais',
                'message' => $e->getMessage()
            ], 500);
        }
	}
	
	public function pagamentos_consultas_exames(Request $request)
    {
		try {
            // Arrays para armazenar os dados
            $meses = [];
            $boleto = [];
            $cartaoCredito = [];
            $maquininha = [];
            $dinheiro = [];
            
            // Nomes dos meses em português (minúsculas conforme exemplo)
            $nomesMeses = [
                1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 
                5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago', 
                9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'
            ];
            
            // Loop pelos últimos 4 meses (3 anteriores + atual)
            for ($i = 3; $i >= 0; $i--) {
                $mesAtual = now()->subMonths($i);
                $inicioMes = $mesAtual->copy()->startOfMonth();
                $fimMes = $mesAtual->copy()->endOfMonth();
                
                // Adiciona o nome do mês ao array
                $meses[] = $nomesMeses[$mesAtual->month];
                
                // Query otimizada com soma de todas as formas em uma única consulta
                $resultado = DB::table('clinica_beneficiario')
                    ->selectRaw('
                        SUM(CASE WHEN forma = "B" THEN valor ELSE 0 END) as total_boleto,
                        SUM(CASE WHEN forma = "C" THEN valor ELSE 0 END) as total_cartao,
                        SUM(CASE WHEN forma = "M" THEN valor ELSE 0 END) as total_maquininha,
                        SUM(CASE WHEN forma = "D" THEN valor ELSE 0 END) as total_dinheiro
                    ')
                    ->whereBetween('pagamento', [$inicioMes, $fimMes])
                    ->whereNotNull('pagamento')
                    ->whereIn('forma', ['B', 'C', 'M', 'D'])
                    ->first();
                
                $boleto[] = (float) ($resultado->total_boleto ?: 0);
                $cartaoCredito[] = (float) ($resultado->total_cartao ?: 0);
                $maquininha[] = (float) ($resultado->total_maquininha ?: 0);
                $dinheiro[] = (float) ($resultado->total_dinheiro ?: 0);
            }
            
            return response()->json([
                'meses' => $meses,
                'boleto' => $boleto,
                'cartaoCredito' => $cartaoCredito,
                'maquininha' => $maquininha,
                'dinheiro' => $dinheiro
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados de pagamentos consulta e exame',
                'message' => $e->getMessage()
            ], 500);
        }
	}
	
}
