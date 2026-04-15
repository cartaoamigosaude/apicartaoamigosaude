<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Beneficiario;
use App\Models\Contrato;
use App\Models\Plano;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Helpers\Cas;
use App\Helpers\ClubeCerto;
use App\Helpers\Conexa;
use App\Helpers\ChatHot;
use stdClass;
use DB;

class BeneficiarioController extends Controller
{
	public function filtro(Request $request)
    {
        if ((!$request->user()->tokenCan('view.beneficiarios')) and (!$request->user()->tokenCan('view.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar beneficiários.'], 403);
        }
		
		$limite              					= $request->input('limite', 20);
		$orderby             					= $request->input('orderby', 'beneficiarios.id');
		$direction          					= $request->input('direction', 'desc');
		
		$payload								= (object) $request->all();
		$tem_produto_id							= false;
		$tem_ativacao							= 1;
		
		foreach ($payload->campos as $filtro)
		{
			$filtro								= (object) $filtro;
			if ($filtro->campo =='beneficiario_produto.produto_id')
			{
				$tem_produto_id					= true;
			}
			if ($filtro->campo =='beneficiario_produto.ativacao')
			{
				$condicoes						= (object) $filtro->condicoes;
				foreach ($condicoes as $condicao)
				{
					$condicao					= (object) $condicao;
					if ($condicao->condicao == 'equals')
					{
						$tem_ativacao			= $condicao->conteudos[0];
					}
				}
			}
		}
		
		$query 				= DB::table('beneficiarios')
								->leftJoin('parentescos','beneficiarios.parentesco_id','=','parentescos.id')
								->join('contratos', 'beneficiarios.contrato_id', '=', 'contratos.id')
								->join('clientes', 'beneficiarios.cliente_id', '=', 'clientes.id');

		// Adicionando verificação do produto_id no payload->campos
		if ($tem_produto_id) 
		{
			$query->leftJoin('beneficiario_produto', function ($join) {
					$join->on('beneficiarios.id', '=', 'beneficiario_produto.beneficiario_id');
			});

			// Adicionando a lógica do campo booleano tem_produto_id
			$query->addSelect(
				DB::raw('IF(beneficiario_produto.id IS NOT NULL AND beneficiario_produto.ativacao = 1, 1, 0) as tem_produto')
			);

			// Filtro condicional com base no $tem_produto_id
			if ($tem_ativacao == 1) 
			{
				// Filtrar beneficiários que têm o produto ativo
				$query->where('beneficiario_produto.ativacao', '=', 1);
			} else {
				// Filtrar beneficiários que não têm o produto ou têm ativacao = 0
				$query->where(function ($subquery) {
					$subquery->where('beneficiario_produto.id','=',null)
							 ->orWhere('beneficiario_produto.ativacao', '=', 0);
				});
			}
		}

		// Adicionando filtros variáveis
		if (isset($payload->campos)) 
		{
			$query = Cas::montar_filtro($query, $payload);
		}

		// Adicionando seleção de colunas
		$query->select(
			'beneficiarios.id',
			'beneficiarios.contrato_id',
			'contratos.tipo as tipo_contrato',
			'contratos.situacao_pagto',
			'contratos.plano_id as cplano_id',
			'clientes.cpfcnpj',
			'clientes.nome as cliente',
			'clientes.data_nascimento',
			'clientes.telefone',
			'contratos.status',
			'beneficiarios.tipo_usuario',
			'beneficiarios.vigencia_inicio',
			'beneficiarios.plano_id as bplano_id',
			DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
			'beneficiarios.desc_status',
			'beneficiarios.tipo as tipo_benef',
			'parentescos.nome as parentesco',
			'beneficiarios.parent_id'
		);

		// Ordenação
		$query->orderBy($orderby, $direction);

		// Executando a query
        $beneficiarios								= $query->paginate($limite);

        $beneficiarios->getCollection()->transform(function ($beneficiario) 
        {
          
            $beneficiario->tipo_usuario             = ucfirst(strtolower($beneficiario->tipo_usuario));
            $beneficiario->desc_status              = ucfirst(strtolower($beneficiario->desc_status));
            $beneficiario->cpfcnpj                  = Cas::formatCnpjCpf($beneficiario->cpfcnpj); 
			$beneficiario->telefone                 = Cas::formatarTelefone($beneficiario->telefone);
			$qtde                					= 0;
			$beneficiario->preco					= 0;
			$beneficiario->qtde_beneficiarios		= 0;
			$beneficiario->disp						= 0;
			$beneficiario->expansoes 				= array();
			$beneficiario->csituacao                = Cas::obterSituacaoContrato($beneficiario->status); 
			$beneficiario->produtos					= array();
			$beneficiario->nome_plano				= "";
			
			if ($beneficiario->csituacao == 'Ativo')
			{
				
				if ($beneficiario->situacao_pagto == 'A')
				{
					$beneficiario->csituacao_pagto	= 'Adimplemente';
				} else {
					$beneficiario->csituacao_pagto	= 'Inadimplente';
				}
			} else {
				$beneficiario->csituacao_pagto		= 'Inadimplente';
			}
			
			if ($beneficiario->tipo_usuario == 'Titular')
			{
				if ($beneficiario->tipo_contrato == 'F')
				{
					$plano              				= Plano::select('id','nome','preco','qtde_beneficiarios')->find($beneficiario->cplano_id);
				} else {
					$plano              				= Plano::select('id','nome','preco','qtde_beneficiarios')->find($beneficiario->bplano_id);
				}
				
				if (isset($plano->id))
				{
					$beneficiario->qtde_beneficiarios  	= $plano->qtde_beneficiarios;
					$beneficiario->preco 				= $plano->preco;
					if ($beneficiario->status == 'active')
					{
						$beneficiario->produtos 		= Cas::obterBeneficios($plano->id,$beneficiario->tipo_benef, $beneficiario->id);
					} else {
						$beneficiario->produtos			= array();
					}
					$beneficiario->nome_plano			= $plano->nome;
				}
			
				$expansoes							= DB::connection('mysql')
														->table('beneficiarios')
														->select(
															     'beneficiarios.id',
                                                                 'clientes.cpfcnpj',
                                                                 'clientes.nome as cliente',
                                                                 'clientes.data_nascimento',
                                                                 'beneficiarios.tipo_usuario',
																 'clientes.data_nascimento',
																  DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
																 'clientes.telefone',
                                                                 'beneficiarios.vigencia_inicio',
																 'beneficiarios.ativo',
                                                                 'beneficiarios.desc_status',
																 'beneficiarios.tipo as tipo_benef',
																 'beneficiarios.parent_id',
																 'parentescos.nome as parentesco',
                                                                )
													    ->leftJoin('parentescos','beneficiarios.parentesco_id','=','parentescos.id')
													    ->join('clientes','beneficiarios.cliente_id','=','clientes.id')
                                                        ->where('beneficiarios.parent_id','=',$beneficiario->id)
														->get();
				if ((count($expansoes) ==0) and ($beneficiario->tipo_contrato == 'F'))
				{
					$expansoes						= DB::connection('mysql')
														->table('beneficiarios')
														->select(
															     'beneficiarios.id',
                                                                 'clientes.cpfcnpj',
                                                                 'clientes.nome as cliente',
                                                                 'clientes.data_nascimento',
                                                                 'beneficiarios.tipo_usuario',
																 'clientes.data_nascimento',
																  DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
																 'clientes.telefone',
                                                                 'beneficiarios.vigencia_inicio',
																 'beneficiarios.ativo',
                                                                 'beneficiarios.desc_status',
																 'beneficiarios.tipo as tipo_benef',
																 'beneficiarios.parent_id',
																 'parentescos.nome as parentesco'
                                                                )
													    ->leftJoin('parentescos','beneficiarios.parentesco_id','=','parentescos.id')
													    ->join('clientes','beneficiarios.cliente_id','=','clientes.id')
                                                        ->where('beneficiarios.contrato_id','=',$beneficiario->contrato_id)
														->where('beneficiarios.tipo','=','D')
														->get();		
				}
				
				if (($beneficiario->tipo_benef == 'T') and ($beneficiario->tipo_contrato == 'F'))
				{
					$beneficiario->pode_excluir 			= 'N';   
				} else {
					if ($beneficiario->tipo_benef == 'D') 
					{
						$beneficiario->pode_excluir 		= 'S';  
					} else {
						if (count($expansoes) > 0)
						{
							$beneficiario->pode_excluir 	= 'N';  
						} else {
							$beneficiario->pode_excluir 	= 'S'; 
						}
					}
				}
				
				//if (($beneficiario->csituacao == 'Encerrado') or ($beneficiario->csituacao == 'Cancelado'))
				//{
				//	$beneficiario->pode_excluir 			= 'S'; 
				//}
				
				foreach ($expansoes as $expansao)
				{
					$plano 								= Cas::obterPlanoBeneficios($expansao->id);
					$expansao->produtos					= $plano->produtos;
					$expansao->nome_plano				= $plano->nome;
					$expansao->tipo_usuario             = ucfirst(strtolower($expansao->tipo_usuario));
					$expansao->desc_status              = ucfirst(strtolower($expansao->desc_status));
					$expansao->telefone                 = Cas::formatarTelefone($expansao->telefone);
					if ($expansao->desc_status == 'Ativo')
					{
						$qtde           				= $qtde + 1;
					}
					if ($expansao->tipo_benef == 'D')
					{
						$expansao->pode_excluir 		= 'S';   
					} else {
						$expansao->pode_excluir 		= 'N';   
					}
					$beneficiario->expansoes[]			= $expansao;
				}
				if ($beneficiario->qtde_beneficiarios > 1)
				{	
					$beneficiario->tipo_usuario			= $beneficiario->tipo_usuario . " + " . $qtde . ' de ' . $beneficiario->qtde_beneficiarios - 1 . ' Dep ';
				} 
				$beneficiario->qtde 					= $qtde;
				$beneficiario->disp                     = ($beneficiario->qtde_beneficiarios - $qtde) - 1;
			} else {	
				$expansoes								= DB::connection('mysql')
															->table('beneficiarios')
															->select(
																	 'beneficiarios.id',
																	 'clientes.cpfcnpj',
																	 'clientes.nome as cliente',
																	 'clientes.data_nascimento',
																	 'beneficiarios.tipo_usuario',
																	 'clientes.data_nascimento',
																	  DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
																	 'clientes.telefone',
																	 'beneficiarios.vigencia_inicio',
																	 'beneficiarios.desc_status',
																	 'beneficiarios.tipo as tipo_benef',
																	 'beneficiarios.parent_id',
																	 'parentescos.nome as parentesco'
																	)
															->leftJoin('parentescos','beneficiarios.parentesco_id','=','parentescos.id')
															->join('clientes','beneficiarios.cliente_id','=','clientes.id')
															->where('beneficiarios.id','=',$beneficiario->parent_id)
															->get();											
				if (count($expansoes) ==0)
				{
					$expansoes							= DB::connection('mysql')
															->table('beneficiarios')
															->select(
																	 'beneficiarios.id',
																	 'clientes.cpfcnpj',
																	 'clientes.nome as cliente',
																	 'clientes.data_nascimento',
																	 'beneficiarios.tipo_usuario',
																	 'clientes.data_nascimento',
																	  DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
																	 'clientes.telefone',
																	 'beneficiarios.vigencia_inicio',
																	 'beneficiarios.desc_status',
																	 'beneficiarios.tipo as tipo_benef',
																     'beneficiarios.parent_id',
																	 'parentescos.nome as parentesco'
																	)
															->leftJoin('parentescos','beneficiarios.parentesco_id','=','parentescos.id')		
															->join('clientes','beneficiarios.cliente_id','=','clientes.id')
															->where('beneficiarios.contrato_id','=',$beneficiario->contrato_id)
															->where('beneficiarios.tipo','=','T')
															->get();		
				}
				
				if (($beneficiario->tipo_benef == 'T') and ($beneficiario->tipo_contrato == 'F'))
				{
					$beneficiario->pode_excluir 			= 'N';   
				} else {
					if ($beneficiario->tipo_benef == 'D') 
					{
						$beneficiario->pode_excluir 		= 'S';  
					} else {
						if (count($expansoes) > 0)
						{
							$beneficiario->pode_excluir 	= 'N';  
						} else {
							$beneficiario->pode_excluir 	= 'S'; 
						}
					}
				}
			
				foreach ($expansoes as $expansao)
				{
					if (($beneficiario->parent_id ==0) or (is_null($beneficiario->parent_id)))
					{
						$beneficiario->parent_id		= $expansao->id;
					}
					$plano 								= Cas::obterPlanoBeneficios($expansao->id);
					if ((isset($plano->produtos)) and (count($plano->produtos) > 0))
					{
						$expansao->produtos				= $plano->produtos;
					} else {
						$expansao->produtos				= array();
					}
					if (isset($plano->nome))
					{
						$expansao->nome_plano			= $plano->nome;
					} else {
						$expansao->nome_plano			= "";
					}
					if ($expansao->tipo_benef == 'D')
					{
						$expansao->pode_excluir 		= 'S';   
					} else {
						$expansao->pode_excluir 		= 'N';   
					}
					$expansao->tipo_usuario             = ucfirst(strtolower($expansao->tipo_usuario));
					$expansao->desc_status              = ucfirst(strtolower($expansao->desc_status));
					$expansao->telefone                 = Cas::formatarTelefone($expansao->telefone);
					$beneficiario->expansoes[]			= $expansao;
				}
				$plano 									= Cas::obterPlanoBeneficios($beneficiario->id);
				$beneficiario->produtos					= $plano->produtos;
				$beneficiario->nome_plano				= $plano->nome;
			}
            return $beneficiario;
         });
		 //$beneficiarios									= $query->toSql();
         return response()->json($beneficiarios, 200);
		
	}
	
	public function filtro_ativardesativar(Request $request)
    {
        if ((!$request->user()->tokenCan('view.beneficiarios')) and (!$request->user()->tokenCan('view.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar beneficiários.'], 403);
        }
		
		$payload								= (object) $request->all();
		
		$tipocontrato 							= $request->input('tipocontrato', '');
		$produto_id 							= $request->input('produto_id', 0);
		$ativarbloquear							= $request->input('ativarbloquear', 'A');
		
		if (($produto_id !=3) and ($payload->opcao =='E'))
		{
			 return response()->json(['error' => 'Lay out não definido'], 404);
		}
		
		$query = DB::table('beneficiarios')
			->select(
				'beneficiarios.id',
				'beneficiarios.contrato_id',
				'contratos.tipo as tipo_contrato',
				'clientes.cpfcnpj',
				'clientes.nome as cliente',
				'clientes.data_nascimento',
				'clientes.sexo',
				DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
				'clientes.telefone',
				'contratos.status',
				'beneficiarios.tipo_usuario',
				'beneficiarios.vigencia_inicio',
				'beneficiarios.desc_status',
				'beneficiarios.tipo',
				'beneficiarios.parent_id'
			)
			->join('contratos', 'beneficiarios.contrato_id', '=', 'contratos.id')
			->join('clientes', 'beneficiarios.cliente_id', '=', 'clientes.id');
			
		if ($ativarbloquear == 'A')
		{
			//$query->leftJoin('beneficiario_produto', 'beneficiarios.id', '=', 'beneficiario_produto.beneficiario_id');
			if ($tipocontrato != "")
			{
				$query->where('contratos.tipo', '=',$tipocontrato);
			}
			$query->where('contratos.status', '=','active');
			$query->where('beneficiarios.desc_status', '=', 'ATIVO');
		} else {
			$query->join('beneficiario_produto', 'beneficiarios.id', '=', 'beneficiario_produto.beneficiario_id');
			if ($tipocontrato != "")
			{
				$query->where('contratos.tipo', '=',$tipocontrato);
			}
			$query->where('contratos.status', '<>','active');
			$query->where('beneficiarios.desc_status', '<>','ATIVO');
			
			$query->where('beneficiario_produto.produto_id', '=',$produto_id)
				  ->where('beneficiario_produto.ativacao', '=',1);
				  
		}
		
		$query->orderBy('beneficiarios.tipo', 'desc');
		
		//$beneficiarios									= $query->toSql();
        //return response()->json($beneficiarios, 200);
		

		// Executando a query
        $qbeneficiarios								= $query->get();
		
		// return response()->json($qbeneficiarios, 200);
		 
		$beneficiarios 								= array();
		$ids 										= array();
		
        foreach ($qbeneficiarios as $beneficiario)
        {
			$beneficiario->tipo_usuario             = ucfirst(strtolower($beneficiario->tipo_usuario));
            $beneficiario->desc_status              = ucfirst(strtolower($beneficiario->desc_status));
            $beneficiario->cpfcnpj                  = Cas::formatCnpjCpf($beneficiario->cpfcnpj); 
			$beneficiario->telefone                 = Cas::formatarTelefone($beneficiario->telefone);
			$selecionar 							= false;
			
			if ($ativarbloquear == 'A')
			{
				$beneficiarioproduto              	=  \App\Models\BeneficiarioProduto::select('id','ativacao')
																					   ->where('beneficiario_id','=',$beneficiario->id)
																					   ->where('produto_id','=',$produto_id)
																					   ->first();
				if ((!isset($beneficiarioproduto->id)) or ($beneficiarioproduto->ativacao ==0))
				{
					$selecionar 					= true;
				}					
			} else {
				$selecionar 						= true;
			}
				
			if (($selecionar) and (Cas::beneficiarioProduto($beneficiario->id,$produto_id)))
			{
				if ($beneficiario->tipo == 'T')
				{
					$ids[$beneficiario->id]						= $beneficiario->id;
					$dependentes 								= array();
					
					$query									= DB::connection('mysql')
																		->table('beneficiarios')
																		->select('beneficiarios.id',
																				 'clientes.cpfcnpj',
																				 'clientes.nome as cliente',
																				 'clientes.data_nascimento',
																				 'beneficiarios.tipo_usuario',
																				 'clientes.data_nascimento',
																				 DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
																				 'clientes.telefone',
																				 'clientes.sexo',
																				 'beneficiarios.vigencia_inicio',
																				 'beneficiarios.ativo',
																				 'beneficiarios.desc_status',
																				 'beneficiarios.tipo as tipo_benef',
																				 'beneficiarios.parent_id')
																		->join('clientes','beneficiarios.cliente_id','=','clientes.id');
					if ($beneficiario->tipo_contrato == 'F')
					{
						$query->where('beneficiarios.contrato_id','=',$beneficiario->contrato_id);
					} else {
						$query->where('beneficiarios.parent_id','=',$beneficiario->id);
					}			
					
					if ($ativarbloquear == 'A')
					{
						$query->where('beneficiarios.desc_status', '=', 'ATIVO');
					} else {
						$query->join('beneficiario_produto', 'beneficiarios.id', '=', 'beneficiario_produto.beneficiario_id');
						$query->where('beneficiarios.desc_status', '<>','ATIVO');
						$query->where('beneficiario_produto.produto_id', '=',$produto_id)
							  ->where('beneficiario_produto.ativacao', '=',1);
					}
		
					$query->where('beneficiarios.tipo','=','D');
					
					$expansoes										= $query->get();
					$dependentes									= array();
					
					foreach ($expansoes as $dependente)
					{
						$selecionar 								= false;
						if ($ativarbloquear == 'A')
						{
							$beneficiarioproduto              		= \App\Models\BeneficiarioProduto::select('id','ativacao')
																								   ->where('beneficiario_id','=',$dependente->id)
																								   ->where('produto_id','=',$produto_id)
																								   ->first();
							if ((!isset($beneficiarioproduto->id)) or ($beneficiarioproduto->ativacao ==0))
							{
								$selecionar 						= true;
							}					
						} else {
							$selecionar 							= true;
						}
			
						if (($selecionar) and (Cas::beneficiarioProduto($dependente->id,$produto_id)))
						{
							$dependente->tipo_usuario             	= ucfirst(strtolower($dependente->tipo_usuario));
							$dependente->desc_status              	= ucfirst(strtolower($dependente->desc_status));
							$dependente->cpfcnpj                 	= Cas::formatCnpjCpf($dependente->cpfcnpj); 
							$dependente->telefone                 	= Cas::formatarTelefone($dependente->telefone);
							$dependentes[]							= $dependente;
							$ids[$dependente->id]					= $dependente->id;
						}
					}
					$beneficiario->expansoes					= $dependentes;
					$beneficiarios[] 							= $beneficiario;
				} else {
					if (!isset($ids[$beneficiario->id]))
					{
						$beneficiarios[] 						= $beneficiario;
						$ids[$beneficiario->id]					= $beneficiario->id;
						$beneficiario->expansoes				= array();
					}
				}
	        }
        };
		//$beneficiarios									= $query->toSql();
		if ($payload->opcao =='V')
		{
			return response()->json($beneficiarios, 200);
		}
		
		ini_set('memory_limit', '1024M');
		set_time_limit(0);

		return Cas::exportCsv($payload,$beneficiarios);
		
	}
	
	public function excel(Request $request)
    {
        if ((!$request->user()->tokenCan('view.beneficiarios')) and (!$request->user()->tokenCan('view.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar beneficiários.'], 403);
        }
		
		$orderby             					= $request->input('orderby', 'beneficiarios.id');
		$direction          					= $request->input('direction', 'desc');
		
		$payload								= (object) $request->all();
		$tem_produto_id							= false;
		$tem_ativacao							= 1;
		
		foreach ($payload->campos as $filtro)
		{
			$filtro								= (object) $filtro;
			if ($filtro->campo =='beneficiario_produto.produto_id')
			{
				$tem_produto_id					= true;
			}
			if ($filtro->campo =='beneficiario_produto.ativacao')
			{
				$condicoes						= (object) $filtro->condicoes;
				foreach ($condicoes as $condicao)
				{
					$condicao					= (object) $condicao;
					if ($condicao->condicao == 'equals')
					{
						$tem_ativacao			= $condicao->conteudos[0];
					}
				}
			}
		}
		
		$query 				= DB::table('beneficiarios')
								->join('contratos', 'beneficiarios.contrato_id', '=', 'contratos.id')
								->join('clientes as cliente_contrato', 'contratos.cliente_id', '=', 'cliente_contrato.id') // Nome do cliente do contrato
							    ->join('clientes as cliente_beneficiario', 'beneficiarios.cliente_id', '=', 'cliente_beneficiario.id');

		// Adicionando verificação do produto_id no payload->campos
		if ($tem_produto_id) 
		{
			$query->leftJoin('beneficiario_produto', function ($join) {
					$join->on('beneficiarios.id', '=', 'beneficiario_produto.beneficiario_id');
			});

			// Adicionando a lógica do campo booleano tem_produto_id
			$query->addSelect(
				DB::raw('IF(beneficiario_produto.id IS NOT NULL AND beneficiario_produto.ativacao = 1, 1, 0) as tem_produto')
			);

			// Filtro condicional com base no $tem_produto_id
			if ($tem_ativacao == 1) 
			{
				// Filtrar beneficiários que têm o produto ativo
				$query->where('beneficiario_produto.ativacao', '=', 1);
			} else {
				// Filtrar beneficiários que não têm o produto ou têm ativacao = 0
				$query->where(function ($subquery) {
					$subquery->where('beneficiario_produto.id','=',null)
							 ->orWhere('beneficiario_produto.ativacao', '=', 0);
				});
			}
		}
		// Adicionando filtros variáveis
		if (isset($payload->campos)) 
		{
			$query = Cas::montar_filtro($query, $payload);
		}
		// Adicionando seleção de colunas
		$query->select(
			'beneficiarios.id',
			'beneficiarios.contrato_id',
			'contratos.tipo as tipo_contrato',
			'contratos.plano_id as cplano_id',
			'cliente_contrato.nome as cliente',
			'cliente_beneficiario.cpfcnpj',
			'cliente_beneficiario.nome as beneficiario',
			'cliente_beneficiario.data_nascimento',
			'cliente_beneficiario.telefone',
			'cliente_beneficiario.cep',
			'cliente_beneficiario.data_nascimento',
			'cliente_beneficiario.sexo',
			'cliente_beneficiario.cep',
			'cliente_beneficiario.logradouro',
			'cliente_beneficiario.numero',
			'cliente_beneficiario.complemento',
			'cliente_beneficiario.bairro',		
			'cliente_beneficiario.cidade',
			'cliente_beneficiario.estado',	
			'cliente_beneficiario.email',			
			'contratos.status',
			'beneficiarios.tipo',
			'beneficiarios.tipo_usuario',
			'beneficiarios.vigencia_inicio',
			'beneficiarios.vigencia_fim',
			'beneficiarios.plano_id as bplano_id',
			'beneficiarios.desc_status',
			'beneficiarios.parent_id'
		);
		// Ordenação
		$query->orderBy($orderby, $direction);
		// Executando a query
        $beneficiarios										= $query->get();
		//$beneficiarios									= $query->toSql();
		
		return Excel::download(new \App\Exports\BeneficiariosExport($beneficiarios), 'beneficiarios.xlsx');
	}
	
    public function index(Request $request)
    {
        if ((!$request->user()->tokenCan('view.beneficiarios')) and (!$request->user()->tokenCan('view.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar beneficiários.'], 403);
        }

        $limite              					= $request->input('limite', 12);
		$orderby             					= $request->input('orderby', 'beneficiarios.tipo_usuario');
		$direction          					= $request->input('direction', 'desc');
		$campo            					    = $request->input('campo', '');
        $pesquisa            					= $request->input('pesquisa', '');
        $contrato_id                            = $request->input('contrato_id', 0);

        $query									= DB::connection('mysql')
														->table('beneficiarios')
														->select(
															     'beneficiarios.id',
                                                                 'clientes.cpfcnpj',
                                                                 'clientes.nome as cliente',
                                                                 'clientes.data_nascimento',
                                                                 'beneficiarios.tipo_usuario',
                                                                 'beneficiarios.vigencia_inicio',
                                                                 'beneficiarios.desc_status',
																 'beneficiarios.plano_id',
																 'contratos.plano_id as cplano_id',
																 'contratos.tipo as tipo_contrato',
																 'beneficiarios.tipo as tipo_benef',
																 'parentescos.nome as parentesco',
																 'beneficiarios.parent_id'
                                                                )
                                                        ->where('beneficiarios.contrato_id','=',$contrato_id);
		if ($pesquisa !="")
		{
			$query->where('clientes.nome','like',"$pesquisa%");
		}
		
        $query->leftJoin('clientes','beneficiarios.cliente_id','=','clientes.id')
			  ->leftJoin('parentescos','beneficiarios.parentesco_id','=','parentescos.id')
			  ->leftJoin('contratos','beneficiarios.contrato_id','=','contratos.id');
        //$query->orderBy($orderby,$direction);
		$query->orderByRaw("
				CASE 
					WHEN beneficiarios.parent_id = 0 THEN beneficiarios.id
					ELSE beneficiarios.parent_id
				END, 
				beneficiarios.tipo_usuario ASC
			");
		
        $beneficiarios								= $query->paginate($limite);

        $beneficiarios->getCollection()->transform(function ($beneficiario) 
        {
			
			if ($beneficiario->plano_id > 0)
			{
				$plano_id							= $beneficiario->plano_id;
			} else {
				$plano_id							= $beneficiario->cplano_id;
			}
			
			$plano              					= Plano::select('id','nome','preco')->find($plano_id);
        
			if (isset($plano->id))
			{
				$beneficiario->plano  				= $plano->nome;
				$beneficiario->preco 				= $plano->preco;
			}
			
			if (($beneficiario->tipo_benef == 'T') and ($beneficiario->tipo_contrato == 'F'))
			{
				$beneficiario->pode_excluir 		= 'N';   
			} else {
				if ($beneficiario->tipo_benef == 'D')
				{
					$beneficiario->pode_excluir 	= 'S';  
				} else {
					$beneficiario->pode_excluir 	= 'N';   
				}
			}
			
            $beneficiario->tipo_usuario             = ucfirst(strtolower($beneficiario->tipo_usuario));
            $beneficiario->desc_status              = ucfirst(strtolower($beneficiario->desc_status));
            $beneficiario->cpfcnpj                  = Cas::formatCnpjCpf($beneficiario->cpfcnpj);   
            $beneficiario->idade 				    = Carbon::createFromDate($beneficiario->data_nascimento)->age;         
            return $beneficiario;
         });
         
		 $retorno 				        			= new stdClass();
		 $retorno->beneficiarios 					= $beneficiarios;
		 $retorno->qtde_titular						= 0;
		 $retorno->valor_titular 					= 0;
		 
         $titulares 								= Beneficiario::where('contrato_id','=',$contrato_id)
																  ->where('tipo','=','T')
																  ->where('desc_status','=','ATIVO')
																  ->get();
		 foreach ($titulares as $titular)
		 {
			 
			$plano              					= Plano::select('preco')->find($titular->plano_id);
        
			if (isset($plano->preco))
			{
				$retorno->valor_titular  			= $retorno->valor_titular +  $plano->preco;
			}
			$retorno->qtde_titular ++;
		 }
		 
		 $retorno->qtde_dependente 					= Beneficiario::where('contrato_id','=',$contrato_id)
																			  ->where('tipo','=','D')
																			  ->where('desc_status','=','ATIVO')
																			  ->count();
		 
         return response()->json($retorno, 200);

    }

    public function show(Request $request, $id)
    {
		if ((!$request->user()->tokenCan('view.beneficiarios')) and (!$request->user()->tokenCan('view.contratos'))) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar beneficiários.'], 403);
        }

        $beneficiario                              = Beneficiario::with('cliente','contrato')->find($id);

        if (!isset($beneficiario->id))
        {
            return response()->json(['error' => 'Beneficiário não encontrado.'], 404);
        }

		$titulares 									= array();
		
		if ($beneficiario->tipo == 'D')
		{
			if ($beneficiario->parent_id > 0)
			{
				 $rtitular                          = Beneficiario::with('cliente:id,nome')
																 ->where('id','=',$beneficiario->parent_id)
																 ->first();
			} else {
				 $rtitular                          = Beneficiario::with('cliente:id,nome')
																 ->where('contrato_id','=',$beneficiario->contrato_id)
																 ->where('desc_status','=','ATIVO')
																 ->where('tipo','=','T')
																 ->first();
			}
			if (isset($rtitular->id))
			{
				 $beneficiario->parent_id			= $rtitular->id;
				 $titular				        	= new stdClass();
				 $titular->id						= $rtitular->id;
				 $titular->nome 					= $rtitular->cliente->nome;
				 $titulares[]						= $titular;
			}
		}
		$beneficiario->titulares 					= $titulares;
        return response()->json($beneficiario, 200);

    }
	
	public function index_titular(Request $request, $id)
    {
		if ((!$request->user()->tokenCan('view.beneficiarios')) and (!$request->user()->tokenCan('view.contratos'))) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar beneficiários.'], 403);
        }

		$beneficiarios 								 = array();
		
        $lbeneficiarios                              = Beneficiario::with('cliente:id,nome')
																 ->where('contrato_id','=',$id)
																 ->where('desc_status','=','ATIVO')
																 ->where('tipo','=','T')
																 ->get()
																 ->sortBy('cliente.nome'); // Ordena em memória
		foreach ($lbeneficiarios as $beneficiario)
		{
			$titular				        		= new stdClass();
			$titular->id							= $beneficiario->id;
			$titular->nome 							= $beneficiario->cliente->nome;
			$beneficiarios[] 						= $titular;
		}
		
        return response()->json($beneficiarios, 200);

    }

    public function store(Request $request)
    {
    
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para criar beneficiários.'], 403);
        }

        $validated = $request->validate([
            'contrato_id' 		=> 'required|exists:contratos,id',
            'cliente_id' 		=> 'required|exists:clientes,id',
            'vigencia_inicio'	=> 'required|date',
            'vigencia_fim' 		=> 'nullable|date',
            'tipo' 				=> 'required|string|max:1',
            'ativo' 			=> 'required|boolean',
            'parent_id' 		=> 'required|exists:clientes,id',
        ]);

		if ($request->tipo =='D')
		{
			$validator = Validator::make($request->all(), [
				'parentesco_id'		 => 'required|exists:parentescos,id',
			]);
			
			if ($validator->fails()) 
			{
				return response()->json(['message' => Cas::getMessageValidTexto($validator->errors())], 422);
			}
			
			if (($request->parentesco_id == 3) or ($request->parentesco_id == 6))
			{
				$cliente                					= \App\Models\Cliente::find($request->cliente_id);

				if (isset($cliente->id)) 
				{
					$idade 				    				= Carbon::createFromDate($cliente->data_nascimento)->age;  
					if ($idade > 21)
					{
						return response()->json(['message' => 'Irmãos e Netos não podem ser maior que 21 anos'], 404);
					}
				}
			}
		} 
		
        return Beneficiario::create($validated);
    }

    public function update(Request $request, $id)
    {
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar beneficiários.'], 403);
        }

        $validated = $request->validate([
            'contrato_id' 		=> 'required|exists:contratos,id',
            'cliente_id' 		=> 'required|exists:clientes,id',
            'vigencia_inicio' 	=> 'required|date',
            'vigencia_fim' 		=> 'nullable|date',
            'tipo' 				=> 'required|string|max:1',
            'ativo' 			=> 'required|boolean',
            'parent_id' 		=> 'required|exists:clientes,id',
        ]);

		if ($request->tipo =='D')
		{
			$validator = Validator::make($request->all(), [
				'parentesco_id'		 => 'required|exists:parentescos,id',
			]);
			
			if ($validator->fails()) 
			{
				return response()->json(['message' => Cas::getMessageValidTexto($validator->errors())], 422);
			}
			
			if (($request->parentesco_id == 3) or ($request->parentesco_id == 6))
			{
				$cliente                					= \App\Models\Cliente::find($request->cliente_id);

				if (isset($cliente->id)) 
				{
					$idade 				    				= Carbon::createFromDate($cliente->data_nascimento)->age;  
					if ($idade > 21)
					{
						return response()->json(['message' => 'Irmãos e Netos não podem ser maior que 21 anos'], 404);
					}
				}
			}
		} 
		
        $beneficiario = Beneficiario::findOrFail($id);
        $beneficiario->update($validated);

        return $beneficiario;
    }

    public function destroy(Request $request, $id)
    {
		
		if ((!$request->user()->tokenCan('delete.beneficiarios')) and (!$request->user()->tokenCan('delete.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para excluir beneficiários.'], 403);
        }

        $beneficiario 								= \App\Models\Beneficiario::findOrFail($id);

        if ($beneficiario->dependentes()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o beneficiário, pois ele é titular com outros dependentes.'], 400);
        }

        $beneficiario->delete();
		
		$retorno 				        			= new stdClass();
		$retorno->ok 								= 'S';
		$retorno->id 								= $id;
		
        return response()->json($retorno, 200);
    }
	
	public function importar_titular(Request $request)
    {
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para criar beneficiarios.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'contrato_id' 	=> 'required|exists:contratos,id',
			'arquivo' 		=> 'required|file|mimes:csv,txt|max:2048', // Limita a 2MB e verifica extensão
		]);
		
		if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$errors 								= array();
		$titulares 								= array();
		
		if ($file = $request->file('arquivo')) 
		{
				// Adiciona uma regra personalizada para validar CPF
			Validator::extend('cpf_valido', function ($attribute, $value, $parameters, $validator) {
				return Cas::validarCpf($value);
			});
	
			Log::info("importar_titular", ['importar_titular,' => 'sim']);
	
			try
			{
				$fileName 					= $file->getClientOriginalName();
				$folderName 				= '/uploads/arquivos/';
				$extension 			        = $file->getClientOriginalExtension() ?: 'csv';
				$destinationPath 			= public_path()  . $folderName;
				$safeName 					= Str::random(10) . '.' . $extension;
				$file->move($destinationPath, $safeName);
				$retorno                  	= false;
				$handle 					= @fopen($destinationPath.$safeName, "r");
				$linha						= 1;
				
				if ($handle) 
				{
						 // Lê o arquivo CSV
					$errors 				= [];
					$lineNumber 			= 1;

						// Define os cabeçalhos esperados
					$expectedHeaders = [
							'CPF', 'NOME', 'NASCIMENTO', 'TELEFONE', 'SEXO', 'EMAIL',
							'CEP', 'ESTADO', 'CIDADE', 'BAIRRO', 'COMPLEMENTO',
							'LOGRADOURO', 'NUMERO', 'OPERACAO', 'PLANO'
						];

						// Lê a primeira linha (cabeçalhos)
					$headers 		= fgetcsv($handle, 0, ';'); // Define ";" como delimitador
						
						// Remove o BOM se presente
					if (isset($headers[0])) {
						$headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
					}
						
					$headers 		= array_map(fn($header) => mb_convert_encoding($header, 'UTF-8', 'auto'), $headers);
						
						 
					if ($headers !== $expectedHeaders) {
						fclose($handle);
							//return response()->json(['error' => $headers],400);
						return response()->json(['error' => 'Os cabeçalhos não coincidem com o formato esperado: CPF;NOME;NASCIMENTO;TELEFONE;SEXO;EMAIL;CEP;ESTADO;CIDADE;BAIRRO;COMPLEMENTO;LOGRADOURO;NUMERO;OPERACAO;PLANO'], 400);
					}
						
					$lineNumber						= 0;
				
					while (($row = fgetcsv($handle, 0, ';')) !== false)
					{
						
						$lineNumber++;

						Log::info("importar_titular", ['linha,' => $lineNumber]);

							// Mapeia os dados para os cabeçalhos
						$row 			= array_map(fn($value) => mb_convert_encoding($value, 'UTF-8', 'auto'), $row);
						$data 			= array_combine($headers, $row);
						$data['LINHA'] 	= $lineNumber; 
							// Valida a linha
						$validator = Validator::make($data, [
								'CPF' 			=> ['required', 'cpf_valido'], // CPF válido conforme Receita Federal
								'NOME' 			=> ['required', 'string', 'min:3'],
								'NASCIMENTO' 	=> ['required', 'date_format:d/m/Y'],
								'TELEFONE' 		=> ['required', 'string', 'min:8'],
								'SEXO' 			=> ['required', Rule::in(['M', 'F'])],
								'EMAIL' 		=> ['nullable'],
								'CEP' 			=> ['required', 'string', 'max:10'],
								'ESTADO' 		=> ['nullable', 'string', 'size:2'], // Estado com 2 caracteres
								'CIDADE' 		=> ['nullable', 'string'],
								'BAIRRO' 		=> ['nullable', 'string'],
								'COMPLEMENTO' 	=> ['nullable', 'string'],
								'LOGRADOURO' 	=> ['nullable', 'string'],
								'NUMERO' 		=> ['nullable', 'string'],
								'OPERACAO' 		=> ['required',  Rule::in(['I', 'E'])],
								'PLANO' 		=> ['required', 'exists:planos,nome'],
							]);

						if ($validator->fails()) {
							$data['MENSAGEM']		= Cas::getMessageValidTexto($validator->errors());	
							$errors[] 				= (object) $data;
						} else {
							$titulares[]     		= (object) $data;
						}
					}

					fclose($handle);

				}
			} catch (Exception $e) {
				return response()->json(['error' => 'Ocorreu erro na tentativa de enviar o arquivo'], 400);
			}
		}

		//DB::beginTransaction();
		$atualizados 										= array();
		
		Log::info("importar_titular", ['titulares,' => count($titulares) ]);
		
		foreach($titulares as $titular)
		{
			
			Log::info("importar_titular", ['titular,' => $titular ]);
			
			$cliente 										= Cas::storeClienteTitular($titular);
			$operacao                                       = 'N';
			
			if ($titular->OPERACAO == 'I')
			{
				$plano                     					= Plano::select('id','nome','preco')
																	->where('nome','=',$titular->PLANO)
																	->first();
						
				if (isset($plano->id))
				{
					$rbeneficiario                           = \App\Models\Beneficiario::where('contrato_id','=',$request->contrato_id)
																					   ->where('cliente_id','=',$cliente->id)
																					   ->first();
								
					if (!isset($rbeneficiario->id))
					{
						$rbeneficiario 						= new \App\Models\Beneficiario();
						$rbeneficiario->contrato_id			= $request->contrato_id;
						$rbeneficiario->cliente_id			= $cliente->id;
						$rbeneficiario->vigencia_inicio		= date('Y-m-d');
						$operacao							= 'I';
					} else {
						if (!$rbeneficiario->ativo)
						{
							$rbeneficiario->vigencia_inicio	= date('Y-m-d');
							$operacao						= 'A';
						}
						if ($rbeneficiario->plano_id == $plano->id)
						{
							$dependentes 					= DB::connection('mysql')
																->table('beneficiarios')
																->where('parent_id', '=',$rbeneficiario->id)
																->where('contrato_id','=',$request->contrato_id)
																->where('tipo','=','D')
																->update([
																	'ativo' 			=> true,
																	'desc_status'		=> 'ATIVO',
																	'vigencia_inicio'	=> date('Y-m-d'),
																	'vigencia_fim'		=> '2999-12-31'
																]);
						} else {
							$operacao							= 'A';
							$dependentes 						= DB::connection('mysql')
																	->table('beneficiarios')
																	->where('parent_id', '=',$rbeneficiario->id)
																	->where('contrato_id','=',$request->contrato_id)
																	->where('tipo','=','D')
																	->update([
																		'ativo' 			=> false,
																		'desc_status'		=> 'INATIVO',
																		'vigencia_fim'		=> date('Y-m-d')
																	]);
						}
					}
					$rbeneficiario->vigencia_fim			= '2999-12-31';
					$rbeneficiario->idcartao				= 0;
					$rbeneficiario->statuscartao			= true;
					$rbeneficiario->desc_status				= "ATIVO";
					$rbeneficiario->codonix					= "";
					$rbeneficiario->numerocartao			= "";
					$rbeneficiario->data_inicio_associacao	= null;
					$rbeneficiario->data_vencimento			= null;
					$rbeneficiario->tipo_usuario			= "TITULAR";
					$rbeneficiario->tipo					= 'T';
					$rbeneficiario->ativo					= true;
					$rbeneficiario->parent_id				= 0;
					$rbeneficiario->plano_id 				= $plano->id;						
					if ($rbeneficiario->save())
					{
						//DB::commit();
						Log::info("beneficiario", ['beneficiario,' => $rbeneficiario]);
					}
				} else {
					$operacao                               = 'P';
				}
			} else {
				$rbeneficiario                              = \App\Models\Beneficiario::where('contrato_id','=',$request->contrato_id)
																					  ->where('cliente_id','=',$cliente->id)
																					  ->first();
								
				if (isset($rbeneficiario->id))
				{
					$parent_id 								= $rbeneficiario->parent_id;
					$rbeneficiario->vigencia_fim			= date('Y-m-d');
					$rbeneficiario->desc_status				= "INATIVO";
					$rbeneficiario->ativo					= false;
					if ($rbeneficiario->save())
					{
						$operacao							= 'E';
						$dependentes 						= DB::connection('mysql')
															->table('beneficiarios')
															->where('parent_id', '=',$rbeneficiario->id)
															->where('contrato_id','=',$request->contrato_id)
															->where('tipo','=','D')
															->update([
																'ativo' 			=> false,
																'desc_status'		=> 'INATIVO',
																'vigencia_fim'		=> date('Y-m-d')
															]);
						//DB::commit();		
						Log::info("beneficiario", ['beneficiario,' => $rbeneficiario]);						
					}
				}
			}
			$titular->OPERACAO								= $operacao;
			if (isset($rbeneficiario->id))
			{
				$titular->ID 								= $rbeneficiario->id;
			} else {
				$titular->ID 								= 0;
			}
			$atualizados[]									= $titular;
		}
		
		$tbeneficiarios                           	= \App\Models\Beneficiario::with('plano')
																			 ->where('contrato_id','=',$request->contrato_id)
																			 ->where('tipo','=','T')
																			 ->where('ativo','=',1)
																			 ->get();
	
		$valor 										= 0;
		foreach($tbeneficiarios as $titular)
		{
			$preco 									= str_replace(",",".",$titular->plano->preco);
			$valor 									= $valor + $preco;
		}
		
		$acontrato 				                    = Contrato::find($request->contrato_id);
                                                                  
		if ((isset($acontrato->id)) and ($acontrato->valor != $valor))
		{
			$acontrato->valor 						= $valor;
			$acontrato->save();
		}
		
		//DB::commit();
		
		$retorno                  					= new stdClass;
		$retorno->erros 							= $errors;
		$retorno->titulares 						= $atualizados;
		return response()->json($retorno, 200);
		
	}
	
	public function importar_titular_lote(Request $request)
    {
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para criar beneficiarios.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'contrato_id' 	=> 'required|exists:contratos,id',
			'beneficiarios'	=> 'required|json', 
		]);
		
		if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$beneficiarios 											= json_decode($request->beneficiarios, true);
        
        if (!is_array($beneficiarios)) 
		{
            return response()->json(['error' => 'Formato inválido de beneficiários'], 422);
        }
		
		Validator::extend('cpf_valido', function ($attribute, $value, $parameters, $validator) {
				return Cas::validarCpf($value);
		});
		
		$contrato               								= \App\Models\Contrato::find($request->contrato_id);
								
		if (!isset($contrato->id))
		{
			 return response()->json(['error' => 'Contrato não encontrado'], 422);
		}
		
		if ($contrato->tipo_vigencia == 'M')
		{
			$vigencia_fim										= Carbon::now('America/Sao_Paulo')->addMonth()->format('Y-m-d');							
		} else {
			$vigencia_fim										= '2999-12-31';	
		}
		
		// Validação em lote dos beneficiários
		$retornos 				= array();
		$beneficiariosValidos 	= array();
		
		foreach($beneficiarios as $index => $beneficiarioData)
		{
			$validatorBenef = Validator::make($beneficiarioData, [
				'CPF'           => 'required|cpf_valido',
				'NOME'          => 'required|string',
				'NASCIMENTO'    => 'required|date_format:d/m/Y',
				'TELEFONE'      => 'nullable|string|min:8',
				'SEXO'          => 'nullable|string|in:M,F',
				'EMAIL'         => 'nullable|email',
				'CEP'           => 'nullable|string|max:10',
				'ESTADO'        => 'nullable|string|size:2',
				'CIDADE'        => 'nullable|string',
				'BAIRRO'        => 'nullable|string',
				'LOGRADOURO'    => 'nullable|string',
				'NUMERO'        => 'nullable|string',
				'COMPLEMENTO'   => 'nullable|string',
				'OPERACAO'      => 'nullable|string|in:I,E',
				'PLANO'         => 'nullable|string',
			]);
			
			if ($validatorBenef->fails()) 
			{
				$retorno 				= new stdClass;
				$retorno->cpf 			= $beneficiarioData['CPF'] ?? 'N/A';
				$retorno->ok 			= 'N';
				$retorno->mensagem 		= Cas::getMessageValidTexto($validatorBenef->errors());
				$retorno->cliente_id 	= null;
				$retorno->contrato_id	= null;
				$retorno->operacao 		= null;
				$retornos[] 			= $retorno;
			} else {
				$beneficiariosValidos[] = $beneficiarioData;
			}
		}
		
		// Processar beneficiários válidos em lote
		if (empty($beneficiariosValidos)) 
		{
			return response()->json($retornos, 200);
		}
		
		$beneficiariosProcessados 			= Cas::storeClienteTitularLote($beneficiariosValidos);
				
		foreach ($beneficiariosProcessados as $beneficiarioProcessado) 
		{
					
			$retorno 						= new stdClass;
			$retorno->cpf 					= $beneficiarioProcessado['CPF'];
			$retorno->contrato_id			= $request->contrato_id;
			$retorno->operacao 				= $beneficiarioProcessado['OPERACAO'];
						
			if (isset($beneficiarioProcessado['erro'])) 
			{						
				$retorno->cliente_id 		= null;
				$retorno->ok 				= 'N';
				$retorno->mensagem 			= $beneficiarioProcessado['erro'];
				$retornos[] 				= $retorno;
				continue;
			} 
						
			if ($retorno->operacao == 'I')
			{
				$beneficiario               = \App\Models\Beneficiario::where('contrato_id','<>',$request->contrato_id)
																	  ->where('cliente_id','=',$beneficiarioProcessado['cliente_id'])
																	  ->where('ativo','=',1)
																	  ->first();
								
				if (isset($beneficiario->id))
				{
					$retorno->cliente_id 	= $beneficiarioProcessado['cliente_id'];
					$retorno->ok 			= 'N';
					$retorno->mensagem 		= 'Beneficiário ativo no contrato: ' . $beneficiario->contrato_id;
					$retornos[] 			= $retorno;
					continue;
				}
			}
						
			$cacheKey 						= sprintf(
											'contrato_plano:%s:%s',
											$request->contrato_id,
											$beneficiarioProcessado['PLANO']
			);

			// 60 segundos
			$plano 							= Cache::remember($cacheKey, 60, function () use ($request, $beneficiarioProcessado) {
				return \App\Models\ContratoPlano::query()
					->select(['id','plano_id']) // selecione só o necessário
					->where('contrato_id', $request->contrato_id)
					->where('sigla', $beneficiarioProcessado['PLANO'])
					->first();
			});

			if (!isset($plano->id))
			{
				$retorno->ok 				= 'N';
				$retorno->mensagem 			= 'Plano não encontrado. Sigla:' . $beneficiarioProcessado['PLANO'];
				$retornos[] 				= $retorno;
				continue;
			}
						
			$beneficiario                   = \App\Models\Beneficiario::where('contrato_id','=',$request->contrato_id)
																	  ->where('cliente_id','=',$beneficiarioProcessado['cliente_id'])
																	  ->first();
									
			if (isset($beneficiario->id))
			{
				$beneficiario_id 			= $beneficiario->id;
			} else {
				$beneficiario_id			=  0;
			}
			
			$retorno->cliente_id 			= $beneficiarioProcessado['cliente_id'];
			$retorno->ok 					= 'S';
			
			if ($retorno->operacao == 'I')
			{								
				if  ($beneficiario_id > 0) 
				{	
					if ($beneficiario->ativo == 0) // Não está ativo
					{
						$beneficiario->plano_id 		= $plano->plano_id;	
						$beneficiario->vigencia_inicio	= date('Y-m-d');							
						$beneficiario->vigencia_fim		= $vigencia_fim;
						$beneficiario->desc_status		= "ATIVO";
						$beneficiario->ativo			= true;
								
						if ($beneficiario->save())
						{
							$dependentes 				= DB::connection('mysql')
															->table('beneficiarios')
															->where('parent_id', '=',$beneficiario->id)
															->where('contrato_id','=',$request->contrato_id)
															->where('tipo','=','D')
															->update([
																'plano_id'			=> $plano->plano_id,
																'ativo' 			=> true,
																'desc_status'		=> 'ATIVO',
																'vigencia_inicio'	=> date('Y-m-d'),
																'vigencia_fim'		=> $vigencia_fim
															]);
						}									
					} else {
						$beneficiario->plano_id 		= $plano->plano_id;	
						$beneficiario->vigencia_fim		= $vigencia_fim;							
						if ($beneficiario->save())
						{
							$dependentes 				= DB::connection('mysql')
															->table('beneficiarios')
															->where('parent_id', '=',$beneficiario->id)
															->where('contrato_id','=',$request->contrato_id)
															->where('tipo','=','D')
															->where('ativo','=',1)
															->update([
																'plano_id'			=> $plano->plano_id,
																'vigencia_fim'		=> $vigencia_fim
															]);
						}
																
					}
				} else {
					$beneficiario 						= new \App\Models\Beneficiario();
					$beneficiario->contrato_id			= $request->contrato_id;
					$beneficiario->cliente_id			= $beneficiarioProcessado['cliente_id'];
					$beneficiario->vigencia_inicio		= date('Y-m-d');
					$beneficiario->vigencia_fim			= $vigencia_fim;
					$beneficiario->idcartao				= 0;
					$beneficiario->statuscartao			= true;
					$beneficiario->desc_status			= "ATIVO";
					$beneficiario->codonix				= "";
					$beneficiario->numerocartao			= "";
					$beneficiario->data_inicio_associacao= null;
					$beneficiario->data_vencimento		= null;
					$beneficiario->tipo_usuario			= "TITULAR";
					$beneficiario->tipo					= 'T';
					$beneficiario->ativo				= true;
					$beneficiario->parent_id			= 0;
					$beneficiario->plano_id 			= $plano->plano_id;				
					$beneficiario->save();
				}
				$retorno->mensagem 						= 'Beneficiário ativado com sucesso';
			} else {
				if  ($beneficiario_id > 0) 
				{
					if ($beneficiario->ativo == 1)
					{
						$beneficiario->vigencia_fim		= date('Y-m-d');
						$beneficiario->desc_status		= "INATIVO";
						$beneficiario->ativo			= false;
						if ($beneficiario->save())
						{
							$dependentes 				= DB::connection('mysql')
														->table('beneficiarios')
														->where('parent_id', '=',$beneficiario->id)
														->where('contrato_id','=',$request->contrato_id)
														->where('tipo','=','D')
														->update([
															'ativo' 			=> false,
															'desc_status'		=> 'INATIVO',
															'vigencia_fim'		=> date('Y-m-d')
														]);
						}
					}
				}
				$retorno->mensagem 						= 'Beneficiário inativado com sucesso';
			}
			$retornos[] 								= $retorno;
		}	
		
		$tbeneficiarios                           		= \App\Models\Beneficiario::with('plano')
																			 ->where('contrato_id','=',$request->contrato_id)
																			 ->where('tipo','=','T')
																			 ->where('ativo','=',1)
																			 ->get();
	
		$valor 											= 0;
		foreach($tbeneficiarios as $titular)
		{
			$preco 										= str_replace(",",".",$titular->plano->preco);
			$valor 										= $valor + $preco;
		}
		
		$acontrato 				                   	 	= \App\Models\Contrato::find($request->contrato_id);
                                                                  
		if ((isset($acontrato->id)) and ($acontrato->valor != $valor))
		{
			$acontrato->valor 							= $valor;
			$acontrato->save();
		}
		
		return response()->json($retornos, 200);
	}
	
	public function storeUpdate(Request $request)
    {
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar beneficiarios.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'tipo'          	=> 'required|string|max:1|in:T,D',
		    'contrato_id' 		=> 'required|exists:contratos,id',
            'cpfcnpj'       	=> 'required|string|max:20',
            'nome'          	=> 'required|string|max:100',
			'sexo'            	=> 'required|string|max:1|in:M,F',
			'data_nascimento' 	=> 'required|date',
            'telefone'      	=> 'required|string|max:15',
            'email'         	=> 'required|string|max:200|email',
            'cep'           	=> 'required|string|max:9',
            'logradouro'    	=> 'required|string|max:100',
            'numero'        	=> 'required|string|max:20',
            'complemento'   	=> 'nullable|string|max:100',
            'bairro'        	=> 'required|string|max:100',
            'cidade'        	=> 'required|string|max:100',
            'estado'        	=> 'required|string|max:2'
        ]);
		
        if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		if ($request->tipo =='D')
		{
			$validator = Validator::make($request->all(), [
				'parent_id'          => 'required|exists:beneficiarios,id',
				'parentesco_id'		 => 'required|exists:parentescos,id',
			]);
			
			if ($validator->fails()) 
			{
				return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
			}
			if (($request->parentesco_id == 3) or ($request->parentesco_id == 6))
			{
				$idade 				    			= Carbon::createFromDate($request->data_nascimento)->age;  
				if ($idade > 21)
				{
					return response()->json(['error' => 'Irmãos e Netos não podem ser maior que 21 anos'], 404);
				}
			}
		} else {
			$request->parent_id						= 0;
		}
		
		$contrato                              		= Contrato::find($request->contrato_id);
		
		if (!isset($contrato->id))
		{
			return response()->json(['error' => 'Contrato do Beneficiário não encontrado'], 422);
		}
		
		if ($contrato->tipo == 'J')
		{
			if ($request->tipo =='T')
			{
				$validator = Validator::make($request->all(), [
					'plano_id'          			=> 'required|exists:planos,id',
				]);	
				if ($validator->fails()) 
				{
					return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
				}
			} else {
				$request->plano_id					= 0;
			}
		}
		
		if (!isset($request->plano_id))
		{
			$request->plano_id						= $contrato->plano_id;
		}
		
		if (!isset($request->id))
		{
			$request->id							= 0;
		}
		
		$payload								= (object) $request->all();
		$payload->tipo 							= 'F';
		$cliente 								= Cas::storeUpdateCliente($payload);
													
		$jaexiste                           	= Beneficiario::where('contrato_id','=',$request->contrato_id)
															  ->where('cliente_id','=',$cliente->id)
															  ->where('id','<>',$request->id)
															  ->first();
		if (isset($jaexiste->id))
		{
			return response()->json(['error' => 'Já existe o beneficiário com este CPF cadastrado neste contrato'], 422);
		}
		
		
		$jaexiste  								= DB::table('beneficiarios')		
													->select('contratos.id')
													->join('contratos','beneficiarios.contrato_id','=', 'contratos.id')
													->where('contratos.status','=','active')
													->where('beneficiarios.cliente_id','=',$cliente->id)
													->where('beneficiarios.desc_status','=','ATIVO')
													->where('beneficiarios.id','<>',$request->id)
													->first();
													
		if (isset($jaexiste->id))
		{
			return response()->json(['error' => 'Já existe o beneficiário com este CPF cadastrado no contrato: '. $jaexiste->id], 422);
		}											
		
		DB::beginTransaction();
		
		$beneficiario                           = Beneficiario::find($request->id);

		if (!isset($beneficiario->id))
		{
			$beneficiario						= new \App\Models\Beneficiario();
			$beneficiario->contrato_id 			= $request->contrato_id;
			$beneficiario->cliente_id			= $cliente->id;
			$beneficiario->vigencia_inicio		= date('Y-m-d');
			$beneficiario->vigencia_fim			= '2099-12-31';
			$beneficiario->ativo				= true;
			$beneficiario->desc_status			= 'ATIVO';
			$beneficiario->parent_id			= 0;
		}
		$beneficiario->tipo						= $request->tipo;
		if ($request->tipo == 'T')
		{
			$beneficiario->tipo_usuario			= 'TITULAR';
		} else {
			$beneficiario->tipo_usuario			= 'DEPENDENTE';
			$beneficiario->parentesco_id 		= $request->parentesco_id;
		}
		$beneficiario->parent_id				= $request->parent_id;
		$beneficiario->plano_id                 = $request->plano_id;
		if ($beneficiario->save())
		{
			DB::commit();
			return response()->json($cliente->id, 200);
		} else {
			DB::rollBack();
			$mensagem 							= 'Ocorreu erro na tentativa de registrar o Beneficiario. Favor entrar em contato com o suporte';
			return response()->json(['error' => $mensagem], 404);
		}
					
	}
	
	public function ativarDesativarProdutos(Request $request)
    {
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar beneficiarios.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'beneficiario_id'       => 'required|exists:beneficiarios,id',
			'ativar'				=> 'required|boolean',
			'produtos.*.id'			=> 'required|exists:produtos,id',
			
        ]);
		
		if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$payload									= (object) $request->all();
		$produtos 									=  Cas::ativarDesativarProdutos($payload);
		
		return response()->json($produtos, 200);
	}
	
	public function ativarDesativarBeneficiarios(Request $request)
    {
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar beneficiarios.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'produto_id'       		=> 'required|exists:produtos,id',
			'ativar'				=> 'required|boolean',
			'beneficiarios.*.id'	=> 'required|exists:beneficiarios,id',
			
        ]);
		
		if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$payload									= (object) $request->all();
		$beneficiarios 								= array();
		
		foreach ($payload->beneficiarios as $beneficiario)
		{
			if (!is_object($beneficiario)) 
			{
				$beneficiario 						= (object) $beneficiario;
			}
			
			$ppayload                  				= new stdClass;
			$ppayload->ativar 						= $payload->ativar;
			$ppayload->beneficiario_id 				= $beneficiario->id;
			$ppayload->produtos						= array();
			$pproduto                  				= new stdClass;
			$pproduto->id 							= $payload->produto_id;
			$ppayload->produtos[]					= $pproduto;
			$ativarDesativar 						= Cas::ativarDesativarProdutos($ppayload);
			
			$retorno                  				= new stdClass;
			$retorno->id 							= $beneficiario->id;
			if (isset($ativarDesativar[0]->ativacao))
			{
				$retorno->ok 						= 'S';
				$retorno->ativar 					= $payload->ativar;
				$retorno->produto_id 				= $ativarDesativar[0]->id;
				$retorno->ativacao 					= $ativarDesativar[0]->ativacao;
			} else {
				$retorno->ok 						= 'N';
			} 
			$retorno->mensagem 						= $ativarDesativar[0]->mensagem;
			$beneficiarios[]						= $retorno;
		}
		
		return response()->json($beneficiarios, 200);
	}
	
	public function gerarLinkMagico(Request $request)
    {
		
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para gerar link magico.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'beneficiario_id'       => 'required|exists:beneficiarios,id',
			'produto_id'			=> 'required|exists:produtos,id',
			
        ]);
		
		if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$beneficiarioproduto  					= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$request->beneficiario_id)
																				 ->where('produto_id','=',$request->produto_id)
																				  ->first();														 
		if ((!isset($beneficiarioproduto->id)) or ($beneficiarioproduto->ativacao==0))
		{
			 return response()->json(['error' => 'Beneficiário não ativado'], 422);
		}
		
		$ipAddress 								= $request->ip();
		 
		$magiclink 								= Cas::gerarLinkMagico($request->beneficiario_id, $request->produto_id, $ipAddress);
		
		return response()->json($magiclink, 200);
	}

	public function inativarBeneficiarioComParcelasVencidas(Request $request)
    {
		$dias              						= $request->input('dias', 9);
		$inativar 								= Cas::inativarBeneficiarioComParcelasVencidas($dias);
		return response()->json($inativar, 200);
	}
	
	public function ativarBeneficiarioContratosValidos(Request $request)
    {
		$ativar 								= Cas::ativarBeneficiarioContratosValidos();
		return response()->json($ativar, 200);
	}
	
	public function enviarMensagem(Request $request)
	{
		$numero              					= $request->input('numero', 0);
		$mensagem              					= $request->input('mensagem', '');
		$envio 									= ChatHot::enviarMensagemChatHot($numero, $mensagem);
		return response()->json($envio, 200);
	}
	
	public function listar_mensagem(Request $request)
    {
        if ((!$request->user()->tokenCan('view.beneficiarios')) and (!$request->user()->tokenCan('view.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar beneficiários.'], 403);
        }
		
		
		$limite              					= $request->input('limite', 20);
		$situacao              					= $request->input('situacao', 'Não enviada');
		$data_inicio              				= $request->input('data_inicio', '2000-01-01');
		$data_fim              					= $request->input('data_fim', date('Y-m-d'));
		$orderby             					= $request->input('orderby', 'whatsapp_mensagens.id');
		$direction          					= $request->input('direction', 'desc');
		
		if ($orderby =='id')
		{
			$orderby             				= 'whatsapp_mensagens.id';
		}
		
		if ($situacao == 'Não enviada')
		{
			$comparacao 						= "<>";
			$statscode							= 200;
		} else {
			$comparacao 						= "=";
			$statscode							= 200;
		}
		
		$payload								= (object) $request->all();
		$query 									= DB::table('whatsapp_mensagens')
													->join('beneficiarios', 'whatsapp_mensagens.beneficiario_id', '=', 'beneficiarios.id')
													->join('clientes','beneficiarios.cliente_id', '=', 'clientes.id')
													->where('whatsapp_mensagens.statcode',$comparacao, $statscode)
													->where('whatsapp_mensagens.created_at','>=', $data_inicio)
													->where('whatsapp_mensagens.created_at','<=', $data_fim)
													->select('whatsapp_mensagens.id',
													         'whatsapp_mensagens.mensagem',
															 'whatsapp_mensagens.statcode',
															 'whatsapp_mensagens.arquivo',
															 'beneficiarios.contrato_id',
															 'clientes.cpfcnpj',
															 'clientes.nome as cliente',
															 'clientes.telefone',
															 'beneficiarios.tipo_usuario',
															 'whatsapp_mensagens.created_at'
															);


		if (isset($payload->campos)) 
		{
			$query = Cas::montar_filtro($query, $payload);
		}
		// Ordenação
		$query->orderBy($orderby, $direction);

		// Executando a query
        $mensagens							= $query->paginate($limite);

        $mensagens->getCollection()->transform(function ($mensagem) 
        {

			if ($mensagem->statcode == 200)
			{
				$mensagem->situacao 		= 'Enviada';
			} else {
				$mensagem->situacao 		= 'Não enviada';
			}
			return $mensagem;
	    });
        return response()->json($mensagens, 200);
	}
	
	public function enviar_mensagem_whatsapp(Request $request, $id)
	{
		$wmensagem                   	 			= \App\Models\WhatsappMensagem::find($id);
		
		if (isset($wmensagem->id))
		{
			 
			$beneficiario           				= \App\Models\Beneficiario::with('cliente')->find($wmensagem->beneficiario_id);
			if (isset($beneficiario->id))
			{
				$payload               				= new stdClass;
				$payload->beneficiario_id 			= $wmensagem->beneficiario_id;
				$payload->numero					= preg_replace('/\D/', '', $beneficiario->cliente->telefone);
				$payload->mensagem					= $wmensagem->mensagem;
				$payload->arquivo					= $wmensagem->arquivo;
				$payload->enviado_por 				= $request->user()->id;
				$payload->token 					= '5519998557120';
				$enviar 							= Cas::chatHotMensagem($payload);
				if ($enviar->ok =='S') 
				{
					return response()->json(true, 200);
				} else {
					return response()->json(['mensagem' => 'Problema na comunicação com o Chat Hot. Mensagem não enviada. Tente novamente!'], 404);
				}
			}
		}
		
		return response()->json(['mensagem' => 'Ocorreu erro na tentativa de enviar a mensagem. Mensagem não enviada'], 404);
	}
	
	public function enviar_mensagem_whatsapp_massa(Request $request)
	{
		
		
		$ids              						= $request->input('ids', array());
		
		foreach ($ids as $id)
		{
			$wmensagem                   	 			= \App\Models\WhatsappMensagem::find($id);
			$envios 									= array();
			
			if (isset($wmensagem->id))
			{
				 
				$beneficiario           				= \App\Models\Beneficiario::with('cliente')->find($wmensagem->beneficiario_id);
				if (isset($beneficiario->id))
				{
					$payload               				= new stdClass;
					$payload->beneficiario_id 			= $wmensagem->beneficiario_id;
					$payload->numero					= preg_replace('/\D/', '', $beneficiario->cliente->telefone);
					$payload->mensagem					= $wmensagem->mensagem;
					$payload->arquivo					= $wmensagem->arquivo;
					$payload->enviado_por 				= $request->user()->id;
					$payload->token 					= '5519998557120';
					$enviar 							= Cas::chatHotMensagem($payload);
					if ($enviar->ok =='S') 
					{
						$envios[]						= $id;
					}
				}
			}
		}
		
		return response()->json($envios, 200);
	}
	
	public function inativar_todos(Request $request, $id)
	{
		
		if ((!$request->user()->tokenCan('edit.beneficiarios')) and (!$request->user()->tokenCan('edit.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado.'], 403);
        }
		
		$beneficiarios 									= DB::table('beneficiarios')
															->where('contrato_id', $id)
															->where('ativo','=',1)
															->whereDate('vigencia_fim', '<', date('Y-m-d')) 
															->update(['ativo' 		 => 0,
																	  'desc_status'	 => 'INATIVO'
																	]);
																	
		
		$tbeneficiarios                           		= \App\Models\Beneficiario::with('plano')
																			 ->where('contrato_id','=',$id)
																			 ->where('tipo','=','T')
																			 ->where('ativo','=',1)
																			 ->get();
																			 
		$valor 											= 0;
		foreach($tbeneficiarios as $titular)
		{
			$preco 										= str_replace(",",".",$titular->plano->preco);
			$valor 										= $valor + $preco;
		}
		
		$acontrato 				                   	 	= \App\Models\Contrato::find($id);
                                                                  
		if ((isset($acontrato->id)) and ($acontrato->valor != $valor))
		{
			$acontrato->valor 							= $valor;
			$acontrato->save();
		}
		
		return response()->json($beneficiarios, 200);															
	}
	
	
}
