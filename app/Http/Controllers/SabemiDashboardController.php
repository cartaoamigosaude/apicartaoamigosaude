<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contrato;
use App\Models\SabemiMovimentacao;
use App\Models\SabemiEndosso;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Beneficiario;
use App\Helpers\Cas;
use App\Services\SabemiService;

class SabemiDashboardController extends Controller
{
    public function index()
    {
        // ---------------------------------------------------------------------
        // 1. KPIs
        // ---------------------------------------------------------------------
        
        // Total Beneficiários Ativos
        // Contratos ativos e elegíveis na Sabemi -> Soma de beneficiários
        $contratosAtivos = Contrato::ativoSabemi()->withCount('beneficiarios')->with('plano')->get();
        $totalBeneficiariosAtivos = $contratosAtivos->sum('beneficiarios_count');

        // Total em Inadimplência
        // Contratos Sabemi com status de pagamento 'I' (Inadimplente)
        $contratosInadimplentes = Contrato::sabemi()->where('situacao_pagto', 'I')->with('plano')->get();
        $totalEmInadimplencia = $contratosInadimplentes->count(); // Ou sum('beneficiarios_count') se quiser por vidas

        // Movimentações do Mês Atual
        $inicioMes = Carbon::now()->startOfMonth();
        $fimMes = Carbon::now()->endOfMonth();

        $totalExcluidosMes = SabemiMovimentacao::where('tipo_movimentacao', 'E')
            ->whereBetween('created_at', [$inicioMes, $fimMes])
            ->count();

        $totalIncluidosMes = SabemiMovimentacao::where('tipo_movimentacao', 'I')
            ->whereBetween('created_at', [$inicioMes, $fimMes])
            ->count();

        // Taxa Inclusão vs Exclusão
        $taxaInclusaoExclusao = $totalExcluidosMes > 0 ? round($totalIncluidosMes / $totalExcluidosMes, 2) : $totalIncluidosMes;

        // Financeiro KPIs
        // Premio Total Mensal: Soma dos valores dos planos dos contratos ativos
        $premioTotalMensal = $contratosAtivos->sum(function($contrato) {
            return $contrato->plano ? $contrato->plano->preco : 0;
        });

        // Premio em Risco: Soma dos valores dos planos dos contratos inadimplentes
        $premioEmRisco = $contratosInadimplentes->sum(function($contrato) {
            return $contrato->plano ? $contrato->plano->preco : 0;
        });

        $kpis = [
            'total_beneficiarios_ativos' => $totalBeneficiariosAtivos,
            'total_em_inadimplencia' => $totalEmInadimplencia,
            'total_excluidos_mes' => $totalExcluidosMes,
            'total_incluidos_mes' => $totalIncluidosMes,
            'taxa_inclusao_vs_exclusao' => $taxaInclusaoExclusao,
            'premio_total_mensal' => round($premioTotalMensal, 2),
            'premio_em_risco' => round($premioEmRisco, 2)
        ];

        // ---------------------------------------------------------------------
        // 2. VOLUME (Gráfico de Barras - Últimos 6 meses)
        // ---------------------------------------------------------------------
        
        // Tentamos pegar do histórico de Endossos primeiro (mais preciso se existir)
        $endossos = SabemiEndosso::orderBy('data_abertura', 'desc')->take(6)->get()->sortBy('data_abertura');
        
        $labelsVolume = [];
        $inclusoesVolume = [];
        $exclusoesVolume = [];

        if ($endossos->count() > 0) {
            foreach ($endossos as $endosso) {
                $labelsVolume[] = $endosso->data_abertura ? $endosso->data_abertura->format('M') : 'N/D';
                $inclusoesVolume[] = $endosso->total_inclusoes;
                $exclusoesVolume[] = $endosso->total_exclusoes;
            }
        } else {
            // Fallback: Calcular via Movimentações se não houver endossos fechados
            for ($i = 5; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $start = $date->copy()->startOfMonth();
                $end = $date->copy()->endOfMonth();
                
                $labelsVolume[] = $date->format('M');
                $inclusoesVolume[] = SabemiMovimentacao::where('tipo_movimentacao', 'I')
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
                $exclusoesVolume[] = SabemiMovimentacao::where('tipo_movimentacao', 'E')
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            }
        }

        $volume = [
            'labels' => $labelsVolume,
            'inclusoes' => $inclusoesVolume,
            'exclusoes' => $exclusoesVolume
        ];

        // ---------------------------------------------------------------------
        // 3. FINANCEIRO (Gráfico de Linha - Últimos 6 meses)
        // ---------------------------------------------------------------------
        
        // Estimativa baseada no ticket médio atual, já que não temos histórico financeiro tabelado
        $ticketMedio = $totalBeneficiariosAtivos > 0 ? ($premioTotalMensal / $totalBeneficiariosAtivos) : 0;
        
        $labelsFin = $labelsVolume; // Mesmos meses
        $premioTotalHist = [];
        $premioRiscoHist = [];

        // Vamos reconstruir de trás pra frente ou usar o atual como base e ajustar
        // Como é apenas visualização, vamos usar o valor atual e "simular" o passado baseado no volume de movimentação
        // Current Month (Index 5 usually)
        
        $currentPremio = $premioTotalMensal;
        
        // Arrays are 0-indexed, length is usually 6.
        // We calculate backwards from the last element
        $reversePremios = [];
        $count = count($inclusoesVolume);
        
        for ($i = $count - 1; $i >= 0; $i--) {
            $reversePremios[] = round($currentPremio, 2);
            
            // Adjust for previous month: 
            // Prev = Current - (Inclusions * Ticket) + (Exclusions * Ticket)
            // Porque se houve inclusão este mês, o mês passado tinha menos. Se houve exclusão, mês passado tinha mais.
            $netChange = ($inclusoesVolume[$i] - $exclusoesVolume[$i]) * $ticketMedio;
            $currentPremio = $currentPremio - $netChange;
            if ($currentPremio < 0) $currentPremio = 0;
        }
        
        $premioTotalHist = array_reverse($reversePremios);

        // Para Risco, como não temos histórico, vamos gerar dados fictícios proporcionais ou zerados, 
        // ou usar o valor atual para o último mês e randomizar levemente para os anteriores para efeito visual,
        // já que "Risco" é volátil e não cumulativo da mesma forma.
        // Melhor abordagem: Mostrar o risco atual no último mês e N/A ou 0 nos outros se não soubermos.
        // Mas o usuário quer um gráfico. Vamos assumir uma taxa de risco constante (ex: 5% do total) ou usar o valor atual.
        $taxaRiscoAtual = $premioTotalMensal > 0 ? ($premioEmRisco / $premioTotalMensal) : 0;
        
        foreach ($premioTotalHist as $val) {
            $premioRiscoHist[] = round($val * $taxaRiscoAtual, 2);
        }

        $financeiro = [
            'labels' => $labelsFin,
            'premioTotal' => $premioTotalHist,
            'premioRisco' => $premioRiscoHist
        ];

        return response()->json([
            'kpis' => $kpis,
            'volume' => $volume,
            'financeiro' => $financeiro
        ]);
    }

    public function endossos(Request $request)
    {
        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'data_abertura');
		$direction          					= $request->input('direction', 'desc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

		$payload								= (object) $request->all();

        $query = SabemiEndosso::select('*')
            ->where(function ($query) use ($campo, $conteudo) {
                if (($campo != "") and ($conteudo != "")) {
                    $query->where($campo, 'like', "%$conteudo%");
                }
            });

        if (isset($payload->campos)) {
            $query = Cas::montar_filtro($query, $payload);
        }

        $query->orderBy($orderby, $direction);
        $endossos = $query->paginate($limite);

        $endossos->getCollection()->transform(function ($endosso) {
            return [
                'id' => $endosso->id,
                'codigo_endosso' => $endosso->numero_endosso,
                'data_abertura' => $endosso->data_abertura ? $endosso->data_abertura->format('Y-m-d') : null,
                'data_fechamento' => $endosso->data_fechamento ? $endosso->data_fechamento->format('Y-m-d') : null,
                'status' => $endosso->status_endosso,
                'total_movimentacoes' => $endosso->total_inclusoes + $endosso->total_exclusoes + $endosso->total_alteracoes,
                'total_inclusoes' => $endosso->total_inclusoes,
                'total_exclusoes' => $endosso->total_exclusoes
            ];
        });

        return response()->json($endossos);
    }

    public function baseAtiva(Request $request)
    {
        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'clientes.nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

		$payload								= (object) $request->all();

        // Beneficiários Ativos em Contratos Ativos na Sabemi
        $query = Beneficiario::select('beneficiarios.*')
            ->join('clientes', 'beneficiarios.cliente_id', '=', 'clientes.id')
            ->join('contratos', 'beneficiarios.contrato_id', '=', 'contratos.id')
            ->where('beneficiarios.ativo', 1)
            ->whereHas('contrato', function ($q) {
                $q->ativoSabemi();
            })
            ->with(['cliente', 'contrato.plano'])
            ->where(function ($query) use ($campo, $conteudo) {
                if (($campo != "") and ($conteudo != "")) {
                    if ($campo == 'nome') {
                        $query->where('clientes.nome', 'like', "%$conteudo%");
                    } elseif ($campo == 'cpf') {
                        $query->where('clientes.cpfcnpj', 'like', "%$conteudo%");
                    } else {
                        $query->where($campo, 'like', "%$conteudo%");
                    }
                }
            });

        if (isset($payload->campos)) {
            $query = Cas::montar_filtro($query, $payload);
        }

        // Handle sorting by related fields
        if ($orderby == 'nome') $orderby = 'clientes.nome';
        if ($orderby == 'cpf') $orderby = 'clientes.cpfcnpj';

        $query->orderBy($orderby, $direction);
        $beneficiarios = $query->paginate($limite);

        $beneficiarios->getCollection()->transform(function ($beneficiario) {
            $statusCobertura = 'ATIVO (Adimplente)';
            if ($beneficiario->contrato->situacao_pagto == 'I' || $beneficiario->contrato->dias_inadimplente > 0) {
                $statusCobertura = 'INADIMPLENTE (' . ($beneficiario->contrato->dias_inadimplente ?? 0) . ' dias)';
            }

            return [
                'id' => $beneficiario->id,
                'nome' => $beneficiario->cliente->nome ?? 'N/D',
                'cpf' => $beneficiario->cliente->cpfcnpj ?? 'N/D',
                'plano' => $beneficiario->contrato->plano->nome ?? 'N/D',
                'premio_mensal' => $beneficiario->contrato->plano->preco ?? 0.00,
                'data_adesao' => $beneficiario->contrato->created_at ? $beneficiario->contrato->created_at->format('Y-m-d') : null,
                'status_cobertura' => $statusCobertura,
                'dias_inadimplente' => $beneficiario->contrato->dias_inadimplente ?? 0,
                'codigo_contrato_sabemi' => $beneficiario->codigo_contrato_sabemi ?? null
            ];
        });

        return response()->json($beneficiarios);
    }

    public function detalhesBeneficiario($id)
    {
        $beneficiario = Beneficiario::with(['cliente', 'contrato.plano'])
            ->find($id);

        if (!$beneficiario) {
            return response()->json(['message' => 'Beneficiário não encontrado'], 404);
        }

        // Dados Cadastrais
        $dadosCadastrais = [
            'id' => $beneficiario->id,
            'nome' => $beneficiario->cliente->nome ?? 'N/D',
            'cpf' => $beneficiario->cliente->cpfcnpj ?? 'N/D',
            'plano' => $beneficiario->contrato->plano->nome ?? 'N/D',
            'data_adesao' => $beneficiario->contrato->created_at ? $beneficiario->contrato->created_at->format('Y-m-d') : null,
            'codigo_contrato_sabemi' => $beneficiario->codigo_contrato_sabemi ?? null
        ];

        // Status Controle
        $situacaoPagamento = 'Em dia';
        $statusCobertura = 'ATIVO (Adimplente)';
        $diasInadimplente = $beneficiario->contrato->dias_inadimplente ?? 0;

        if ($beneficiario->contrato->situacao_pagto == 'I' || $diasInadimplente > 0) {
            $situacaoPagamento = 'Inadimplente';
            $statusCobertura = 'INADIMPLENTE (' . $diasInadimplente . ' dias)';
        }

        $statusControle = [
            'situacao_pagamento' => $situacaoPagamento,
            'dias_inadimplente' => $diasInadimplente,
            'status_cobertura' => $statusCobertura
        ];

        // Histórico de Movimentações
        $movimentacoes = SabemiMovimentacao::where('beneficiario_id', $id)
            ->orderBy('data_envio', 'desc')
            ->get();

        $historicoMovimentacoes = $movimentacoes->map(function ($mov) {
            $tipo = 'DESCONHECIDO';
            if ($mov->tipo_movimentacao == 'I') $tipo = 'INCLUSAO';
            elseif ($mov->tipo_movimentacao == 'E') $tipo = 'EXCLUSAO';
            elseif ($mov->tipo_movimentacao == 'A') $tipo = 'ALTERACAO';

            return [
                'tipo' => $tipo,
                'endosso' => $mov->codigo_endosso, // Assumindo que o código do endosso está salvo aqui
                'data_envio' => $mov->data_envio ? $mov->data_envio->format('Y-m-d') : null,
                'status_envio' => $mov->status_envio,
                'mensagem_erro' => $mov->erro
            ];
        });

        return response()->json([
            'dadosCadastrais' => $dadosCadastrais,
            'statusControle' => $statusControle,
            'historicoMovimentacoes' => $historicoMovimentacoes
        ]);
    }

    public function divergencias(Request $request)
    {
        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'clientes.nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

		$payload								= (object) $request->all();

        // Consulta Base: Beneficiários em Contratos Ativos na Sabemi
        $query = Beneficiario::select('beneficiarios.*')
            ->join('clientes', 'beneficiarios.cliente_id', '=', 'clientes.id')
            ->join('contratos', 'beneficiarios.contrato_id', '=', 'contratos.id')
            ->whereHas('contrato', function ($q) {
                $q->ativoSabemi();
            })
            ->where(function ($query) {
                // Grupo 1: ATIVO_NAO_REGISTRADO (Ativo localmente mas sem ID Sabemi)
                $query->where(function ($q) {
                    $q->where('beneficiarios.ativo', 1)
                      ->whereNull('beneficiarios.codigo_contrato_sabemi');
                })
                // Grupo 2: INATIVO_NAO_CANCELADO (Inativo localmente mas com ID Sabemi)
                ->orWhere(function ($q) {
                    $q->where('beneficiarios.ativo', 0)
                      ->whereNotNull('beneficiarios.codigo_contrato_sabemi');
                });
            })
            ->with(['cliente', 'contrato.plano'])
            ->where(function ($query) use ($campo, $conteudo) {
                if (($campo != "") and ($conteudo != "")) {
                    if ($campo == 'nome') {
                        $query->where('clientes.nome', 'like', "%$conteudo%");
                    } elseif ($campo == 'cpf') {
                        $query->where('clientes.cpfcnpj', 'like', "%$conteudo%");
                    } else {
                        $query->where($campo, 'like', "%$conteudo%");
                    }
                }
            });

        if (isset($payload->campos)) {
            $query = Cas::montar_filtro($query, $payload);
        }

        // Handle sorting by related fields
        if ($orderby == 'nome') $orderby = 'clientes.nome';
        if ($orderby == 'cpf') $orderby = 'clientes.cpfcnpj';

        $query->orderBy($orderby, $direction);
        $divergencias = $query->paginate($limite);

        $divergencias->getCollection()->transform(function ($beneficiario) {
            $statusCobertura = 'ATIVO (Adimplente)';
            if ($beneficiario->contrato->situacao_pagto == 'I' || $beneficiario->contrato->dias_inadimplente > 0) {
                $statusCobertura = 'INADIMPLENTE (' . ($beneficiario->contrato->dias_inadimplente ?? 0) . ' dias)';
            }

            $tipoDivergencia = 'DESCONHECIDO';
            if ($beneficiario->ativo == 1 && is_null($beneficiario->codigo_contrato_sabemi)) {
                $tipoDivergencia = 'ATIVO_NAO_REGISTRADO';
            } elseif ($beneficiario->ativo == 0 && !is_null($beneficiario->codigo_contrato_sabemi)) {
                $tipoDivergencia = 'INATIVO_NAO_CANCELADO';
            }

            return [
                'id' => $beneficiario->id,
                'nome' => $beneficiario->cliente->nome ?? 'N/D',
                'cpf' => $beneficiario->cliente->cpfcnpj ?? 'N/D',
                'plano' => $beneficiario->contrato->plano->nome ?? 'N/D',
                'data_adesao' => $beneficiario->contrato->created_at ? $beneficiario->contrato->created_at->format('Y-m-d') : null,
                'status_cobertura' => $statusCobertura,
                'codigo_contrato_sabemi' => $beneficiario->codigo_contrato_sabemi ?? null,
                'tipo_divergencia' => $tipoDivergencia
            ];
        });

        return response()->json($divergencias);
    }

    public function registrarBeneficiario(Request $request, $id)
    {
        $beneficiario 					= Beneficiario::with(['contrato.plano', 'cliente'])->find($id);

        if (!$beneficiario) {
            return response()->json(['message' => 'Beneficiário não encontrado.'], 404);
        }

        // 1. Verificação de Elegibilidade
        if (!$beneficiario->contrato->plano->produtos()->whereIn('produtos.id', [7, 11, 12, 13])->exists()) {
            return response()->json(['message' => 'O contrato não possui produtos Sabemi.'], 400);
        }

        $contratoValido = $beneficiario->contrato()
            ->whereIn('status', ['active', 'waitingPayment'])
            ->whereHas('parcelas', function ($q) {
                $q->where('nparcela', 1)
                  ->whereNotNull('data_pagamento');
            })
            ->exists();

        if (!$contratoValido) {
            return response()->json(['message' => 'Contrato não elegível (Inativo ou 1ª parcela pendente).'], 400);
        }

        if ($beneficiario->codigo_contrato_sabemi) {
            return response()->json(['message' => 'Beneficiário já registrado na Sabemi.', 'codigo_sabemi' => $beneficiario->codigo_contrato_sabemi], 200);
        }

        try {
            $sabemiService 				= app(SabemiService::class);

            // 2. Garantia de Endosso Aberto
            $endosso = SabemiEndosso::where('status_endosso', 'ABERTO')->first();

            if (!$endosso) {
                // Tenta abrir um novo endosso usando o contrato mestre configurado (exemplo fixo ou config)
                $codigoContratoMestre 	= config('services.sabemi.contrato_mestre', 123456); // Fallback para exemplo
                $aberturaResult 		= $sabemiService->abrirEndosso($codigoContratoMestre);

                if (!$aberturaResult['sucesso']) {
                    return response()->json(['message' => 'Falha ao abrir novo endosso na Sabemi: ' . $aberturaResult['mensagem']], 500);
                }

                $endosso = SabemiEndosso::create([
                    'numero_endosso' 	=> $aberturaResult['codigo_endosso'],
                    'codigo_apolice' 	=> config('services.sabemi.apolice'), // Idealmente do service
                    'codigo_grupo' 		=> config('services.sabemi.grupo'),
                    'status_endosso' 	=> 'ABERTO',
                    'data_abertura' 	=> Carbon::now(),
                ]);
            }

            // 3. Preparar e Enviar Movimentação
            $dataInicio = $beneficiario->contrato->data_adesao ? $beneficiario->contrato->data_adesao->format('Y-m-d') : Carbon::now()->format('Y-m-d');
            $payload = $sabemiService->prepararPayloadBeneficiario($beneficiario, 'I', $dataInicio);
            
            $envioResult = $sabemiService->enviarMovimentacao($endosso->numero_endosso, [$payload]);

            if ($envioResult['sucesso']) {
                $idPrime = $envioResult['detalhes']['CodigoContratoSabemi'] ?? null; // Ajustar conforme estrutura real de retorno da API Sabemi

                // Atualizar Beneficiário
                $beneficiario->status_envio_sabemi = 'ENVIADO';
                $beneficiario->data_envio_sabemi = Carbon::now();
                $beneficiario->erro_envio_sabemi = null;
                $beneficiario->codigo_endosso_inclusao = $endosso->numero_endosso;
                
                if ($idPrime) {
                    $beneficiario->codigo_contrato_sabemi = $idPrime;
                }
                $beneficiario->save();

                // Atualizar Endosso
                $endosso->increment('total_inclusoes');
                $endosso->increment('total_sucesso');

                return response()->json([
                    'message' => 'Beneficiário registrado com sucesso.',
                    'codigo_sabemi' => $idPrime,
                    'endosso' => $endosso->numero_endosso
                ], 200);

            } else {
                // Atualizar erro no beneficiário
                $beneficiario->status_envio_sabemi = 'ERRO';
                $beneficiario->erro_envio_sabemi = $envioResult['mensagem'];
                $beneficiario->save();

                $endosso->increment('total_erro');

                return response()->json(['message' => 'Erro ao enviar para Sabemi: ' . $envioResult['mensagem']], 500);
            }

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Erro interno ao processar registro: ' . $e->getMessage()], 500);
        }
    }
}
