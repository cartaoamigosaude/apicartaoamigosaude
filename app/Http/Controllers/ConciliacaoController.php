<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Helpers\Cas;
use stdClass;

class ConciliacaoController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.conciliacaop')) {
            return response()->json(['error' => 'Não autorizado para visualizar conciliação.'], 403);
        }

        $limite 		= $request->input('limite', 10);
        $orderby 		= $request->input('orderby', 'p.nome');
        $direction 		= $request->input('direction', 'asc');
        $campo 			= $request->input('campo', '');
        $conteudo 		= $request->input('conteudo', '');
        $situacao_pagto	= $request->input('situacao_pagto','');
		
		if (($direction != 'asc') and ($direction != 'desc'))
		{
			$direction			= 'asc';
		}
		
		if ($orderby == '20')
		{
			 $orderby 		= 'p.nome';
		}
		
        $payload = (object) $request->all();

        // Query base de conciliação
        $query = DB::table('produtos as p')
            ->join('plano_produto as pp', 'p.id', '=', 'pp.produto_id')
            ->join('planos as pl', 'pp.plano_id', '=', 'pl.id')
            ->join('quempode as qp', 'pp.beneficiario', '=', 'qp.beneficiario')
            ->join('beneficiarios as b', 'qp.tipo', '=', 'b.tipo')
            ->join('contratos as c', function($join) {
                $join->on('b.contrato_id', '=', 'c.id')
                     ->on('c.plano_id', '=', 'pp.plano_id');
            })
            ->join('clientes as cl', 'b.cliente_id', '=', 'cl.id')
            ->leftJoin('beneficiario_produto as bp', function($join) {
                $join->on('b.id', '=', 'bp.beneficiario_id')
                     ->on('p.id', '=', 'bp.produto_id');
            })
            ->select(
                'p.id as produto_id',
                'p.nome as produto_nome',
                'p.ativo as produto_ativo',
                'pp.plano_id',
                'pl.nome as plano_nome',
                'pp.beneficiario as plano_beneficiario',
                'qp.tipo as beneficiario_tipo',
                'b.id as beneficiario_id',
				'b.ativo as beneficiario_ativo',
                'c.id as contrato_id',
                'c.tipo as contrato_tipo',
                'c.status as situacao_contrato',
                'cl.id as cliente_id',
                'cl.nome as cliente_nome',
                'cl.cpfcnpj as cpfcnpj',
                'bp.ativacao as ativacao',
                'bp.data_ativacao as data_ativacao',
                'bp.data_desativacao as data_desativacao',
				'bp.data_inicio as data_inicio',
				'bp.data_fim as data_fim',
            )
            ->where(function ($query) use ($campo, $conteudo) {
                if (($campo != "") and ($conteudo != "")) {
                    $query->where($campo, 'like', "%$conteudo%");
                }
            });

        // Aplicar filtros avançados se existirem
        if (isset($payload->campos)) {
            $query = Cas::montar_filtro($query, $payload);
        }
		
		/*
        if ($situacao_pagto !== "") 
		{
		  if ($situacao_pagto === "A") 
		  {
			$query->leftJoin(
				'vw_contratos_parcela_vencida_mais_antiga as ov',
				'ov.id','=','c.id'
			)->whereNull('ov.id');
		  } else {
			$query->join(
				'vw_contratos_parcela_vencida_mais_antiga as ov',
				'ov.id','=','c.id'
			);
		  }
		}
		*/
		
		
		
        // Aplicar ordenação
        $query->orderBy($orderby, $direction);
        
        // Paginar resultados
        $conciliacao = $query->paginate($limite);

        // Transformar dados para incluir informações adicionais
        $conciliacao->getCollection()->transform(function ($item) {
            // Determinar status da conciliação
            if ($item->ativacao === 1) {
                $item->status_conciliacao = 'Ativo';
            } elseif ($item->ativacao === 0) {
                $item->status_conciliacao = 'Inativo';
            } else {
                $item->status_conciliacao = 'Pendente';
            }

            // Formatar CPF/CNPJ
            if ($item->cpfcnpj) {
                $item->cpfcnpj_formatado = Cas::formatCnpjCpf($item->cpfcnpj);
            }

            // Determinar situação do contrato
            $item->situacao_contrato_descricao = Cas::obterSituacaoContrato($item->situacao_contrato);

            return $item;
        });

        return response()->json($conciliacao, 200);
    }

    public function exportar(Request $request)
    {
        if (!$request->user()->tokenCan('export.conciliacao')) {
            return response()->json(['error' => 'Não autorizado para exportar conciliação.'], 403);
        }

        $campo = $request->input('campo', '');
        $conteudo = $request->input('conteudo', '');
        $payload = (object) $request->all();

        // Mesma query do index, mas sem paginação
        $query = DB::table('produtos as p')
            ->leftJoin('plano_produto as pp', 'p.id', '=', 'pp.produto_id')
            ->leftJoin('planos as pl', 'pp.plano_id', '=', 'pl.id')
            ->leftJoin('quempode as qp', 'pp.beneficiario', '=', 'qp.beneficiario')
            ->leftJoin('beneficiarios as b', 'qp.tipo', '=', 'b.tipo')
            ->leftJoin('contratos as c', function($join) {
                $join->on('b.contrato_id', '=', 'c.id')
                     ->on('c.plano_id', '=', 'pp.plano_id');
            })
            ->leftJoin('clientes as cl', 'c.cliente_id', '=', 'cl.id')
            ->leftJoin('beneficiario_produto as bp', function($join) {
                $join->on('b.id', '=', 'bp.beneficiario_id')
                     ->on('p.id', '=', 'bp.produto_id');
            })
            ->select(
                'p.id as produto_id',
                'p.nome as produto_nome',
                'p.ativo as produto_ativo',
                'pp.plano_id',
                'pl.nome as plano_nome',
                'pp.beneficiario as plano_beneficiario',
                'qp.tipo as beneficiario_tipo',
                'b.id as beneficiario_id',
                'b.nome as beneficiario_nome',
                'b.cpf as beneficiario_cpf',
                'c.id as contrato_id',
                'c.tipo as contrato_tipo',
                'c.status as situacao_contrato',
                'cl.id as cliente_id',
                'cl.nome as cliente_nome',
                'cl.cpfcnpj',
                'bp.ativacao',
                'bp.data_ativacao',
                'bp.data_desativacao'
            )
            ->where(function ($query) use ($campo, $conteudo) {
                if (($campo != "") and ($conteudo != "")) {
                    $query->where($campo, 'like', "%$conteudo%");
                }
            });

        // Aplicar filtros avançados se existirem
        if (isset($payload->campos)) {
            $query = Cas::montar_filtro($query, $payload);
        }

        $dados = $query->get();

        // Transformar dados para exportação
        $dadosExportacao = $dados->map(function ($item) {
            return [
                'Produto ID' => $item->produto_id,
                'Produto Nome' => $item->produto_nome,
                'Produto Ativo' => $item->produto_ativo ? 'Sim' : 'Não',
                'Plano ID' => $item->plano_id,
                'Plano Nome' => $item->plano_nome,
                'Beneficiário Tipo' => $item->beneficiario_tipo,
                'Beneficiário Nome' => $item->beneficiario_nome,
                'Beneficiário CPF' => $item->beneficiario_cpf ? Cas::formatCnpjCpf($item->beneficiario_cpf) : '',
                'Contrato ID' => $item->contrato_id,
                'Contrato Tipo' => $item->contrato_tipo,
                'Situação Contrato' => Cas::obterSituacaoContrato($item->situacao_contrato),
                'Cliente Nome' => $item->cliente_nome,
                'Cliente CPF/CNPJ' => $item->cpfcnpj ? Cas::formatCnpjCpf($item->cpfcnpj) : '',
                'Status Ativação' => $item->ativacao === 1 ? 'Ativo' : ($item->ativacao === 0 ? 'Inativo' : 'Pendente'),
                'Data Ativação' => $item->data_ativacao,
                'Data Desativação' => $item->data_desativacao
            ];
        });

        return response()->json([
            'dados' => $dadosExportacao,
            'total' => $dadosExportacao->count()
        ], 200);
    }

    public function filtros(Request $request)
    {
        if (!$request->user()->tokenCan('view.conciliacao')) {
            return response()->json(['error' => 'Não autorizado para visualizar filtros de conciliação.'], 403);
        }

        // Retornar opções para os filtros
        $filtros = [
            'produtos' => DB::table('produtos')->select('id', 'nome')->where('ativo', true)->get(),
            'planos' => DB::table('planos')->select('id', 'nome')->where('ativo', true)->get(),
            'beneficiario_tipos' => DB::table('beneficiarios')->select('tipo')->distinct()->whereNotNull('tipo')->get(),
            'contrato_tipos' => DB::table('contratos')->select('tipo')->distinct()->whereNotNull('tipo')->get(),
            'situacoes_contrato' => [
                ['value' => 'active', 'label' => 'Ativo'],
                ['value' => 'waitingPayment', 'label' => 'Aguardando Pagamento'],
                ['value' => 'closed', 'label' => 'Encerrado'],
                ['value' => 'canceled', 'label' => 'Cancelado'],
                ['value' => 'stopped', 'label' => 'Parado'],
                ['value' => 'suspended', 'label' => 'Suspenso']
            ],
            'status_ativacao' => [
                ['value' => 1, 'label' => 'Ativo'],
                ['value' => 0, 'label' => 'Inativo'],
                ['value' => null, 'label' => 'Pendente']
            ]
        ];

        return response()->json($filtros, 200);
    }

    public function resumo(Request $request)
    {
        if (!$request->user()->tokenCan('view.conciliacao')) {
            return response()->json(['error' => 'Não autorizado para visualizar resumo de conciliação.'], 403);
        }

        $campo = $request->input('campo', '');
        $conteudo = $request->input('conteudo', '');
        $payload = (object) $request->all();

        // Query base para o resumo
        $query = DB::table('produtos as p')
            ->leftJoin('plano_produto as pp', 'p.id', '=', 'pp.produto_id')
            ->leftJoin('planos as pl', 'pp.plano_id', '=', 'pl.id')
            ->leftJoin('quempode as qp', 'pp.beneficiario', '=', 'qp.beneficiario')
            ->leftJoin('beneficiarios as b', 'qp.tipo', '=', 'b.tipo')
            ->leftJoin('contratos as c', function($join) {
                $join->on('b.contrato_id', '=', 'c.id')
                     ->on('c.plano_id', '=', 'pp.plano_id');
            })
            ->leftJoin('clientes as cl', 'c.cliente_id', '=', 'cl.id')
            ->leftJoin('beneficiario_produto as bp', function($join) {
                $join->on('b.id', '=', 'bp.beneficiario_id')
                     ->on('p.id', '=', 'bp.produto_id');
            })
            ->where(function ($query) use ($campo, $conteudo) {
                if (($campo != "") and ($conteudo != "")) {
                    $query->where($campo, 'like', "%$conteudo%");
                }
            });

        // Aplicar filtros avançados se existirem
        if (isset($payload->campos)) {
            $query = Cas::montar_filtro($query, $payload);
        }

        // Calcular resumo
        $resumo = [
            'total_registros' => $query->count(),
            'produtos_ativos' => $query->where('bp.ativacao', 1)->count(),
            'produtos_inativos' => $query->where('bp.ativacao', 0)->count(),
            'produtos_pendentes' => $query->whereNull('bp.ativacao')->count()
        ];

        return response()->json($resumo, 200);
    }
}