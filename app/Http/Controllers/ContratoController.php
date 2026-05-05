<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Contrato;
use App\Models\Beneficiario;
use App\Models\Parcela;
use App\Models\Plano;
use App\Helpers\CelCash;
use App\Helpers\ChatHot;
use App\Helpers\Cas;
use App\Jobs\CelCashParcelasAvulsaJob;
use Carbon\Carbon;
use stdClass;
use DB;

class ContratoController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar contratos.'], 403);
        }

		$vendedor								= Cas::obterIDVendedor($request->user()->id);	
		
        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'contratos.id');
		$direction          					= $request->input('direction', 'asc');
        $conteudo            					= $request->input('conteudo', '');
        $situacao            					= $request->input('situacao', '');

        $query									= DB::connection('mysql')
														->table('contratos')
														->select(
															     'contratos.id',
                                                                 'contratos.galaxPayId',
                                                                 'contratos.valor',
																 'contratos.taxa_ativacao',
                                                                 'contratos.status',
																 'contratos.contractpdf',
                                                                 'contratos.firstPayDayDate',
																 'contratos.tipo_vigencia',
                                                                 'clientes.cpfcnpj',
                                                                 'clientes.nome as cliente',
                                                                 'planos.nome as plano',
                                                                 'periodicidades.nome as periodicidade',
                                                                 'situacoes.nome as situacao'
														        )
                                                        ->where(function ($query) use ($situacao) {
                                                                    if ($situacao != "")
                                                                    {
                                                                        $query->where('contratos.status', '=', "$situacao");
                                                                    }
                                                                 })
														->where(function ($query) use ($vendedor) {
                                                              if ($vendedor->vendedor_id > 0)
															  {
																   $query->where('contratos.vendedor_id', '=', $vendedor->vendedor_id);
															  }																  
                                                         })
                                                        ->where(function ($query) use ($conteudo) {
                                                            if ($conteudo != "")
                                                            {
                                                                $query->where('clientes.nome', 'like', "$conteudo%");
                                                                $query->orWhere('planos.nome', 'like', "$conteudo%");
                                                                $query->orWhere('contratos.galaxPayId', '=', "$conteudo");
                                                                $query->orWhere('contratos.id', '=', "$conteudo");
                                                                $query->orWhere('clientes.cpfcnpj', '=', "$conteudo%");
                                                            }
                                                         })
														->leftJoin('clientes',	    'contratos.cliente_id', 	'=', 'clientes.id')
														->leftJoin('planos',	    'contratos.plano_id',       '=', 'planos.id')
                                                        ->leftJoin('situacoes',	    'contratos.situacao_id',    '=', 'situacoes.id')
														->leftJoin('periodicidades','planos.periodicidade_id',  '=', 'periodicidades.id');

        $query->orderBy($orderby,$direction);
		
        $contratos								= $query->paginate($limite);

        $contratos->getCollection()->transform(function ($contrato) 
        {
            $ccontrato                          = \App\Models\Contrato::find($contrato->id);

            if ($ccontrato->beneficiarios()->exists()) 
            {
                $contrato->podeexcluir 			= 0;
            }  else {
                if ($ccontrato->parcelas()->exists()) 
                {
                    $contrato->podeexcluir 		= 0;
                } else {
                    $contrato->podeexcluir 		= 1;
                }
            }  
			$contrato->contractpdf				= Cas::nulltoSpace($contrato->contractpdf);
            $contrato->cpfcnpj                  = Cas::formatCnpjCpf($contrato->cpfcnpj);     
            $contrato->csituacao                = Cas::obterSituacaoContrato($contrato->status); 
			$contrato->podecarne             	= Cas::podecarneContrato($contrato->status,$contrato->id);			
            return $contrato;
         });
                    
         return response()->json($contratos, 200);

    }
	
	public function filtro(Request $request)
    {
        if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar contratos.'], 403);
        }


		$vendedor								= Cas::obterIDVendedor($request->user()->id);	

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'contratos.id');
		$direction          					= $request->input('direction', 'asc');
       
	    $payload								= (object) $request->all();
		

        $query									= DB::connection('mysql')
														->table('contratos')
														->select(
															     'contratos.id',
                                                                 'contratos.galaxPayId',
                                                                 'contratos.valor',
																 'contratos.taxa_ativacao',
                                                                 'contratos.status',
																 'contratos.situacao_pagto',
																 'contratos.tipo',
																 'contratos.contractpdf',
																 'contratos.contractacceptedAt',
																 'contratos.mainPaymentMethodId',
																 'contratos.paymentLink',
                                                                 'contratos.firstPayDayDate',
																 'contratos.avulso',
																 'contratos.tipo_vigencia',
                                                                 'clientes.cpfcnpj',
																 'vendedores.nome as vendedor',
                                                                 'clientes.nome as cliente',
                                                                 'planos.nome as plano',
																 'planos.qtde_beneficiarios',
																 'planos.formapagamento',
                                                                 'periodicidades.nome as periodicidade',
                                                                 'situacoes.nome as situacao'
														        );
																
		if (isset($payload->campos))
		{
			$query                            	= Cas::montar_filtro($query, $payload);
		}
	
		if ($vendedor->vendedor_id > 0)
		{
			$query->where('vendedor_id','=',$vendedor->vendedor_id);
		}
														 
	    $query->leftJoin('clientes',	    'contratos.cliente_id', 	'=', 'clientes.id')
		      ->leftJoin('planos',	    	'contratos.plano_id',       '=', 'planos.id')
              ->leftJoin('situacoes',	    'contratos.situacao_id',    '=', 'situacoes.id')
			  ->leftJoin('vendedores',	    'contratos.vendedor_id',    '=', 'vendedores.id')
		      ->leftJoin('periodicidades',	'planos.periodicidade_id',  '=', 'periodicidades.id');

        $query->orderBy($orderby,$direction);
		
        $contratos								= $query->paginate($limite);

        $contratos->getCollection()->transform(function ($contrato) 
        {
            $ccontrato                          = Contrato::find($contrato->id);

            if ($ccontrato->beneficiarios()->exists()) 
            {
                $contrato->podeexcluir 			= 'N';
            }  else {
                if ($ccontrato->parcelas()->exists()) 
                {
                    $contrato->podeexcluir 		= 'N';
                } else {
                    $contrato->podeexcluir 		= 'S';
                }
            }  
			

			$contrato->contractpdf					= Cas::nulltoSpace($contrato->contractpdf);
            $contrato->cpfcnpj                 	 	= Cas::formatCnpjCpf($contrato->cpfcnpj);     
            $contrato->csituacao                	= Cas::obterSituacaoContrato($contrato->status);    
			$contrato->podecarne             		= Cas::podecarneContrato($contrato->status,$contrato->id);	
			
			if ($contrato->csituacao  == 'Ativo')
			{
				if ($contrato->situacao_pagto =='A')
				{
					$contrato->situacao_pagto 		= 'Adimplente';
				} else {
					if ($contrato->situacao_pagto =='I')
					{
						$contrato->situacao_pagto	= 'Inadimplente';
					} else {
						$contrato->situacao_pagto   = 'Indefinido';
					}
				}
			} else {
				$contrato->situacao_pagto			= 'Cancelado';
			}
			
			if ($contrato->status == 'canceled')
			{
				$contrato->podeeditar 			= 'N';	
			} else {
				$contrato->podeeditar 			= Cas::podeeditarContrato($contrato->status,$contrato->id);	
			}	
			
			if (($contrato->tipo == 'F') and (is_null($contrato->contractacceptedAt)) and ($contrato->csituacao=='Ativo'))
			{				
				$contrato->linkassinatura 		= $contrato->paymentLink;
			} else {
				if (($contrato->tipo == 'F') and ($contrato->avulso == 'S') and (is_null($contrato->contractacceptedAt)) and ($contrato->csituacao=='Registrado'))
				{
					$contrato->linkassinatura 	= config('services.cas.crm_url') . "/#/assinatura/" . $contrato->paymentLink;
				} else {
					$contrato->linkassinatura 	= "";
				}
			}
			
			if (($contrato->status == 'closed') and ($contrato->tipo == 'F'))
			{
				$contrato->poderenovar          = 'S';
			} else {
				$contrato->poderenovar          = 'N';
			}
				
			$pvencidas							= DB::table('parcelas')
															->select('id',
																	 'valor',
																	 'nparcela',
																	 'data_vencimento')
															->where('contrato_id', '=',$contrato->id)
															->whereNull('data_pagamento')
															->whereNull('data_baixa')
															->get();
			if (count($pvencidas) > 0)
			{
				$contrato->podenegociar         = 'S';
			} else {
				$contrato->podenegociar         = 'N';
			}
			if (($contrato->avulso == 'S') or ($contrato->csituacao !='Ativo'))
			{
				$contrato->paymentLink			= "";
			}
			$contrato->created_at               = $ccontrato->created_at;
			
			$contrato->planos 					= DB::table('contrato_planos')
															->select('plano_id',
																	 'sigla')
															->where('contrato_id', '=',$contrato->id)
															->get();
            return $contrato;
         });
                    
         return response()->json($contratos, 200);

    }

	public function excel(Request $request)
    {
		if (!$request->user()->tokenCan('view.contratos')) 
        {
            return response()->json(['error' => 'Não autorizado para visualizar contratos.'], 403);
        }
        
		$orderby             					= 'contratos.created_at';
		$direction          					= 'desc';
		
		$vendedor								= Cas::obterIDVendedor($request->user()->id);	    
	    $payload								= (object) $request->all();
		
		$query									= DB::connection('mysql')
														->table('contratos')
														->select(
															     'contratos.id',
																 'contratos.status',
																 'clientes.cpfcnpj',
																 'clientes.nome as cliente',
																 'clientes.telefone',
																 'planos.nome as plano',
																 'contratos.mainPaymentMethodId',
                                                                 'contratos.valor',
																 'contratos.taxa_ativacao',
																 'contratos.tipo',
																 'contratos.contractacceptedAt',
                                                                 'contratos.firstPayDayDate',
																 'contratos.created_at',
																 'contratos.tipo_vigencia',
																 'vendedores.nome as vendedor'
														        );
																
		if (isset($payload->campos))
		{
			$query                            	= Cas::montar_filtro($query, $payload);
		}
	
		if ($vendedor->vendedor_id > 0)
		{
			$query->where('vendedor_id','=',$vendedor->vendedor_id);
		}
														 
	    $query->leftJoin('clientes',	    'contratos.cliente_id', 	'=', 'clientes.id')
		      ->leftJoin('planos',	    	'contratos.plano_id',       '=', 'planos.id')
			  ->leftJoin('vendedores',	    'contratos.vendedor_id',    '=', 'vendedores.id');

        $query->orderBy($orderby,$direction);
		
        $contratos								= $query->get();
		
		return Excel::download(new \App\Exports\ContratosExport($contratos), 'contratos.xlsx');
	}
	
    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.contratos')) {
            return response()->json(['error' => 'Não autorizado para visualizar contratos.'], 403);
        }

        $contrato                               = \App\Models\Contrato::with('cliente:id,tipo,cpfcnpj,nome')->find($id);

        if (!isset($contrato->id))
        {
            return response()->json(['error' => 'Contrato não encontrado.'], 401);
        }
       
		$cdependentes 							= array();
		
        $contrato->plano                        = \App\Models\Plano::with('periodicidade:id,nome,periodicity')
																 ->select('id','nome','formapagamento','periodicidade_id')
																 ->find($contrato->plano_id);
		 														 
		$dependentes                         	= \App\Models\Beneficiario::with('cliente')
																		 ->where('contrato_id','=',$id)
																		 ->where('tipo','=','D')
																		 ->get();
																		 
		foreach ($dependentes as $dependente)
		{
			$reg								= new stdClass();
			$reg->tipo 							= $dependente->tipo;
			$reg->cpf 							= $dependente->cliente->cpfcnpj;
			$reg->nome 							= $dependente->cliente->nome;
			$reg->sexo 							= $dependente->cliente->sexo;
			if (substr_count($dependente->data_nascimento, '-') > 0)
			{
				list($ano,$mes,$dia)             = explode("-",$dependente->data_nascimento);
			} else {
				$ano							= "";
				$mes							= "";
				$dia							= "";
			}
			$reg->nasc                          = $dia. "/". $mes . "/". $ano;
			$reg->parentesco 					= $dependente->parentesco_id;
			$cdependentes[]						= $reg;
		}
		
		$contrato->mensagem						= "";
		$contrato->situacaoc					= "";
		
		$acontrato                   			= \App\Models\Contrato::where('cliente_id','=',$contrato->cliente_id)
															->whereIn('status',array('active','waitingPayment'))
															->first();

		if (isset($acontrato->id))
		{
			$contrato->situacaoc 				= "E";
			$contrato->mensagem 				= 'Já existe o contrato ID ' . $acontrato->id . " ativo para este cliente. Novo contrato não permitido.";
		} else {
			$beneficiario 		   				= \App\Models\Beneficiario::with('contrato')
																	  ->where('cliente_id','=',$contrato->cliente_id)
																	  ->where('ativo','=',1)
																	  ->first();
			if (isset($beneficiario->id))
			{
				if ($beneficiario->tipo =='T')
				{
					$tipo 						= 'Titular';
				} else {
					$tipo 						= 'Dependente';
				}
				if ($beneficiario->contrato->tipo =='F')
				{
					$tipoc 						= 'PFisica';
				} else {
					$tipoc 						= 'PJuridica';
				}
				$contrato->situacaoc 			= "E";
				$contrato->mensagem 			= 'O Cliente é beneficiário ' . $tipo . ' ativo no contrato ID ' . $beneficiario->contrato_id . "  tipo de contrato: " . $tipoc . ". Entre em contato com o financeiro.";
			} else {
				$contratos 						= array();
				$lcontratos                   	= \App\Models\Contrato::where('cliente_id','=',$contrato->cliente_id)
																		  ->where('status','=','canceled')
																		  ->orderBy('created_at','desc')
																		  ->get();

				$inadimplente 					= false;
				
				foreach ($lcontratos as $lcontrato)
				{
					$reg 				        = new stdClass();
					$reg->id					= $lcontrato->id;
					$reg->assinado 				= "";
					$reg->valor                 = $lcontrato->valor;
					$reg->data                  = substr($lcontrato->updated_at,0,10);
					$reg->parcelas 				= array();
					
					if (!is_null($lcontrato->contractacceptedAt))
					{
						list($ano,$mes,$dia) 		= explode("-",substr($lcontrato->contractacceptedAt,0,10));
						$reg->assinado				= $dia . "/" . $mes . "/". $ano . " " . substr($lcontrato->contractacceptedAt,11,05);
					}
					
					$lparcelas 						= \App\Models\Parcela::where('contrato_id','=',$lcontrato->id)
																	   ->orderBy('nparcela','desc') 
																	   ->get();
					
					foreach ($lparcelas as $parcela)
					{
						if (!isset($reg->statusDescription))
						{
							if ($parcela->nparcela == 1)
							{
								$reg->statusDescription 	= $parcela->statusDescription;
								if (Cas::nulltoSpace($parcela->reasonDenied) != "")
								{
									$reg->statusDescription = $reg->statusDescription . " | "	. $parcela->reasonDenied;
								}
							}
						}
						$preg 				    	= new stdClass();
						$preg->id					= $parcela->id;
						$preg->nparcela				= $parcela->nparcela;
						$preg->data_vencimento		= $parcela->data_vencimento;
						$preg->data_pagamento		= $parcela->data_pagamento;
						$preg->data_baixa			= $parcela->data_baixa;
						$preg->valor 				= $parcela->valor;
						$reg->parcelas[]			= $preg;
					}
					
					$contratos[] 					= $reg;
					
					if (\App\Models\Parcela::where('contrato_id','=',$lcontrato->id)
										   ->where('data_pagamento','<>',null)
										   ->where('galaxPayId','>',0)
										   ->count() > 0)
					{
							$inadimplente			= true;
					}
								
				}
					
				$contrato->ccontratos 				= $contratos;
				$contrato->situacaoc 				= "A";	
				if ($inadimplente)
				{
					$contrato->mensagem 			= 'Existe o contrato ID ' . $lcontrato->id . " cancelado por inadimplência para este cliente. Analise os contratos para tomar a descisão.";
				} else {
					if (count($contrato->ccontratos) > 0)
					{
						$contrato->mensagem 		= "Verifique os contratos cancelados.";
					} else {
						$contrato->mensagem 		= 'A situação do cliente foi verificada com sucesso. Novo contrato permitido!';
					}
				}
			}
		}
		
		$contrato->dependentes 						= $cdependentes;
		
        return response()->json($contrato, 200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.contratos')) {
            return response()->json(['error' => 'Não autorizado para criar contratos.'], 403);
        }

        $validator = Validator::make($request->all(), [
			'plano_id' 			=> 'required|exists:planos,id',
            'tipo' 				=> 'required|string|max:1',
            'cliente_id' 		=> 'required|exists:clientes,id',
            'vigencia_inicio'	=> 'required|date',
            'vendedor_id' 		=> 'required|exists:vendedores,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		if (!isset($request->avulso))
		{
			$request->avulso		= "N";
		}

		if (($request->avulso == 'S') and ($request->tipo == 'J'))
		{
			return response()->json(['error' => 'Contrato PJ não pode ser parametrizado com avulso'], 422);
		}
		
		if ($request->avulso == 'S') 
		{
			$plano                                      	= \App\Models\Plano::select('id','formapagamento','clausulas')->find($request->plano_id);
			if (!isset($plano->id))
			{
				return response()->json(['error' => 'Plano não encontrado'], 422);
			}
				
			if ($plano->formapagamento !='boleto')
			{
				return response()->json(['error' => 'Avulso somente permitido para forma de contrato igual a boleto'], 422);
			}
			
			if ((is_null($plano->clausulas)) or ($plano->clausulas ==""))
			{
				return response()->json(['error' => 'Favor informar as cláusulas do contrato no plano'], 422);
			}
		}
		
		if ($request->tipo == 'F')
		{
			$validator = Validator::make($request->all(), [
				'taxa_ativacao' 	=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
				'valor' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			]);
		} 

		if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		if ($request->vigencia_inicio < date('Y-m-d'))
		{
			return response()->json(['error' => 'Vencimento da 1ª Parcela não pode ser menor que a data de hoje.'], 422);
		}
					
		$titulares                  			= array();
		$errors									= array();
		
		if ($request->tipo == 'J')
		{
			if ($file = $request->file('arquivo')) 
			{
				// Adiciona uma regra personalizada para validar CPF
				Validator::extend('cpf_valido', function ($attribute, $value, $parameters, $validator) {
					return Cas::validarCpf($value);
				});
	
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
						
						$lineNumber								= 0;
				
						while (($row = fgetcsv($handle, 0, ';')) !== false)
						{
							$lineNumber++;

							// Mapeia os dados para os cabeçalhos
							$row 			= array_map(fn($value) => mb_convert_encoding($value, 'UTF-8', 'auto'), $row);
							$data 			= array_combine($headers, $row);

							$data['LINHA'] 	= $lineNumber; 

							// Valida a linha
							$validator = Validator::make($data, [
								'CPF' 			=> ['required', 'cpf_valido'], // CPF válido conforme Receita Federal
								'NOME' 			=> ['required', 'string', 'min:3'],
								'NASCIMENTO' 	=> ['required', 'date_format:d/m/Y'],
								'TELEFONE' 		=> ['required', 'string', 'min:8', 'max:20'],
								'SEXO' 			=> ['required', Rule::in(['M', 'F'])],
								'EMAIL' 		=> ['nullable'],
								'CEP' 			=> ['required', 'string', 'max:10'],
								'ESTADO' 		=> ['nullable', 'string', 'size:2'], // Estado com 2 caracteres
								'CIDADE' 		=> ['nullable', 'string'],
								'BAIRRO' 		=> ['nullable', 'string'],
								'COMPLEMENTO' 	=> ['nullable', 'string'],
								'LOGRADOURO' 	=> ['nullable', 'string'],
								'NUMERO' 		=> ['nullable', 'string'],
								'OPERACAO' 		=> ['required',  Rule::in(['I'])],
								'PLANO' 		=> ['required', 'exists:planos,nome'],
							]);

							if ($validator->fails()) {
								$data['MENSAGEM']= Cas::getMessageValidTexto($validator->errors());	
								$errors[] 		 = (object) $data;
							} else {
								$titulares[]     = (object) $data;
							}
						}

						fclose($handle);

						if (!empty($errors)) 
						{
							Log::info("errors", ['errors'	=> $errors ]);
							Log::info("titulares", ['titulares'	=> $titulares ]);
							return response()->json(['error' => $errors, 'titulares' => $titulares],400);
						}
					}
				} catch (Exception $e) {
					return response()->json(['error' => 'Ocorreu erro na tentativa de enviar o arquivo'], 400);
				}
			}
		}
		
        $contrato 				                        = \App\Models\Contrato::where('cliente_id','=',$request->cliente_id)  
																			  ->where('plano_id','=',$request->plano_id)  
																			  ->whereIn('status',array('active','waitingPayment','',null))
																			  ->first();
                                                                  
        if (isset($contrato->id))
        {
            return response()->json(['error' => 'O Cliente não pode ter mais de um contrato ativo para o mesmo plano'], 404);
        }

		
		$plano                                      	= \App\Models\Plano::with('periodicidade:id,nome,periodicity')
																		 ->select('id','formapagamento','periodicidade_id','qtde_beneficiarios')
																		 ->find($request->plano_id);
																		 
																		 
		
		if (!isset($plano->id))
        {
            return response()->json(['error' => 'Plano não encontrado'], 404);
        }
		
		if (($request->avulso == 'N') and ($request->tipo == 'F') and ($plano->formapagamento == 'boleto'))
		{
			return response()->json(['error' => 'Contrato PF em Boleto tem que ser avulso'], 422);
		}																 
																														
		$beneficiarios									= array();
		$titular 										= false;
		
		if (isset($request->beneficiarios))
		{
			foreach($request->beneficiarios as $beneficiario)
			{
					
				$beneficiario 							= json_decode(json_encode($beneficiario));
					
				$cliente                                = Cas::getStoreClienteBeneficiario($beneficiario);
				
				Log::info("cliente", ['cliente'	=> $cliente ]);
				Log::info("beneficiario", ['beneficiario'	=> $beneficiario ]);
							
				$beneficiario->cliente_id 				= $cliente->id;
					
				if (($beneficiario->tipo == 'T') or ($beneficiario->cliente_id == $request->cliente_id))
				{
					$titular							= true;
				}
				
				if ($beneficiario->tipo == 'D')
				{
					if ($beneficiario->parentesco_id == 0)
					{
						return response()->json(['error' => 'É necessário informar o parentesco'], 404);
					}
					
					if (($beneficiario->parentesco_id == 3) or ($beneficiario->parentesco_id == 6))
					{
						$idade 				    		= Carbon::createFromDate($cliente->data_nascimento)->age;  
						if ($idade > 21)
						{
							return response()->json(['error' => 'Irmãos e Netos não podem ser maior que 21 anos'], 404);
						}
					}
				}
				
				$beneficiarios[]						= $beneficiario;
			}
		}
		
		if ($request->tipo == 'F')
		{
			if ((!$titular) and  ($plano->qtde_beneficiarios ==1) and (count($beneficiarios) > 0))
			{
				return response()->json(['error' => 'O plano não permite dependente. Somente um títular'], 404);
			}
		}
		
		DB::beginTransaction();

        $contrato            			                = new \App\Models\Contrato();

        $contrato->tipo                                 = $request->tipo;
        $contrato->cliente_id                           = $request->cliente_id;
		if ($request->tipo == 'F')
		{
			$contrato->plano_id                         = $request->plano_id;
		} else {
			$contrato->plano_id                         = 5;
		}
        $contrato->vigencia_inicio                      = $request->vigencia_inicio;
        $contrato->vigencia_fim                         = '2999-12-31';
        $contrato->vendedor_id                          = $request->vendedor_id;
		if ($request->tipo == 'F')
		{
			$contrato->valor                            = str_replace(",",".",$request->valor);
			$contrato->taxa_ativacao                    = str_replace(",",".",$request->taxa_ativacao);
		} else {
			$contrato->valor                            = 0;
			$contrato->taxa_ativacao                    = 0;
		}
        $contrato->situacao_id                          = 1;
        $contrato->galaxPayId                           = 0;

        $contrato->paymentLink                          = "";
		
		if ($request->tipo == 'F')
		{
			if (isset($plano->formapagamento))
			{
				$contrato->mainPaymentMethodId          = $plano->formapagamento;
			} else {
				$contrato->mainPaymentMethodId          = "";
			}
		} else {
			$contrato->mainPaymentMethodId              = "boleto";
		}
		
        $contrato->status                               = "";
        $contrato->quantity                             = 0;

		if ($request->tipo == 'F')
		{
			if (isset($plano->periodicidade->periodicity))
			{
				$contrato->periodicity                  = $plano->periodicidade->periodicity;
			} else {
				$contrato->periodicity                  = "";
			}
		} else {
			$contrato->periodicity                      = "monthly";
		}
		
        $contrato->firstPayDayDate                      = $request->vigencia_inicio;
        $contrato->additionalInfo                       = "";
        $contrato->paymentMethodBoletofine              = 0;
        $contrato->paymentMethodBoletointerest          = 0;
        $contrato->paymentMethodBoletoinstructions      = "";
        $contrato->paymentMethodBoletodeadlineDays      = 0;
        $contrato->paymentMethodBoletodocumentNumber    = "";
        $contrato->contractname                         = "";
        $contrato->contractdocument                     = "";
        $contrato->contractip                           = "";
        $contrato->contractacceptedAt                   = null;
        $contrato->contractpdf                          = "";
		$contrato->avulso 								= $request->avulso;
		
		if ($request->avulso == 'S')
		{
			$contrato->paymentLink						= Str::random(10);
		}
        
        if ($contrato->save())
		{
			if (isset($request->parcelas))
			{
				foreach($request->parcelas as $parcela)
				{
					$parcela 								= json_decode(json_encode($parcela));
					$rparcela 								= new \App\Models\Parcela();
					$rparcela->contrato_id					= $contrato->id;
					$rparcela->nparcela						= $parcela->nparcela;
					$rparcela->data_vencimento	    		= $parcela->vencimento;
					$rparcela->data_pagamento				= null;
					$rparcela->data_baixa					= null;
					$rparcela->taxa							= 0;
					$rparcela->valor						= str_replace(",",".",$parcela->valor); 
					$rparcela->desconto						= 0;
					$rparcela->juros						= 0;
					$rparcela->valor_pago					= 0;
					$rparcela->galaxPayId					= 0;
					$rparcela->boletobankNumber				= 0;
					$rparcela->payedOutsideGalaxPay			= false;
					$rparcela->statusDate					= null;
					$rparcela->datetimeLastSentToOperator	= null;
					$rparcela->formapagamento				= 'boleto';
					$rparcela->status						= "";
					$rparcela->statusDescription			= "";
					$rparcela->additionalInfo				= "";
					$rparcela->subscriptionMyId				= "";
					$rparcela->boletopdf					= "";
					$rparcela->boletobankLine				= "";
					$rparcela->boletobarCode				= "";
					$rparcela->boletobankEmissor			= "";
					$rparcela->boletobankAgency				= "";
					$rparcela->boletobankAccount			= "";
					$rparcela->pixreference					= "";
					$rparcela->pixqrCode					= "";
					$rparcela->piximage						= "";
					$rparcela->pixpage						= "";
					$rparcela->tid							= "";
					$rparcela->authorizationCode			= "";
					$rparcela->cardOperatorId				= "";
					$rparcela->conciliationOccurrences		= "{}";
					$rparcela->creditCard					= "{}";
					if (!$rparcela->save())
					{
						DB::rollBack();
					}
				}
			}
			
			if ((!$titular) and ($contrato->tipo == 'F'))
			{
				$rbeneficiario 							= new \App\Models\Beneficiario();
                $rbeneficiario->contrato_id				= $contrato->id;
				$rbeneficiario->cliente_id				= $contrato->cliente_id;
				$rbeneficiario->vigencia_inicio			= $contrato->vigencia_inicio;
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
				if (!$rbeneficiario->save())
				{
					DB::rollBack();
				}					
			}
			
			foreach($beneficiarios as $beneficiario)
            {
                $rbeneficiario 							= new \App\Models\Beneficiario();
                $rbeneficiario->contrato_id				= $contrato->id;
				$rbeneficiario->cliente_id				= $beneficiario->cliente_id;
				$rbeneficiario->vigencia_inicio			= $contrato->vigencia_inicio;
				$rbeneficiario->vigencia_fim			= '2999-12-31';
				$rbeneficiario->idcartao				= 0;
				$rbeneficiario->statuscartao			= true;
				$rbeneficiario->desc_status				= "ATIVO";
				$rbeneficiario->codonix					= "";
				$rbeneficiario->numerocartao			= "";
				$rbeneficiario->data_inicio_associacao	= null;
				$rbeneficiario->data_vencimento			= null;
				$rbeneficiario->tipo_usuario			= "";
				
				if ($beneficiario->cliente_id != $contrato->cliente_id)
				{
					$rbeneficiario->tipo				= $beneficiario->tipo;
				} else {
					$rbeneficiario->tipo				= 'T';
				}
				if ($rbeneficiario->tipo == 'T')
				{
					$rbeneficiario->tipo_usuario		= "TITULAR";
					$rbeneficiario->parentesco_id		= 0;
				} else {
					$rbeneficiario->parentesco_id		= $beneficiario->parentesco_id;
					$rbeneficiario->tipo_usuario		= "DEPENDENTE";
				}
				$rbeneficiario->ativo					= true;
				$rbeneficiario->parent_id				= 0;	
				if (!$rbeneficiario->save())
				{
					DB::rollBack();
				}					
	        } 
			if ($contrato->tipo == 'J')
			{
				$valor 												= 0;
				foreach($titulares as $titular)
				{
					$cliente 										= Cas::storeClienteTitular($titular);
					if ($titular->OPERACAO == 'I')
					{
						$plano                     					= Plano::select('id','nome','preco')
																			->where('nome','=',$titular->PLANO)
																			->first();
						
						if (isset($plano->id))
						{	
							$preco 									= str_replace(",",".",$plano->preco);					
							$rbeneficiario 							= new \App\Models\Beneficiario();
							$rbeneficiario->contrato_id				= $contrato->id;
							$rbeneficiario->cliente_id				= $cliente->id;
							$rbeneficiario->vigencia_inicio			= $contrato->vigencia_inicio;
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
								$valor								= $valor + $preco;
							}
						}
					}
				}
				
				$rparcela 									= new \App\Models\Parcela();
				$rparcela->contrato_id						= $contrato->id;
				$rparcela->nparcela							= 1;
				$rparcela->data_vencimento	    			= $contrato->vigencia_inicio;
				$rparcela->data_pagamento					= null;
				$rparcela->data_baixa						= null;
				$rparcela->taxa								= 0;
				$rparcela->valor							= $valor; 
				$rparcela->desconto							= 0;
				$rparcela->juros							= 0;
				$rparcela->valor_pago						= 0;
				$rparcela->galaxPayId						= 0;
				$rparcela->boletobankNumber					= 0;
				$rparcela->payedOutsideGalaxPay				= false;
				$rparcela->statusDate						= null;
				$rparcela->datetimeLastSentToOperator		= null;
				$rparcela->formapagamento					= 'boleto';			
				$rparcela->status							= "";
				$rparcela->statusDescription				= "";
				$rparcela->additionalInfo					= "";
				$rparcela->subscriptionMyId					= "";
				$rparcela->boletopdf						= "";
				$rparcela->boletobankLine					= "";
				$rparcela->boletobarCode					= "";
				$rparcela->boletobankEmissor				= "";
				$rparcela->boletobankAgency					= "";
				$rparcela->boletobankAccount				= "";
				$rparcela->pixreference						= "";
				$rparcela->pixqrCode						= "";
				$rparcela->piximage							= "";
				$rparcela->pixpage							= "";
				$rparcela->tid								= "";
				$rparcela->authorizationCode				= "";
				$rparcela->cardOperatorId					= "";
				$rparcela->conciliationOccurrences			= "{}";
				$rparcela->creditCard						= "{}";
				$rparcela->save();
				
				DB::commit();
				
				$acontrato 				                    = Contrato::find($contrato->id);
                                                                  
				if (isset($acontrato->id))
				{
					
					$acontrato->valor 						= $valor;
					$acontrato->status 						= 'active';
					if ($acontrato->save())
					{
						$mensagem 							= "";
						$charges							= CelCash::storeContratoCharges($rparcela->id);
						if (($charges->ok == 'S') and (isset($charges->Charge)))
						{
							$scharge 						= CelCash::updateContratoWithCharge($charges->Charge);
							if ($scharge->ok == 'S')
							{
								return response()->json($contrato, 200);
							} else {
								$contrato 					= Contrato::find($contrato->id);
								if (isset($contrato->id))
								{
									if ($contrato->delete())
									{
										DB::table('parcelas')
													->where('contrato_id','=',$contrato->id)
													->delete();	
										DB::table('beneficiarios')
													->where('contrato_id','=',$contrato->id)
													->delete();	
									} 
								}
								$mensagem 					= $scharge->mensagem;
							}	
						} else {
							$contrato 						= Contrato::find($contrato->id);
							if (isset($contrato->id))
							{
								if ($contrato->delete())
								{
									DB::table('parcelas')
											->where('contrato_id','=',$contrato->id)
											->delete();	
									DB::table('beneficiarios')
											->where('contrato_id','=',$contrato->id)
											->delete();	
								} 
							}
							$mensagem 							= $charges->mensagem;
						}
						return response()->json(['error' => $mensagem], 404);
					}
				}
			} else {
					
				if ($request->avulso == 'N')
				{
					$ccontrato 									= CelCash::storeContrato($contrato->id);
					
					if ($ccontrato->ok == 'S')
					{
						DB::commit();
						$scontrato 								= CelCash::updateContratoWithSubscription($ccontrato);
						if ($scontrato->ok == 'S')
						{
							$contrato->status 					= $scontrato->status;
							return response()->json($contrato, 200);
						} else {
							$mensagem 							= 'Ocorreu erro na tentativa de atualizar o contrato registrado no CelCash. Favor entrar em contato com o suporte. Mensagem:' . $scontrato->mensagem;
							return response()->json(['error' => $mensagem], 404);
						} 
					} else {
						DB::rollBack();
						$mensagem 								= 'Ocorreu erro na tentativa de registrar o contrato no CelCash. Favor entrar em contato com o suporte. Mensagem:' . $ccontrato->mensagem;
						return response()->json(['error' => $mensagem], 404);
					}
				} else {
					DB::commit();
					return response()->json($contrato, 200);
				}
			}
		} else {
			DB::rollBack();
		}

        return response()->json($contrato, 200);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.contratos')) {
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'motivo_id' 		=> 'required|exists:motivos,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 404);
        }

        $contrato 				                        = \App\Models\Contrato::find($id);
                
        if (!isset($contrato->id))
        {
             return response()->json(['error' => 'Contrato não encontrato'], 404);
        }

		if (($contrato->status == 'active') or ($contrato->status == 'waitingPayment') or ((Cas::nulltoSpace($contrato->status) == '')))
		{
			if ($contrato->tipo != 'F')
			{
				if (\App\Models\Parcela::where('contrato_id','=',$id)->where('data_pagamento','=',null)->where('data_baixa','=',null)->count() > 1)
				{
					return response()->json(['error' => 'Favor baixar ou realizar o pagamento da mensalidade da empresa antes'], 404);
				}
			}
			
			$cancelar 									= true;
			$contrato->status							= 'canceled';
		} else {
			$cancelar 									= false;
		}
		
		if (!isset($request->observacao))
		{
			$request->observacao						= "";
		}
		
        $contrato->motivo_id                          	= $request->motivo_id;
		$contrato->observacao                          	= $request->observacao;
		
		DB::beginTransaction();
		
		Log::info("cancelar", ['cancela' => $cancelar ]);
		
        if ($contrato->save())
		{
			if ($cancelar)
			{
				if ($contrato->tipo == 'F')
				{
					if ($contrato->avulso =='S')
					{
						$parcelas 						= DB::table('parcelas')
															->where('contrato_id', '=', $id)
															->where('data_pagamento','=',null)
															->where('data_baixa','=',null)
															->where('cgalaxPayId','>',0)
															->get();
						foreach ($parcelas as $parcela)
						{
							$cancelar 					= CelCash::cancelCharges($parcela->cgalaxPayId,1,"galaxPayId");
						}
						
					} else {
						$cancelar 						= CelCash::cancelarContrato($id);
						Log::info("cancelar", ['cancelar' => $cancelar ]);
						if ($cancelar->ok != 'S')
						{
							if ((isset($cancelar->mensagem)) and (($cancelar->mensagem == "Nenhuma assinatura encontrada para cancelar.") or ($cancelar->mensagem == "Não encontramos nenhum registro com o dado informado.")))
							{
								Log::info("cancelar", ['cancelar' => $cancelar ]);
							} else {								
								DB::rollBack();
								return response()->json(['error' => 'Ocorreu problema no cancelamento do contrato no CellCash.', 'response' => $cancelar], 404);
							}
						}
					}
				} 
				
				$parcelas 								= DB::table('parcelas')
															->where('contrato_id', '=', $id)
															->where('data_pagamento','=',null)
															->where('data_baixa','=',null)
															->update(['status' 		=> 'cancel',
																	  'data_baixa'	=> date('Y-m-d'),
																	  'statusDate'	=> date('Y-m-d H:m:s')
																	]);
				$beneficiarios 							= DB::table('beneficiarios')
															->where('contrato_id', $id)
															->where('ativo','=',1)
															->update(['ativo' 		 => 0,
																	  'vigencia_fim' => date('Y-m-d'),
																	  'desc_status'	 => 'INATIVO'
																	]);
			}
		}

		DB::commit();
        return response()->json($contrato, 200);
    }

	public function renovar(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.contratos')) {
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }

		$validator = Validator::make($request->all(), [
			'plano_id' 			=> 'required|exists:planos,id',
			'taxa_ativacao' 	=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			'valor' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'vigencia_inicio'	=> 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		if ($request->vigencia_inicio < date('Y-m-d'))
		{
			return response()->json(['error' => 'Vencimento da proxima Parcela não pode ser menor que a data de hoje.'], 422);
		}
		
        $contrato 				                        = \App\Models\Contrato::find($id);
                
        if (!isset($contrato->id))
        {
             return response()->json(['error' => 'Contrato não encontrato'], 404);
        }
		
		if ($contrato->tipo !='F')
		{
			return response()->json(['error' => 'Renovação permitida somente contrato de pessoa fisica'], 404);
		}
		
		if (\App\Models\Parcela::where('contrato_id','=',$id)->count() == 0)
		{
			return response()->json(['error' => 'Contrato não tem parcelas, renovação não permitida'], 404);
		}
		
		if (\App\Models\Parcela::where('contrato_id', '=', $id)->where('data_pagamento','=',null)->where('data_baixa','=',null)->count() > 0)
		{
			return response()->json(['error' => 'Contrato tem parcelas abertas, renovação não permitida'], 404);
		}
				
		$plano 				                        	= \App\Models\Plano::find($request->plano_id);
                
        if (!isset($plano->id))
        {
             return response()->json(['error' => 'Plano não encontrato'], 404);
        }

		if ($plano->formapagamento == 'creditcard')
		{
			return response()->json(['error' => 'Renovação permitida somente para forma de contrato Boleto'], 404);
		}
		
		if (($plano->parcelas ==0) or ($plano->parcelas ==99))
		{
			return response()->json(['error' => 'Renovação permitida somente para planos com número limitado de parcelas'], 404);
		}
		
		if (\App\Models\Beneficiario::where('contrato_id','=',$id)->count() > $plano->qtde_beneficiarios)
		{
			return response()->json(['error' => 'Tem mais beneficiário no contrato do que suportado no plano'], 404);
		}
		
		$parcela 										= \App\Models\Parcela::where('contrato_id','=',$id)
																		   ->orderBy('nparcela','desc')
																		   ->first();
		
		if (!isset($parcela->id))
		{
			$nparcela 									= 1;
		} else {
			$nparcela 									= $parcela->nparcela;
		}
		
		//return response()->json($contrato, 200);
		 
        $contrato->plano_id                          	= $request->plano_id;
		$contrato->vigencia_inicio                      = $request->vigencia_inicio;
        $contrato->vigencia_fim                         = '2999-12-31';
		$contrato->valor                            	= str_replace(",",".",$request->valor);
		$contrato->taxa_ativacao                    	= str_replace(",",".",$request->taxa_ativacao);
        $contrato->situacao_id                          = 1;
        $contrato->galaxPayId                           = 0;
        $contrato->paymentLink                          = "";
		$contrato->mainPaymentMethodId          		= $plano->formapagamento;
        $contrato->status                               = "active";
        $contrato->quantity                             = 0;
		$contrato->periodicity                  		= $plano->periodicidade->periodicity;
        $contrato->firstPayDayDate                      = $request->vigencia_inicio;
        $contrato->additionalInfo                       = "";
        $contrato->paymentMethodBoletofine              = 0;
        $contrato->paymentMethodBoletointerest          = 0;
        $contrato->paymentMethodBoletoinstructions      = "";
        $contrato->paymentMethodBoletodeadlineDays      = 0;
        $contrato->paymentMethodBoletodocumentNumber    = "";
        $contrato->contractname                         = "";
        $contrato->contractdocument                     = "";
        $contrato->contractip                           = "";
		$contrato->avulso 								= "S";

		DB::beginTransaction();
		
        if ($contrato->save())
		{
			$beneficiarios 								= DB::table('beneficiarios')->where('contrato_id', $id)
															->update(['ativo' 		 => 1,
																	  'vigencia_fim' => '2999-12-31',
																	  'desc_status'	 => 'ATIVO'
																	 ]);
																	 
			$vencimento 								= Carbon::createFromFormat('Y-m-d', $request->vigencia_inicio);
			$dataVencimento 							= clone $vencimento;	
			
			for ($i = 1; $i <= $plano->parcelas; $i++) 
			{													 
				$rparcela 								= new \App\Models\Parcela();
				$rparcela->contrato_id					= $contrato->id;
				$rparcela->nparcela						= $nparcela + $i;
				$rparcela->data_vencimento	    		= $dataVencimento->format('Y-m-d');
				$rparcela->data_pagamento				= null;
				$rparcela->data_baixa					= null;
				$rparcela->taxa							= 0;
				
				if ($i ==1)
				{	
					$rparcela->valor					= $contrato->valor + $contrato->taxa_ativacao;
				} else {
					$rparcela->valor					= $contrato->valor;
				}					
				$rparcela->desconto						= 0;
				$rparcela->juros						= 0;
				$rparcela->valor_pago					= 0;
				$rparcela->galaxPayId					= 0;
				$rparcela->boletobankNumber				= 0;
				$rparcela->payedOutsideGalaxPay			= false;
				$rparcela->statusDate					= null;
				$rparcela->datetimeLastSentToOperator	= null;
				$rparcela->status						= "";
				$rparcela->statusDescription			= "";
				$rparcela->additionalInfo				= "";
				$rparcela->subscriptionMyId				= "";
				$rparcela->boletopdf					= "";
				$rparcela->boletobankLine				= "";
				$rparcela->boletobarCode				= "";
				$rparcela->boletobankEmissor			= "";
				$rparcela->boletobankAgency				= "";
				$rparcela->boletobankAccount			= "";
				$rparcela->pixreference					= "";
				$rparcela->pixqrCode					= "";
				$rparcela->piximage						= "";
				$rparcela->pixpage						= "";
				$rparcela->tid							= "";
				$rparcela->authorizationCode			= "";
				$rparcela->cardOperatorId				= "";
				$rparcela->conciliationOccurrences		= "{}";
				$rparcela->formapagamento				= 'boleto';
				$rparcela->creditCard					= "{}";
				$rparcela->save();
				 // Avançar a data de vencimento em um mês para a próxima parcela
				$dataVencimento->addMonth();
			}
			CelCashParcelasAvulsaJob::dispatch($contrato->id)->onQueue('default');
		}

		DB::commit();
        return response()->json($contrato, 200);
    }
	
	public function renovar_confirmar(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.contratos')) {
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }

		$validator = Validator::make($request->all(), [
		    'vendedor_id' 		=> 'required|exists:vendedores,id',
			'plano_id' 			=> 'required|exists:planos,id',
            'vigencia_inicio'	=> 'required|date',
			'dia_vencimento'	=> 'required|integer|digits_between:1,12',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		if ($request->vigencia_inicio < date('Y-m-d'))
		{
			return response()->json(['error' => 'Vencimento da proxima Parcela não pode ser menor que a data de hoje.'], 422);
		}
		
        $contrato 				                        = \App\Models\Contrato::find($id);
                
        if (!isset($contrato->id))
        {
             return response()->json(['error' => 'Contrato não encontrato'], 404);
        }
		
		if ($contrato->tipo !='F')
		{
			return response()->json(['error' => 'Renovação permitida somente contrato de pessoa fisica'], 404);
		}
		
		if (\App\Models\Parcela::where('contrato_id','=',$id)->count() == 0)
		{
			return response()->json(['error' => 'Contrato não tem parcelas, renovação não permitida'], 404);
		}
		
		if (\App\Models\Parcela::where('contrato_id', '=', $id)->where('data_pagamento','=',null)->where('data_baixa','=',null)->count() > 0)
		{
			return response()->json(['error' => 'Contrato tem parcelas abertas, renovação não permitida'], 404);
		}
				
		$plano 				                        	= \App\Models\Plano::find($request->plano_id);
                
        if (!isset($plano->id))
        {
             return response()->json(['error' => 'Plano não encontrato'], 404);
        }

		if ($plano->formapagamento == 'creditcard')
		{
			return response()->json(['error' => 'Renovação permitida somente para forma de contrato Boleto'], 404);
		}
		
		if (($plano->parcelas ==0) or ($plano->parcelas ==99))
		{
			return response()->json(['error' => 'Renovação permitida somente para planos com número limitado de parcelas'], 404);
		}
		
		$qtde_beneficiarios								= \App\Models\Beneficiario::where('contrato_id','=',$id)->count();
		
		if ($qtde_beneficiarios > $plano->qtde_beneficiarios)
		{
			return response()->json(['error' => 'Tem mais beneficiário no contrato do que suportado no plano'], 404);
		}
		
		$parcela 										= \App\Models\Parcela::where('contrato_id','=',$id)
																		   ->orderBy('nparcela','desc')
																		   ->first();
		
		if (!isset($parcela->id))
		{
			$nparcela 									= 1;
		} else {
			$nparcela 									= $parcela->nparcela;
		}
		
		//return response()->json($contrato, 200);
		 
        $contrato->plano_id                          	= $request->plano_id;
		$contrato->vigencia_inicio                      = $request->vigencia_inicio;
        $contrato->vigencia_fim                         = '2999-12-31';
		$contrato->valor                            	= $plano->preco;
		$contrato->taxa_ativacao                    	= 0;
        $contrato->situacao_id                          = 1;
        $contrato->galaxPayId                           = 0;
        $contrato->paymentLink                          = "";
		$contrato->mainPaymentMethodId          		= $plano->formapagamento;
        $contrato->status                               = "active";
        $contrato->quantity                             = 0;
		$contrato->periodicity                  		= $plano->periodicidade->periodicity;
        $contrato->firstPayDayDate                      = $request->vigencia_inicio;
        $contrato->additionalInfo                       = "";
        $contrato->paymentMethodBoletofine              = 0;
        $contrato->paymentMethodBoletointerest          = 0;
        $contrato->paymentMethodBoletoinstructions      = "";
        $contrato->paymentMethodBoletodeadlineDays      = 0;
        $contrato->paymentMethodBoletodocumentNumber    = "";
        $contrato->contractname                         = "";
        $contrato->contractdocument                     = "";
        $contrato->contractip                           = "";
		$contrato->avulso 								= "S";

		DB::beginTransaction();
		
        if ($contrato->save())
		{
			if ($qtde_beneficiarios == 0)
			{
				$beneficiario							= new \App\Models\Beneficiario();
				$beneficiario->contrato_id 				= $id;
				$beneficiario->cliente_id				= $contrato->cliente->id;
				$beneficiario->vigencia_inicio			= date('Y-m-d');
				$beneficiario->vigencia_fim				= '2099-12-31';
				$beneficiario->ativo					= true;
				$beneficiario->desc_status				= 'ATIVO';
				$beneficiario->parent_id				= 0;
				$beneficiario->tipo						= 'T';
				$beneficiario->tipo_usuario				= 'TITULAR';
				$beneficiario->parentesco_id 			= 0;
				$beneficiario->parent_id				= 0;
				$beneficiario->plano_id             	= $plano->id;
				$beneficiario->save();
			} else {
				$beneficiarios 							= DB::table('beneficiarios')
																->where('contrato_id', $id)
																->update(['ativo' 		 => 1,
																		  'vigencia_fim' => '2999-12-31',
																		  'desc_status'	 => 'ATIVO'
																		 ]);
			}
			
			$vencimento 								= Carbon::createFromFormat('Y-m-d', $request->vigencia_inicio);
			$dataVencimento 							= clone $vencimento;	
			
			for ($i = 1; $i <= $plano->parcelas; $i++) 
			{													 
				$rparcela 								= new \App\Models\Parcela();
				$rparcela->contrato_id					= $contrato->id;
				$rparcela->nparcela						= $nparcela + $i;
				if ($i == 1)
				{
					$rparcela->data_vencimento	    	= $dataVencimento->format('Y-m-d');
				} else {
					list($ano, $mes, $dia)				= explode("-",$dataVencimento->format('Y-m-d'));
					$data								= $ano . "-" . $mes . "-"  . $request->dia_vencimento;
					$rparcela->data_vencimento			= Cas::ajustarDiaVencimento($data);
				}
				$rparcela->data_pagamento				= null;
				$rparcela->data_baixa					= null;
				$rparcela->taxa							= 0;
				
				if ($i ==1)
				{	
					$rparcela->valor					= $contrato->valor + $contrato->taxa_ativacao;
				} else {
					$rparcela->valor					= $contrato->valor;
				}					
				$rparcela->desconto						= 0;
				$rparcela->juros						= 0;
				$rparcela->valor_pago					= 0;
				$rparcela->galaxPayId					= 0;
				$rparcela->boletobankNumber				= 0;
				$rparcela->payedOutsideGalaxPay			= false;
				$rparcela->statusDate					= null;
				$rparcela->datetimeLastSentToOperator	= null;
				$rparcela->status						= "";
				$rparcela->statusDescription			= "";
				$rparcela->additionalInfo				= "";
				$rparcela->subscriptionMyId				= "";
				$rparcela->boletopdf					= "";
				$rparcela->boletobankLine				= "";
				$rparcela->boletobarCode				= "";
				$rparcela->boletobankEmissor			= "";
				$rparcela->boletobankAgency				= "";
				$rparcela->boletobankAccount			= "";
				$rparcela->pixreference					= "";
				$rparcela->pixqrCode					= "";
				$rparcela->piximage						= "";
				$rparcela->pixpage						= "";
				$rparcela->tid							= "";
				$rparcela->authorizationCode			= "";
				$rparcela->cardOperatorId				= "";
				$rparcela->conciliationOccurrences		= "{}";
				$rparcela->formapagamento				= 'boleto';
				$rparcela->creditCard					= "{}";
				$rparcela->save();
				 // Avançar a data de vencimento em um mês para a próxima parcela
				$dataVencimento->addMonth();
			}
			CelCashParcelasAvulsaJob::dispatch($contrato->id)->onQueue('default');
		}

		DB::commit();
        return response()->json($contrato, 200);
    }
	
	public function modificar_confirmar(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.contratos')) {
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }

		$validator = Validator::make($request->all(), [
		    'vendedor_id' 				=> 'required|exists:vendedores,id',
			'plano_id' 					=> 'required|exists:planos,id',
            'vigencia_inicio'			=> 'required|date',
			'dia_vencimento'			=> 'required|integer|digits_between:1,12',
			'parcelas'          		=> 'required',
			'parcelas.*.dataVencimento'	=> 'required|date',
			'parcelas.*.numero'			=> 'required|numeric',
			'parcelas.*.valor'			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		if ($request->vigencia_inicio < date('Y-m-d'))
		{
			return response()->json(['error' => 'Vencimento da proxima Parcela não pode ser menor que a data de hoje.'], 422);
		}
		
        $contrato 				                        = \App\Models\Contrato::find($id);
                
        if (!isset($contrato->id))
        {
             return response()->json(['error' => 'Contrato não encontrato'], 404);
        }
		
		if ($contrato->tipo !='F')
		{
			return response()->json(['error' => 'Modificar permitida somente contrato de pessoa fisica'], 404);
		}
		
		if ($contrato->plano_id == $request->plano_id)
		{
			return response()->json(['error' => 'Modificar permitida somente planos diferentes'], 404);
		}
		
		if (\App\Models\Parcela::where('contrato_id','=',$id)->count() == 0)
		{
			return response()->json(['error' => 'Contrato não tem parcelas, modificacao não permitida'], 404);
		}
		
		if (\App\Models\Parcela::where('contrato_id', '=', $id)->where('data_pagamento','=',null)->where('data_baixa','=',null)->where('data_vencimento','>', date('Y-m-d'))->count() == 0)
		{
			return response()->json(['error' => 'Contrato não tem parcelas a vencer, modificação não permitida'], 404);
		}
		
		if (\App\Models\Parcela::where('contrato_id', '=', $id)->where('data_pagamento','=',null)->where('data_baixa','=',null)->where('data_vencimento','<=',date('Y-m-d'))->count() > 0)
		{
			return response()->json(['error' => 'Contrato tem parcelas vencidas, modificação não permitida'], 404);
		}
		
		$avulso											= $contrato->avulso;		
		$plano 				                        	= \App\Models\Plano::find($request->plano_id);
                
        if (!isset($plano->id))
        {
             return response()->json(['error' => 'Plano não encontrato'], 404);
        }

		if ($plano->formapagamento == 'creditcard')
		{
			return response()->json(['error' => 'Renovação permitida somente para forma de contrato Boleto'], 404);
		}
		
		if (($plano->parcelas ==0) or ($plano->parcelas ==99))
		{
			return response()->json(['error' => 'Renovação permitida somente para planos com número limitado de parcelas'], 404);
		}
		
		$qtde_beneficiarios								= \App\Models\Beneficiario::where('contrato_id','=',$id)->where('ativo','=',1)->count();
		
		if ($qtde_beneficiarios > $plano->qtde_beneficiarios)
		{
			return response()->json(['error' => 'Tem mais beneficiário(s) ativo(s) no contrato do que suportado no plano'], 404);
		}
		
		$parcela 										= \App\Models\Parcela::where('contrato_id','=',$id)
																		   ->orderBy('nparcela','desc')
																		   ->first();
		
		if (!isset($parcela->id))
		{
			$nparcela 									= 1;
		} else {
			$nparcela 									= $parcela->nparcela;
		}
		
		//return response()->json($contrato, 200);
		 
        $contrato->plano_id                          	= $request->plano_id;
		$contrato->vigencia_inicio                      = $request->vigencia_inicio;
        $contrato->vigencia_fim                         = '2999-12-31';
		$contrato->valor                            	= $plano->preco;
		$contrato->taxa_ativacao                    	= 0;
        $contrato->situacao_id                          = 1;
        $contrato->galaxPayId                           = 0;
        $contrato->paymentLink                          = "";
		$contrato->mainPaymentMethodId          		= $plano->formapagamento;
        $contrato->status                               = "active";
        $contrato->quantity                             = 0;
		$contrato->periodicity                  		= $plano->periodicidade->periodicity;
        $contrato->firstPayDayDate                      = $request->vigencia_inicio;
        $contrato->additionalInfo                       = "";
        $contrato->paymentMethodBoletofine              = 0;
        $contrato->paymentMethodBoletointerest          = 0;
        $contrato->paymentMethodBoletoinstructions      = "";
        $contrato->paymentMethodBoletodeadlineDays      = 0;
        $contrato->paymentMethodBoletodocumentNumber    = "";
        $contrato->contractname                         = "";
        $contrato->contractdocument                     = "";
        $contrato->contractip                           = "";
		$contrato->avulso 								= "S";

		DB::beginTransaction();
		
        if ($contrato->save())
		{
												
			if ($avulso == 'S')
			{
				$parcelas 								= DB::table('parcelas')
														->where('contrato_id', '=', $id)
														->where('data_pagamento','=',null)
														->where('data_baixa','=',null)
														->where('cgalaxPayId','>',0)
														->get();
				foreach ($parcelas as $parcela)
				{
					$cancelar 							= CelCash::cancelCharges($parcela->cgalaxPayId,1,"galaxPayId");
				}
			} else {
				$parcelas 								= DB::table('parcelas')
														->where('contrato_id', '=', $id)
														->where('data_pagamento','=',null)
														->where('data_baixa','=',null)
														->where('galaxPayId','>',0)
														->get();
				foreach ($parcelas as $parcela)
				{
					$cancelar 							= CelCash::cancelTransaction($parcela->galaxPayId,1,"galaxPayId");
				}
			}
			
			$update 									= DB::table('parcelas')
															->where('contrato_id', $id)
															->where('data_pagamento','=',null)
															->where('data_baixa','=',null)
															->update(['status' 		=> 'cancel',
															      'data_baixa'	=> date('Y-m-d'),
															      'statusDate'	=> date('Y-m-d H:m:s')
															]);
															
			$payload									= (object) $request->all();	
				
			foreach ($payload->parcelas as $parcela)
			{
				$parcela								= (object) $parcela;												 
				$rparcela 								= new \App\Models\Parcela();
				$rparcela->contrato_id					= $contrato->id;
				$rparcela->nparcela						= $parcela->numero;
				$rparcela->data_vencimento				= $parcela->dataVencimento;
				$rparcela->data_pagamento				= null;
				$rparcela->data_baixa					= null;
				$rparcela->taxa							= 0;
				$rparcela->valor						= $contrato->valor;
				$rparcela->desconto						= 0;
				$rparcela->juros						= 0;
				$rparcela->valor_pago					= 0;
				$rparcela->galaxPayId					= 0;
				$rparcela->boletobankNumber				= 0;
				$rparcela->payedOutsideGalaxPay			= false;
				$rparcela->statusDate					= null;
				$rparcela->datetimeLastSentToOperator	= null;
				$rparcela->status						= "";
				$rparcela->statusDescription			= "";
				$rparcela->additionalInfo				= "";
				$rparcela->subscriptionMyId				= "";
				$rparcela->boletopdf					= "";
				$rparcela->boletobankLine				= "";
				$rparcela->boletobarCode				= "";
				$rparcela->boletobankEmissor			= "";
				$rparcela->boletobankAgency				= "";
				$rparcela->boletobankAccount			= "";
				$rparcela->pixreference					= "";
				$rparcela->pixqrCode					= "";
				$rparcela->piximage						= "";
				$rparcela->pixpage						= "";
				$rparcela->tid							= "";
				$rparcela->authorizationCode			= "";
				$rparcela->cardOperatorId				= "";
				$rparcela->conciliationOccurrences		= "{}";
				$rparcela->formapagamento				= 'boleto';
				$rparcela->creditCard					= "{}";
				$rparcela->save();
			}
			DB::commit();
			CelCashParcelasAvulsaJob::dispatch($contrato->id)->onQueue('default');
		}

		DB::commit();
        return response()->json($contrato, 200);
    }
	
    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.contratos')) {
            return response()->json(['error' => 'Não autorizado para excluir contratos.'], 403);
        }

        $contrato 							= \App\Models\Contrato::find($id);
		
		if (!isset($contrato->id))
		{
			return response()->json(['error' => 'Contrato não encontrado'], 400);
		}

		if (($contrato->status !="") and ($contrato->tipo =='F'))
		{
			if ($contrato->beneficiarios()->exists()) {
				return response()->json(['error' => 'Não é possível excluir o contrato, pois ele possui beneficiários vinculados.'], 400);
			}

			if ($contrato->parcelas()->exists()) {
				return response()->json(['error' => 'Não é possível excluir o contrato, pois ele possui parcelas vinculadas.'], 400);
			}
		}
		
		DB::beginTransaction();

        if ($contrato->delete())
		{
		    DB::table('parcelas')
				->where('contrato_id','=',$id)
				->delete();	
			DB::table('beneficiarios')
				->where('contrato_id','=',$id)
				->delete();	
			DB::commit();
		} else {
			DB::rollBack();
		}
		
		$retorno				= new stdClass();
		$retorno->id 			= $id;
		$retorno->ok 			= "S";
		
        return response()->json($retorno, 200);
    }
	
	public function cancelar(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.contratos')) {
            return response()->json(['error' => 'Não autorizado para excluir contratos.'], 403);
        }

		$retorno								= new stdClass();
		$retorno->id 							= $id;
		$retorno->ok 							= "N";
		$retorno->mensagem						= "";
		
        $contrato 								= \App\Models\Contrato::find($id);
		
		if (!isset($contrato->id))
		{
			$retorno->mensagem					= 'Contrato não encontrado';
			return response()->json($retorno, 400);
		}

		if (($contrato->status !="active") and ($contrato->status !="waitingPayment"))
		{
			$retorno->mensagem					= "A situação do contrato não permite cancelamento";
			return response()->json($retorno, 404);
		}
		
		DB::beginTransaction();
			
		if ($contrato->tipo == 'F')
		{
			$cancelar 							= CelCash::cancelarContrato($id);
			
			if ($cancelar->ok == 'S')
			{
				$contrato->status 				= 'canceled';
				if ($contrato->save())
				{
					$parcelas 					= DB::table('parcelas')
														->where('contrato_id', '=', $id)
														->where('data_pagamento','=',null)
														->where('data_baixa','=',null)
														->update(['status' 		=> 'cancel',
																  'data_baixa'	=> date('Y-m-d'),
																  'statusDate'	=> date('Y-m-d H:m:s')
																]);
					$beneficiarios 				= DB::table('beneficiarios')
														->where('contrato_id', $id)
														->where('ativo','=',1)
														->update(['ativo' 		 => 0,
																  'vigencia_fim' => date('Y-m-d'),
																  'desc_status'	 => 'INATIVO'
																]);
					DB::commit();
				} else {
					DB::rollBack();
				}
			} else {
				DB::rollBack();
			}
			$retorno->ok 						= $cancelar->ok;
			if (isset($cancelar->mensagem))
			{
				$retorno->mensagem 				= $cancelar->mensagem;
			}
			return response()->json($retorno, 200);
		}
			
		$contrato->status 						= 'canceled';
		if ($contrato->save())
		{
			$parcelas 							= DB::table('parcelas')
													->where('contrato_id', $id)
													->where('data_pagamento','=',null)
													->where('data_baixa','=',null)
													->update(['status' 		=> 'cancel',
															      'data_baixa'	=> date('Y-m-d'),
															      'statusDate'	=> date('Y-m-d H:m:s')
															]);
															
			$beneficiarios 						= DB::table('beneficiarios')
														->where('contrato_id', $id)
														->where('ativo','=',1)
														->update(['ativo' 		 => 0,
																  'vigencia_fim' => date('Y-m-d'),
																  'desc_status'	 => 'INATIVO'
																]);
			DB::commit();
			$retorno->ok 						= 'S';
			return response()->json($retorno, 200);
		}
		
		DB::rollBack();
		$retorno->ok 							= 'N';
		$retorno->mensagem 						= "Ocorreu um erro não identificado. Entre em contato com o suporte";
        return response()->json($retorno, 200);
    }

    public function carne_view(Request $request, $id)
    {
        $contrato                            	= Contrato::find($id);

        if (isset($contrato->id))
		{
			
			$quantidade 						= DB::table('parcelas')
												->where('contrato_id', $id)
												->whereNull('data_pagamento')
												->whereNull('data_baixa')
												->where('galaxPayId', 0)
												->count();
												
			if ($quantidade == 0)
			{
										
				$tipo              					= $request->input('tipo', 'onePDFBySubscription');
				
				//if ($contrato->avulso == 'N')
				//{
				//	$tipo              				= 'onePDFBySubscription';
				//	$galaxPayId						= $contrato->galaxPayId;
				//} else {
					//$tipo              				= 'onePDFCharge';
					$tipo              				= 'onePDFTransaction';
					$galaxPayId						= Cas::obter_parcelaAbertas($contrato->id);
				//}
				
				$response                           = CelCash::obterCarne($galaxPayId,1,$tipo);
				
				if (isset($response->statcode))
				{
					if (($response->statcode == 200) and (isset($response->Carnes)))
					{			
						//$response                   = Http::get($response->Carnes[0]->pdf);
						$response = Http::withOptions(['stream' => true, 'allow_redirects' => ['max' => 60]])->get($response->Carnes[0]->pdf);
						return  response($response->body(), 200)->header('Content-Type', 'application/pdf');
					}
				}

				 //return response()->json($response, 200);

				if (isset($response->error))
				{
					if (isset($response->error->message))
					{
						return response()->json(['error' => $response->error->message], $response->statcode);
					}
				}
			} else {
				return response()->json(['error' => "Existem $quantidade parcelas sem boleto!"], 404);
			}
		}
		
        return response()->json(['error' => "ocorreu erro nao identificado:$id"], 404);
        //
        //return  response($response,200)->header('Content-Type', 'application/pdf');
    }
	
	public function contratopdf_view(Request $request, $id)
    {
        $contrato                            = \App\Models\Contrato::find($id);

        if (!isset($contrato->id))
        {
            return response()->json(['error' => 'Contrato não encontrado'], 404);
        }

        if (empty($contrato->contractpdf))
        {
            return response()->json(['error' => 'Contrato ainda não foi assinado ou PDF não disponível'], 404);
        }

        $urlPath = parse_url($contrato->contractpdf, PHP_URL_PATH);

        if ((!empty($urlPath)) and (Str::startsWith($urlPath, '/uploads/contratos/')))
        {
            $filePath = public_path($urlPath);

            if (!file_exists($filePath))
            {
                Log::warning("Arquivo PDF do contrato não encontrado no disco", [
                    'contrato_id' => $id,
                    'contractpdf' => $contrato->contractpdf,
                    'filePath' => $filePath
                ]);
                return response()->json(['error' => 'Arquivo PDF do contrato não foi encontrado no servidor. O contrato pode precisar ser assinado novamente.'], 404);
            }

            return response()->file($filePath, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="contrato-'.$id.'.pdf"',
            ]);
        }

        if (filter_var($contrato->contractpdf, FILTER_VALIDATE_URL))
        {
            $response = Http::get($contrato->contractpdf);

            if (!$response->successful())
            {
                Log::warning("Erro ao buscar PDF externo do contrato", [
                    'contrato_id' => $id,
                    'contractpdf' => $contrato->contractpdf,
                    'status' => $response->status()
                ]);
                return response()->json(['error' => 'Não foi possível obter o PDF do contrato. Tente novamente mais tarde.'], 422);
            }

            return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="contrato-'.$id.'.pdf"');
        }

        Log::warning("Caminho do PDF do contrato inválido", [
            'contrato_id' => $id,
            'contractpdf' => $contrato->contractpdf
        ]);
        return response()->json(['error' => 'Caminho do PDF do contrato inválido.'], 422);
    }
	
	public function cancelar_parcela_beneficiario(Request $request)
    {
		
		$contratos 							= DB::table('contratos')
												->whereIn('status', array('canceled','stopped','closed'))
												->get();
		foreach ($contratos as $contrato)
		{
			Cas::cancelar_parcela_beneficiario($contrato->id);
		}
		
		return true;
	}
	
	public function enviar_assinaturaWhatsapp(Request $request, $id)
    {
		
		$contrato                                	= \App\Models\Contrato::with('cliente')->find($id);
		
		if (!isset($contrato->id))
        {
			return response()->json(['mensagem' => 'Contrato não encontrado.'], 404);
		}
		
		if (!is_null($contrato->contractacceptedAt))
		{
			return response()->json(['mensagem' => 'O Contrato já foi assinado.'], 404);
		}
		
		$token										= Cas::obterTokenVendedor($request->user()->id);	
		
		if ($token =="")
		{
		  return response()->json(['mensagem' => 'Token do Chat Hot não cadastrado. Favor solicitar o cadastro.'], 404);
		}
		
		$mensagem 									= 'Olá, segue o link para assinatura do contrato. ';
		$mensagem 									.= "\n" . $contrato->paymentLink;
		
		$envio 										= ChatHot::enviarMensagemChatHot($contrato->cliente->telefone, $mensagem, $token);
		
		if ($envio->ok =='S') 
		{
			 return response()->json(['mensagem' => 'Assinatura enviada pelo whatsapp com sucesso!'], 200);
		}
		
		return response()->json(['mensagem' => 'Ocorreu erro no envio da mensagem no whatsapp. Entre em contato com o administrador do Chat Hot'], 404);
		
	}
	
	public function enviar_contratoWhatsapp(Request $request, $id)
    {
		
		$contrato                                	= \App\Models\Contrato::with('cliente')->find($id);
		
		if (!isset($contrato->id))
        {
			return response()->json(['mensagem' => 'Contrato não encontrado.'], 404);
		}
		
		if (is_null($contrato->contractacceptedAt))
		{
			return response()->json(['mensagem' => 'O Contrato ainda não foi assinado.'], 404);
		}
		
		$token										= Cas::obterTokenVendedor($request->user()->id);	
		
		if ($token =="")
		{
		  return response()->json(['mensagem' => 'Token do Chat Hot não cadastrado. Favor solicitar o cadastro.'], 404);
		}
		
		$mensagem 									= 'Olá, segue o link para obter o contrato ';
		$mensagem 									.= "\n" . $contrato->contractpdf;
		
		$envio 										= ChatHot::enviarMensagemChatHot($contrato->cliente->telefone, $mensagem, $token);
		
		if ($envio->ok =='S') 
		{
			 return response()->json(['mensagem' => 'Contrato enviada pelo whatsapp com sucesso!'], 200);
		}
		
		return response()->json(['mensagem' => 'Ocorreu erro no envio da mensagem no whatsapp. Entre em contato com o administrador do Chat Hot'], 404);
		
	}
	
	public function obter_assinatura(Request $request)
    {
		
		$hash              							= $request->input('hash', 'nada');
		$retorno									= new stdClass();
		$retorno->texto								= "";
		
		$contrato 				                    = \App\Models\Contrato::where('paymentLink','=',$hash)->first();
                
        if (isset($contrato->id))
        {		
			$plano                  				= \App\Models\Plano::find($contrato->plano_id);
			$retorno->texto 						= Cas::formatarTextoContrato($plano->clausulas,$contrato->id);
		}
		
		return response()->json($retorno, 200);
	}
	
	public function gravar_assinatura(Request $request)
    {
		$hash              							= $request->input('hash', 'nada');
		$cpf              							= $request->input('cpf', '');
		$nome              							= $request->input('nome', '');
		
		$paragrafos 								= array();
		
		$contrato 				                    = \App\Models\Contrato::with('cliente')->where('paymentLink','=',$hash)->first();
         
		if (!isset($contrato->id))
        {	
			return response()->json(['mensagem' => 'Contrato não encntrato!'], 422);
		}
		
		$cpf 										= preg_replace('/[^0-9]/', '', $cpf);
		$cpf 										= str_pad($cpf, 11, "0", STR_PAD_LEFT);	
		
		
		$cpfc 										= preg_replace('/[^0-9]/', '', $contrato->cliente->cpfcnpj);
		$cpfc 										= str_pad($cpfc, 11, "0", STR_PAD_LEFT);	
		
		if ($cpfc != $cpf)
		{
			return response()->json(['mensagem' => 'O CPF informado não está de acordo com o cadastrado no contrato: ' . $cpf ], 422);
		}
		
       	$ip 										= request()->ip();
		$gerou 										= Cas::gerarContratoPDF($contrato->id,$ip);
	
		return response()->json($gerou , 200);
	}
	
	public function obter_parcelas_vencidas(Request $request, $id)
    {
		$retorno									= new stdClass();
		$retorno->ok 								= "";
		$retorno->mensagem 							= "";
		$sql 										= "SELECT id, nome, ativo FROM motivos where ativo=1 order by nome";
		$retorno->motivos							= DB::select($sql);
		
		$contrato 				                    = \App\Models\Contrato::with('cliente')->find($id);
         
		if (!isset($contrato->id))
        {
			$retorno->ok 							= "N";
			$retorno->mensagem 						= "O número de contrato informado não foi encontrado";
			return response()->json($retorno, 200);
		}
		
		$cliente									= new stdClass();
		$cliente->cpf 								= $contrato->cliente->cpfcnpj;
		$cliente->nome								= $contrato->cliente->nome;
        $cliente->telefone							= $contrato->cliente->telefone;
        $cliente->contrato							= $id;
		$retorno->cliente 							= $cliente;
		$hoje 										= Carbon::now()->format('Y-m-d');
		
		/*
		 numero: 1,
         valor: 500.00,
         juros: 50.00,
         valorTotal: 550.00,
         dataVencimento: new Date(2025, 2, 15),
         diasAtraso: 40 
		*/
		$parcelas 									= array();
		$vparcelas 									= array();
		
		$pvencidas									= DB::table('parcelas')
															->select('id',
																	 'valor',
																	 'nparcela',
																	 'data_vencimento',
																	 DB::raw("DATEDIFF('$hoje', data_vencimento) as dias")
																	)
															->where('contrato_id', $id)
															->where('data_pagamento','=',null)
															->where('data_baixa','=',null)
															->where('data_vencimento', '<=', DB::raw('CURDATE()'))
															->orderBy('data_vencimento')
															->get();
		foreach ($pvencidas as $pvencida)
		{
			$parcela								= new stdClass();
			$parcela->id 							= $pvencida->id;
			$parcela->numero 						= $pvencida->nparcela;
			$parcela->valor 						= $pvencida->valor;
			$parcela->juros							= 0;
			$parcela->diasAtraso 					= $pvencida->dias;
			$parcela->dataVencimento				= $pvencida->data_vencimento;
			$calculo 								= cas::calcularJurosBoleto($parcela->valor, $parcela->diasAtraso, null, 5.0, 2.0);
			if ($calculo->erro =="")
			{
				$parcela->juros 					= number_format($calculo->multaJuros,2);
			}
			$parcela->valorTotal					= $parcela->valor + $parcela->juros;
			$parcelas[]								= $parcela;
		}
		
		$pvencidas									= DB::table('parcelas')
															->select('id',
																	 'valor',
																	 'nparcela',
																	 'data_vencimento',
																	 DB::raw("DATEDIFF('$hoje', data_vencimento) as dias")
																	)
															->where('contrato_id', $id)
															->where('data_pagamento','=',null)
															->where('data_baixa','=',null)
															->where('data_vencimento', '>', DB::raw('CURDATE()'))
															->orderBy('data_vencimento')
															->get();
		foreach ($pvencidas as $pvencida)
		{
			$parcela								= new stdClass();
			$parcela->id 							= $pvencida->id;
			$parcela->numero 						= $pvencida->nparcela;
			$parcela->valor 						= $pvencida->valor;
			$parcela->juros							= 0;
			$parcela->diasAtraso 					= $pvencida->dias;
			$parcela->dataVencimento				= $pvencida->data_vencimento;
			$parcela->valorTotal					= $parcela->valor + $parcela->juros;
			$vparcelas[]							= $parcela;
		}
		$retorno->parcelas 							= $parcelas;
		$retorno->vparcelas 						= $vparcelas;
		$retorno->ok 								= "S";
		return response()->json($retorno, 200); 
	}
	
	public function cancelar_parcela_falhou(Request $request, $id)
    {
		$pparcela 				                = \App\Models\Parcela::with("contrato")->find($id);
         
		if (isset($pparcela->id))
		{
			if ($pparcela->contrato->tipo == 'J')
			{
				$cancelar 						= CelCash::cancelCharges($pparcela->contrato->galaxPayId,1,"galaxPayId");
			} else {
				if ($pparcela->contrato->avulso == 'S')
				{
					$cancelar 					= CelCash::cancelCharges($pparcela->cgalaxPayId,1,"galaxPayId");
				} else {
					$cancelar 					= CelCash::cancelTransaction($pparcela->galaxPayId,1,"galaxPayId");
							
				}
			}
			if ((isset($cancelar->statcode)) and ($cancelar->statcode ==200))
			{
				$pparcela->data_baixa			= date('Y-m-d');
				$pparcela->boletobankNumber		= 0;
				$pparcela->negociar				= 'S';
				$pparcela->save();
				return response()->json($true, 200); 
			} else {
				return response()->json(['error' => "Ocorreu um erro não identificado no cancelamento da parcela no CelCash"], 422);	
			}
		}
		
		return response()->json(['error' => "A parcela nao foi encontrada"], 422);	
	}
	
	public function negociar_parcelas_vencidas(Request $request)
    {
		
		if (!$request->user()->tokenCan('edit.contratos')) {
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }

		$validator = Validator::make($request->all(), [
			'contrato_id' 			=> 'required|exists:contratos,id',
			'desconto' 				=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			'valor' 				=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'vencimento'			=> 'required|date',
			'formapagamento'		=> 'required|in:"boleto","creditcard","dinheiro"',
			'parcelas'				=> 'required',
			'parcelas.*.parcela_id'	=> 'required|exists:parcelas,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		/*
		$payload							= (object) $request->all();			
		$parcelas_falharam 					= array();
		
		foreach ($payload->parcelas as $parcela)
		{
			$parcela						= (object) $parcela;	
			$parcelas_falharam[]			= $parcela;
		}
		
		return response()->json(['sucesso' => true, 'parcelas_falharam' => $parcelas_falharam], 200);
		*/
		
		$contrato 				                    = \App\Models\Contrato::find($request->contrato_id);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => "O número de contrato informado não foi encontrado"], 422);
		}
		
		if ((!isset($request->motivo_id)) or (!is_numeric($request->motivo_id)))
		{
			$request->motivo_id						= 0;
		}
		
		if (!isset($request->observacao))
		{
			$request->observacao					= "";
		}

		
		$tcontrato 				                    = \App\Models\Contrato::where('cliente_id','=',$contrato->cliente_id)
																		  ->where('status','=','active')
																		  ->where('id','<>',$contrato->id)
																		  ->first();
         
		if (isset($tcontrato->id))
        {
			return response()->json(['error' => "Não é possível negociar, já existe um contrato ativo para este cliente"], 422);
		}
		
		//return response()->json(['sucesso' => true], 200); 
		
		$nparcelas 									= 0;
		$payload									= (object) $request->all();	
		DB::beginTransaction();
		
		foreach ($payload->parcelas as $parcela)
		{
			$parcela								= (object) $parcela;	
			
			if ($parcela->nparcela > $nparcelas)
			{
				$nparcelas							= $parcela->nparcela;
			}
		}
		
		$nparcelas									= \App\Models\Parcela::where('contrato_id','=',$request->contrato_id)
															->lockForUpdate()
															->max('nparcela');
		if (!is_numeric($nparcelas))
		{
			$nparcelas								= 0;
		}
		
		$rparcela 								= new \App\Models\Parcela();
		$rparcela->contrato_id					= $request->contrato_id;
		$rparcela->nparcela						= $nparcelas + 1;
		$rparcela->data_vencimento	    		= $request->vencimento;
		$rparcela->data_pagamento				= null;
		$rparcela->data_baixa					= null;
		$rparcela->taxa							= 0;
		$rparcela->valor						= str_replace(",",".",$request->valor); 
		$rparcela->desconto						= 0;
		$rparcela->juros						= 0;
		$rparcela->valor_pago					= 0;
		$rparcela->galaxPayId					= 0;
		$rparcela->boletobankNumber				= 0;
		$rparcela->payedOutsideGalaxPay			= false;
		$rparcela->statusDate					= null;
		$rparcela->datetimeLastSentToOperator	= null;
		$rparcela->formapagamento				= $request->formapagamento;		
		$rparcela->status						= "";
		$rparcela->statusDescription			= "";
		$rparcela->additionalInfo				= "Negociado em " . date('d/m/Y') . " desconto de : R$ " . str_replace(".",",",$request->desconto);
		$rparcela->negociar						= 'S';
		$rparcela->subscriptionMyId				= "";
		$rparcela->boletopdf					= "";
		$rparcela->boletobankLine				= "";
		$rparcela->boletobarCode				= "";
		$rparcela->boletobankEmissor			= "";
		$rparcela->boletobankAgency				= "";
		$rparcela->boletobankAccount			= "";
		$rparcela->pixreference					= "";
		$rparcela->pixqrCode					= "";
		$rparcela->piximage						= "";
		$rparcela->pixpage						= "";
		$rparcela->tid							= "";
		$rparcela->authorizationCode			= "";
		$rparcela->cardOperatorId				= "";
		$rparcela->chargeMyId					= "";
		$rparcela->conciliationOccurrences		= "{}";
		$rparcela->creditCard					= "{}";
		
		if (!$rparcela->save())
		{
			DB::rollBack();
			return response()->json(['error' => "Ocorreu um erro não identificado na criação da parcela"], 422);	
		}
		
		if ($request->formapagamento=='boleto')
		{
			if (($contrato->tipo == 'J') or ($contrato->avulso == 'S'))
			{
				$charges								= CelCash::storeContratoCharges($rparcela->id);
				
				if (($charges->ok == 'S') and (isset($charges->Charge)))
				{
					$scharge 							= CelCash::updateContratoWithCharge($charges->Charge);
					if ((!isset($scharge->ok)) or ($scharge->ok != 'S'))
					{
						DB::rollBack();
						return response()->json(['error' => "Ocorreu um erro nÃ£o identificado na criaÃ§Ã£o da parcela no CelCash"], 422);
					}
				} else {
					DB::rollBack();
					return response()->json(['error' => "Ocorreu um erro nÃ£o identificado na criaÃ§Ã£o da parcela no CelCash"], 422);
				}
			} else {
				$body									= new stdClass();
				$body->myId								= $request->contrato_id . "#" . $rparcela->id . "#" .  bin2hex(random_bytes(5));
				$body->value							= intval($request->valor * 100);
				$body->payday							= $request->vencimento;
				$body->payedOutsideGalaxPay				= false;
				$body->additionalInfo					= $rparcela->additionalInfo;
				$rparcela->chargeMyId					= $body->myId;
				$rparcela->save();
				$adicionar 								= CelCash::adicionarTransaction($contrato->galaxPayId,$body,'galaxPayId');
				if (($adicionar->statcode != 200) or (!isset($adicionar->type)) or (!$adicionar->type))
				{
					DB::rollBack();
					return response()->json(['error' => "Ocorreu um erro não identificado na criação da parcela no CelCash"], 422);	
				}
				$rparcela->galaxPayId					= $adicionar->Transaction->galaxPayId;
				$rparcela->save();
				$transaction   							= CelCash::CelCashMigrarTransaction($adicionar->Transaction,1,'C');
				if ((!isset($transaction->ok)) or (!$transaction->ok))
				{
					DB::rollBack();
					return response()->json(['error' => "Ocorreu um erro nÃ£o identificado na criaÃ§Ã£o da parcela no CelCash"], 422);
				}
			}
		}
		
		DB::commit();
		
		$parcelas_falharam 							= array();
		
		foreach ($payload->parcelas as $parcela)
		{
			$parcela								= (object) $parcela;	
				
			$pparcela 				                = \App\Models\Parcela::find($parcela->parcela_id);
         
			if (isset($pparcela->id))
			{
				if ($contrato->tipo == 'J')
				{
					$cancelar 						= CelCash::cancelCharges($contrato->galaxPayId,1,"galaxPayId");
				} else {
					if ($contrato->avulso == 'S')
					{
						$cancelar 					= CelCash::cancelCharges($pparcela->cgalaxPayId,1,"galaxPayId");
					} else {
						$cancelar 					= CelCash::cancelTransaction($pparcela->galaxPayId,1,"galaxPayId");
							
					}
				}
				if ((isset($cancelar->statcode)) and ($cancelar->statcode ==200))
				{
					$pparcela->data_baixa			= date('Y-m-d');
					$pparcela->boletobankNumber		= 0;
					//$pparcela->galaxPayId			= 0;
					//$pparcela->cgalaxPayId		= 0;
					$pparcela->negociar				= 'S';
					$pparcela->save();
				} else {
					$parcelas_falharam[]			= $parcela;
				}
			}
		}
		
		if (($request->motivo_id > 0) and ($request->formapagamento=='dinheiro'))
		{
			$contrato->status 						= 'canceled';
		}
		
		$contrato->motivo_id                        = $request->motivo_id;
		$contrato->observacao                       = $request->observacao;
		$contrato->save();
		
		if (count($parcelas_falharam) == 0)
		{
			return response()->json(['sucesso' => true], 200);
		} 

		return response()->json(['sucesso' => true, 'parcelas_falharam' => $parcelas_falharam], 200);
		
	}
	
	public function obter_parcelas_todas(Request $request, $id)
    {
		$retorno									= new stdClass();
		$retorno->ok 								= "";
		$retorno->mensagem 							= "";
		
		$contrato 				                    = \App\Models\Contrato::with('cliente')->find($id);
         
		if (!isset($contrato->id))
        {
			$retorno->ok 							= "N";
			$retorno->mensagem 						= "O número de contrato informado não foi encontrado";
			return response()->json($retorno, 200);
		}
		
		$cliente									= new stdClass();
		$cliente->cpf 								= $contrato->cliente->cpfcnpj;
		$cliente->nome								= $contrato->cliente->nome;
        $cliente->telefone							= $contrato->cliente->telefone;
        $cliente->contrato							= $id;
		$retorno->cliente 							= $cliente;
		$retorno->plano_id 							= $contrato->plano_id;
		$retorno->vendedor_id 						= $contrato->vendedor_id;
		/*
		 numero: 1,
         valor: 500.00,
         juros: 50.00,
         valorTotal: 550.00,
         dataVencimento: new Date(2025, 2, 15),
         diasAtraso: 40 
		*/
		$parcelas 									= array();
		
		$pparcelas									= DB::table('parcelas')
															->select('id',
																	 'valor',
																	 'nparcela',
																	 'data_vencimento',
																	 'data_pagamento',
																	 'data_baixa',
																	 'status'
																	)
															->where('contrato_id', $id)
															->orderBy('data_vencimento')
															->get();
		foreach ($pparcelas as $pparcela)
		{
			$parcela								= new stdClass();
			$parcela->id 							= $pparcela->id;
			$parcela->numero 						= $pparcela->nparcela;
			$parcela->valor 						= $pparcela->valor;
			$parcela->dataVencimento				= $pparcela->data_vencimento;
			$parcela->dataPagamento					= $pparcela->data_pagamento;
			$parcela->dataBaixa						= $pparcela->data_baixa;
			$parcela->situacao 						= Cas::obterSituacaoParcela($pparcela->data_vencimento,$pparcela->data_pagamento,$pparcela->data_baixa);
			$parcelas[]								= $parcela;
		}
		
		$retorno->parcelas 							= $parcelas;
		$beneficiarios 								= array();
		
		$bbeneficiarios								= DB::connection('mysql')
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
                                                        ->where('beneficiarios.contrato_id','=',$id)
														->leftJoin('clientes','beneficiarios.cliente_id','=','clientes.id')
														->leftJoin('parentescos','beneficiarios.parentesco_id','=','parentescos.id')
														->leftJoin('contratos','beneficiarios.contrato_id','=','contratos.id')
														->get();
		foreach ($bbeneficiarios as $bbeneficiario)
		{
			$beneficiario							= new stdClass();
			$beneficiario->id 						= $bbeneficiario->id;
			$beneficiario->cpfcnpj                  = Cas::formatCnpjCpf($bbeneficiario->cpfcnpj);   
			$beneficiario->cliente 					= $bbeneficiario->cliente;
			$beneficiario->idade 				    = Carbon::createFromDate($bbeneficiario->data_nascimento)->age;  
			$beneficiario->tipo_usuario             = ucfirst(strtolower($bbeneficiario->tipo_usuario));
            $beneficiario->desc_status              = ucfirst(strtolower($bbeneficiario->desc_status));
			$beneficiario->vigencia_inicio			= $bbeneficiario->vigencia_inicio;
			$beneficiario->parentesco				= $bbeneficiario->parentesco;
			$beneficiarios[]						= $beneficiario;
		}
		
		$retorno->beneficiarios 					= $beneficiarios;
		$retorno->ok 								= "S";
		return response()->json($retorno, 200); 
	}
	
	public function suspender_contrato(Request $request, $id)
    {
		
		if (!$request->user()->tokenCan('edit.contratos')) {
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }

		$validator = Validator::make($request->all(), [
		    'observacao'			=> 'required',
			'parcelas'				=> 'required',
			'parcelas.*.parcela_id'	=> 'required|exists:parcelas,id',
			'parcelas.*.vencimento'	=> 'required|date',
			'parcelas.*.nparcela'	=> 'required|numeric',
			'parcelas.*.valor'		=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		
		$contrato 				                    = \App\Models\Contrato::find($id);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => "Contrato não encontrado"], 422);
		}
		
		
		$nparcela 					= DB::table('parcelas')
										->where('contrato_id', $id)
										->max('nparcela');
		
		if ((!isset($nparcela)) or ($nparcela==0))
		{
			return response()->json(['error' => "Ultima parcela não encontrada"], 422);
		}
		
		foreach ($payload->parcelas as $parcela)
		{
			$nparcela++;
			$parcela								= (object) $parcela;	
			$rparcela 								= new \App\Models\Parcela();
			$rparcela->contrato_id					= $id;
			$rparcela->nparcela						= $nparcela;
			$rparcela->data_vencimento	    		= $parcela->vencimento;
			$rparcela->data_pagamento				= null;
			$rparcela->data_baixa					= null;
			$rparcela->taxa							= 0;
			$rparcela->valor						= str_replace(",",".",$parcela->valor); 
			$rparcela->desconto						= 0;
			$rparcela->juros						= 0;
			$rparcela->valor_pago					= 0;
			$rparcela->galaxPayId					= 0;
			$rparcela->boletobankNumber				= 0;
			$rparcela->payedOutsideGalaxPay			= false;
			$rparcela->statusDate					= null;
			$rparcela->datetimeLastSentToOperator	= null;
			$rparcela->formapagamento				= 'boleto';
			$rparcela->status						= "";
			$rparcela->statusDescription			= "";
			$rparcela->additionalInfo				= "";
			$rparcela->negociar						= 'N';
			$rparcela->subscriptionMyId				= "";
			$rparcela->boletopdf					= "";
			$rparcela->boletobankLine				= "";
			$rparcela->boletobarCode				= "";
			$rparcela->boletobankEmissor			= "";
			$rparcela->boletobankAgency				= "";
			$rparcela->boletobankAccount			= "";
			$rparcela->pixreference					= "";
			$rparcela->pixqrCode					= "";
			$rparcela->piximage						= "";
			$rparcela->pixpage						= "";
			$rparcela->tid							= "";
			$rparcela->authorizationCode			= "";
			$rparcela->cardOperatorId				= "";
			$rparcela->conciliationOccurrences		= "{}";
			$rparcela->creditCard					= "{}";
		
			if (!$rparcela->save())
			{
				return response()->json(['error' => "Ocorreu um erro não identificado na criação da parcela"], 422);	
			}
			
			if (($contrato->tipo == 'J') or ($contrato->avulso == 'S'))
			{
				$charges								= CelCash::storeContratoCharges($rparcela->id);
				
				if (($charges->ok == 'S') and (isset($charges->Charge)))
				{
					$scharge 							= CelCash::updateContratoWithCharge($charges->Charge);
				}
			} else {
				$body									= new stdClass();
				$body->myId								= $id . "#" . $rparcela->id . "#" .  bin2hex(random_bytes(5));
				$body->value							= intval($parcela->valor * 100);
				$body->payday							= $parcela->vencimento;
				$body->payedOutsideGalaxPay				= false;
				$body->additionalInfo					= $rparcela->additionalInfo;
				$adicionar 								= CelCash::adicionarTransaction($contrato->galaxPayId,$body,'galaxPayId');
				if (($adicionar->statcode != 200) or (!isset($adicionar->type)) or (!$adicionar->type))
				{
					return response()->json(['error' => "Ocorreu um erro não identificado na criação da parcela no CelCash"], 422);	
				}
				$rparcela->galaxPayId					= $adicionar->Transaction->galaxPayId;
				$rparcela->save();
				$transaction   							= CelCash::CelCashMigrarTransaction($adicionar->Transaction,1,'C');
			}
		}
		
		foreach ($payload->parcelas as $parcela)
		{
			$parcela								= (object) $parcela;	
				
			$pparcela 				                = \App\Models\Parcela::find($parcela->parcela_id);
         
			if (isset($pparcela->id))
			{
				if ($contrato->tipo == 'J')
				{
					$cancelar 						= CelCash::cancelCharges($contrato->galaxPayId,1,"galaxPayId");
				} else {
					if ($contrato->avulso == 'S')
					{
						$cancelar 					= CelCash::cancelCharges($pparcela->cgalaxPayId,1,"galaxPayId");
					} else {
						$cancelar 					= CelCash::cancelTransaction($pparcela->galaxPayId,1,"galaxPayId");
							
					}
				}
				if ((isset($cancelar->statcode)) and ($cancelar->statcode ==200))
				{
					$pparcela->data_baixa			= date('Y-m-d');
					$pparcela->boletobankNumber		= 0;
					//$pparcela->galaxPayId			= 0;
					//$pparcela->cgalaxPayId		= 0;
					$pparcela->negociar				= 'S';
					$pparcela->save();
				}
			}
		}
		
		$contrato->status 							= 'suspended';
		$contrato->observacao 						= $request->observacao;
		$contrato->save();
		
		return response()->json(true, 200); 
	}
	
	public function getComparativoParcelas(Request $request, $contratoId, $cellCashId)
    {
	
		$limite 							= $request->input('limite', 100);	
		$comp								= new stdClass();
		$comp->parcelasCRM 					= array();
		$comp->parcelasCellCash				= array();
		
		$contrato 				            = \App\Models\Contrato::with('cliente')->find($contratoId);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => "Contrato não encontrado"], 422);
		}
		
		if (!isset($contrato->cliente->cpfcnpj))
		{
			return response()->json(['error' => "Cliente do Contrato não encontrado"], 422);
		}
		
		$cpf 								= preg_replace('/[^0-9]/', '', $contrato->cliente->cpfcnpj);
		$cpf 								= str_pad($cpf, 11, "0", STR_PAD_LEFT);	
		
		$query                              = "documents=" .  $cpf . "&startAt=0&limit=1";
		$customer                           = CelCash::getCustomers($query);
		
		if (!isset($customer->Customers[0]))
		{
			return response()->json(['error' => "Cliente no Cell Cash não encontrado"], 422);
		}
			
		if (!isset($customer->Customers[0]->galaxPayId))
		{
			return response()->json(['error' => "Cliente no Cell Cash não encontrado com o ID"], 422);
		}
		
		$resultado = CelCash::listarTransacoesPorData($customer->Customers[0]->galaxPayId, $limite);

        if ($resultado->statcode != 200) 
		{
			return response()->json(['error' => "Não existe transação no Cel Cash"], 422);
		}
		
        $dadosExtraidos 				= CelCash::extrairDadosTransacoes($resultado->response);
    	$balanco 						= CelCash::balancearDadosTransacoesParcelas($contratoId, $dadosExtraidos);
		
		
		if (isset($balanco['parcelas_com_transacao']))
		{
			//'Correspondente'
			
			//$balanco->parcelas_com_transacao->parcela_resumo	= json_decode($balanco->parcelas_com_transacao->parcela_resumo);
			
			foreach ($balanco['parcelas_com_transacao'] as $parcelas_com_transacao)
			{
				
				$parcelas_com_transacao			= $parcelas_com_transacao;
				
				if (isset($parcelas_com_transacao['parcela_resumo']))
				{
					$reg						= new stdClass();
					$reg->id					= $parcelas_com_transacao['parcela_resumo']['id'];
					$reg->cellCashId			= $parcelas_com_transacao['parcela_resumo']['galaxPayId'];
					$reg->nparcela				= $parcelas_com_transacao['parcela_resumo']['nparcela'] ?? 0;
					$reg->valor					= $parcelas_com_transacao['parcela_resumo']['valor'];
					$reg->data_vencimento		= $parcelas_com_transacao['parcela_resumo']['data_vencimento'];
					$reg->data_pagamento		= $parcelas_com_transacao['parcela_resumo']['data_pagamento'];
					$reg->data_baixa			= $parcelas_com_transacao['parcela_resumo']['data_baixa'] ?? null;
					$reg->status				= Cas::obterSituacaoParcela($reg->data_vencimento,$reg->data_pagamento,$reg->data_baixa);
					$reg->situacao				= 'Correspondente';
					$comp->parcelasCRM[]		= $reg;
				}
				if (isset($parcelas_com_transacao['transacao_resumo']))
				{
					$reg						= new stdClass();
					$reg->cellCashId			= $parcelas_com_transacao['transacao_resumo']['galaxPayId'];
					$reg->nparcela				= $parcelas_com_transacao['transacao_resumo']['installment'] ?? 0;
					$reg->valor					= $parcelas_com_transacao['transacao_resumo']['value'] / 100;
					$reg->data_vencimento		= $parcelas_com_transacao['transacao_resumo']['payday'];
					$reg->data_pagamento		= substr($parcelas_com_transacao['transacao_resumo']['paydayDate'],0,10);
					$reg->data_baixa			= $parcelas_com_transacao['transacao_resumo']['data_baixa'] ?? null;
					$reg->status				= Cas::obterSituacaoParcela($reg->data_vencimento,$reg->data_pagamento,$reg->data_baixa);
					$reg->situacao				= 'Correspondente';
					$comp->parcelasCellCash[]	= $reg;
				}
			}
		}
		
		if (isset($balanco['parcelas_sem_transacao']))
		{
			// 'Só no CRM'
			foreach ($balanco['parcelas_sem_transacao'] as $parcelas_sem_transacao)
			{
				$reg						= new stdClass();
				$reg->id					= $parcelas_sem_transacao['id'];
				$reg->cellCashId			= $parcelas_sem_transacao['galaxPayId'];
				$reg->nparcela				= $parcelas_sem_transacao['nparcela'] ?? 0;
				$reg->valor					= $parcelas_sem_transacao['valor'];
				$reg->data_vencimento		= $parcelas_sem_transacao['data_vencimento'];
				$reg->data_pagamento		= $parcelas_sem_transacao['data_pagamento'];
				$reg->data_baixa			= $parcelas_sem_transacao['data_baixa'] ?? null;
				$reg->status				= Cas::obterSituacaoParcela($reg->data_vencimento,$reg->data_pagamento,$reg->data_baixa);
				$reg->situacao				= 'Só no CRM';
				$comp->parcelasCRM[]		= $reg;
			}
		}
		
		if (isset($balanco['transacoes_sem_parcela']))
		{
			
			// 'SÓ NO CELL CASH'
			foreach ($balanco['transacoes_sem_parcela'] as $transacoes_sem_parcela)
			{
				$reg						= new stdClass();
				$reg->cellCashId			= $transacoes_sem_parcela['galaxPayId'];
				$reg->nparcela				= $transacoes_sem_parcela['installment'] ?? 0;
				$reg->valor					= $transacoes_sem_parcela['value'] / 100;
				$reg->data_vencimento		= $transacoes_sem_parcela['payday'];
				$reg->data_pagamento		= substr($transacoes_sem_parcela['paydayDate'],0,10);
				$reg->data_baixa			= $transacoes_sem_parcela['data_baixa'] ?? null;
				$reg->status				= Cas::obterSituacaoParcela($reg->data_vencimento,$reg->data_pagamento,$reg->data_baixa);
				$reg->situacao				= 'SÓ NO CELL CASH';
				$comp->parcelasCellCash[]	= $reg;
			}
		}
		
		return response()->json($comp, 200); 
		
	}
	
	public function contrato_planos_listar(Request $request)
    {
		if (!$request->user()->tokenCan('edit.contratos')) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }
		
		$limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'contrato_planos.plano_id');
		$direction          					= $request->input('direction', 'asc');
       
		$validator = Validator::make($request->all(), [
			'contrato_id'						=> 'required|exists:contratos,id',
        ]);

        if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$contrato 				              	= \App\Models\Contrato::find($request->contrato_id);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => "Contrato não encontrado"], 422);
		}
		
		$planos									= DB::connection('mysql')
														->table('contrato_planos')
														->select(
															     'contrato_planos.id',
                                                                 'contrato_planos.contrato_id',
                                                                 'contrato_planos.plano_id',
																 'contrato_planos.sigla',
                                                                 'planos.nome as nome_plano'
														        )
                                      					->where('contrato_id','=',$request->contrato_id)
														->join('planos',	    'contrato_planos.plano_id','=','planos.id')
														->orderBy($orderby,$direction)
														->paginate($limite);
		return response()->json($planos, 200); 
	}
	
	public function contrato_plano_update(Request $request, $id)
    {
		if (!$request->user()->tokenCan('edit.contratos')) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'contrato_id'						=> 'required|exists:contratos,id',
			'plano_id'							=> 'required|exists:planos,id',
			'sigla' 							=> 'required|string|max:20',
        ]);

        if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$contrato 				              	= \App\Models\ContratoPlano::where('contrato_id','=',$request->contrato_id)
																		   ->where('plano_id','=',$request->plano_id)
																		   ->where('id','<>',$id)
																		   ->first();					
		if (isset($contrato->id))
        {
			return response()->json(['error' => "Já existe o Contrato/Plano. Alteração não permitida"], 404);
		}
		
		$contrato 				              	= \App\Models\ContratoPlano::where('contrato_id','=',$request->contrato_id)
																		   ->where('sigla','=',$request->sigla)
																		   ->where('id','<>',$id)
																		   ->first();					
		if (isset($contrato->id))
        {
			return response()->json(['error' => "Já existe o Contrato/Sigla. Alteração não permitida"], 404);
		}
		
		$contrato 				              	= \App\Models\ContratoPlano::find($id);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => "Contrato Plano não encontrado"], 404);
		}
		
		$contrato->plano_id 					= $request->plano_id;
		$contrato->sigla 						= $request->sigla;
		$contrato->save();
		
		return response()->json($contrato, 200); 
	}
	
	public function contrato_plano_store(Request $request)
    {
		if (!$request->user()->tokenCan('edit.contratos')) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }
		
		$validator = Validator::make($request->all(), [
			'contrato_id'						=> 'required|exists:contratos,id',
			'plano_id'							=> 'required|exists:planos,id',
			'sigla' 							=> 'required|string|max:20',
        ]);

        if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$contrato 				              	= \App\Models\ContratoPlano::where('contrato_id','=',$request->contrato_id)
																		   ->where('plano_id','=',$request->plano_id)
																		   ->first();					
		if (isset($contrato->id))
        {
			return response()->json(['error' => "Já existe o Contrato/Plano. Inclusão não permitida"], 404);
		}
		
		$contrato 				              	= \App\Models\ContratoPlano::where('contrato_id','=',$request->contrato_id)
																		   ->where('sigla','=',$request->sigla)
																		   ->first();					
		if (isset($contrato->id))
        {
			return response()->json(['error' => "Já existe o Contrato/Sigla. Inclusão não permitida"], 404);
		}
		
		$contrato 				              	= new \App\Models\ContratoPlano();
		$contrato->contrato_id 					= $request->contrato_id;
		$contrato->plano_id 					= $request->plano_id;
		$contrato->sigla 						= $request->sigla;
		$contrato->save();
		
		return response()->json($contrato, 200); 
	}
	
	public function contrato_plano_view(Request $request, $id)
    {
		if (!$request->user()->tokenCan('edit.contratos')) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }
		
		$contrato 				              	= \App\Models\ContratoPlano::find($id);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => "Contrato Plano não encontrado"], 404);
		}
		
		return response()->json($contrato, 200); 
	}
	
	public function contrato_plano_destroy(Request $request, $id)
    {
		if (!$request->user()->tokenCan('edit.contratos')) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar contratos.'], 403);
        }
		
		$contrato 				              	= \App\Models\ContratoPlano::find($id);
         
		if (!isset($contrato->id))
        {
			return response()->json(['error' => "Contrato Plano não encontrado"], 404);
		}
		
		$contrato->delete();
		return response()->json($contrato, 200); 
	}
}
