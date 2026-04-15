<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Parcela;
use App\Models\Beneficiario;
use App\Helpers\Cas;
use DB;
use stdClass;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.clientes')) {
            return response()->json(['error' => 'Não autorizado para visualizar clientes.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

		$payload								= (object) $request->all();

        $query                               	= Cliente::select('id','tipo','cpfcnpj','nome','telefone','email','saldo','ativo','cor_primaria','cor_secundaria','url_logo')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            });
		if (isset($payload->campos))
		{
			$query                              = Cas::montar_filtro($query, $payload);
		}
		
		$query->orderBy($orderby,$direction);
		$clientes								= $query->paginate($limite);
		 
        $clientes->getCollection()->transform(function ($cliente) 
        {
            if (($cliente->contratos()->exists()) or ($cliente->beneficiarios()->exists()))
            {
                $cliente->pexcluir 				= 'N';
            }  else {
				$beneficiario               	= \App\Models\Beneficiario::where('cliente_id','=',$cliente->id)->first();
				if (isset($beneficiario->id))
				{
					 $cliente->pexcluir 		= 'N';
				} else {
					$cliente->pexcluir 			= 'S';
				}
            }  
            $cliente->ativo                    = $cliente->ativo_label;       
            $cliente->telefone                 = Cas::formatarTelefone($cliente->telefone);                      
            return $cliente;
         });
                    
         return response()->json($clientes, 200);
        
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.clientes')) {
            return response()->json(['error' => 'Não autorizado para visualizar clientes.'], 403);
        }

        $cliente              = Cliente::find($id);
        
        if (!$cliente) 
        {
            return response()->json(['error' => 'Cliente não encontrado.'], 404);
        }

        return response()->json($cliente, 200);
    }

    public function store(Request $request)
    {
        //if (!$request->user()->tokenCan('edit.clientes')) {
        //    return response()->json(['error' => 'Não autorizado para criar clientes.'], 403);
        //}

        $validator = Validator::make($request->all(), [
            'tipo'          => 'required|string|max:1|in:F,J',
            'cpfcnpj'       => 'required|string|max:20|unique:clientes,cpfcnpj',
            'nome'          => 'required|string|max:100',
            'telefone'      => 'required|string|max:15',
            'email'         => 'required|string|max:200|email',
            'cep'           => 'required|string|max:9',
            'logradouro'    => 'required|string|max:100',
            'numero'        => 'required|string|max:20',
            'complemento'   => 'nullable|string|max:100',
            'bairro'        => 'required|string|max:100',
            'cidade'        => 'required|string|max:100',
            'estado'        => 'required|string|max:2',
			//'saldo' 		=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			//'cor_primaria'	=> 'required|max:7',
			//'cor_secundaria'=> 'required|max:7',
            'ativo'         => 'required|boolean',
            'observacao'    => 'nullable|string',
        ]);
    
         // Validação condicional para CPF/CNPJ, sexo e data_nascimento com base no tipo
         $validator->after(function ($validator) use ($request) {
            if ($request->tipo === 'F') {
                // Preencher CPF com zeros à esquerda se estiver incompleto
				$cpf							= preg_replace('/\D/', '', $request->cpfcnpj);
                $cpf 							= str_pad($cpf, 11, '0', STR_PAD_LEFT);
                $request->merge(['cpfcnpj' => $cpf]); // Atualiza o valor do request
                // Validação para CPF (11 dígitos)
                $validator->addRules(['cpfcnpj' => 'required|digits:11|unique:clientes,cpfcnpj']);
                // Validação para sexo (somente 'M' ou 'F')
                $validator->addRules(['sexo' => 'required|string|in:M,F']);
                // Validação para data de nascimento (formato de data válido)
                $validator->addRules(['data_nascimento' => 'required|date']);
            } elseif ($request->tipo === 'J') {
                // Preencher CNPJ com zeros à esquerda se estiver incompleto
				$cnpj							= preg_replace('/\D/', '', $request->cpfcnpj);
                $cnpj 							= str_pad($cnpj, 14, '0', STR_PAD_LEFT);
                $request->merge(['cpfcnpj' => $cnpj]); // Atualiza o valor do request
                // Validação para CNPJ (14 dígitos)
                $validator->addRules(['cpfcnpj' => 'required|digits:14|unique:clientes,cpfcnpj']);
            }
        });

        if ($validator->fails()) 
        {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        $validated                  = $validator->validated();
        if ((!isset($request->observacao)) or (is_null($request->observacao)))
        {
            $validated['observacao']= "Inserido pelo CRM";    
        }
		if ((isset($validated['saldo'])) and (is_numeric($validated['saldo'])))
		{
			$validated['saldo'] 	= str_replace(',', '.', $validated['saldo']);
		}
		if (isset($validated['cor_primaria']))
		{
			$validated['cor_primaria'] 		= $validated['cor_primaria'];
		}
		if (isset($validated['cor_secundaria']))
		{
			$validated['cor_secundaria'] 	= $validated['cor_secundaria'];
		}
        $cliente                    = Cliente::create($validated + $request->only(['data_nascimento', 'sexo']));

        return response()->json($cliente->id, 201);
    }

    public function update(Request $request, $id)
    {
        //if (!$request->user()->tokenCan('edit.clientes')) {
        //    return response()->json(['error' => 'Não autorizado para atualizar clientes.'], 403);
        //}

        $validator = Validator::make($request->all(), [
            'tipo'          => 'required|string|max:1|in:F,J',
            'cpfcnpj'       => 'required|string|max:20|unique:clientes,cpfcnpj,' . $id . ',id',
            'nome'          => 'required|string|max:100',
            'telefone'      => 'required|string|max:15',
            'email'         => 'required|string|max:200|email',
            'cep'           => 'required|string|max:9',
            'logradouro'    => 'required|string|max:100',
            'numero'        => 'required|string|max:20',
            'complemento'   => 'nullable|string|max:100',
            'bairro'        => 'required|string|max:100',
            'cidade'        => 'required|string|max:100',
            'estado'        => 'required|string|max:2',
			//'saldo' 		=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			//'cor_primaria'	=> 'required|max:7',
			//'cor_secundaria'=> 'required|max:7',
            'ativo'         => 'required|boolean',
            'observacao'    => 'nullable|string',
        ]);
    
       // Validação condicional para CPF/CNPJ, sexo e data_nascimento com base no tipo
       $validator->after(function ($validator) use ($request, $id) {
            if ($request->tipo === 'F') {
                // Preencher CPF com zeros à esquerda se estiver incompleto
				$cpf							= preg_replace('/\D/', '', $request->cpfcnpj);
                $cpf 							= str_pad($cpf, 11, '0', STR_PAD_LEFT);
                $request->merge(['cpfcnpj' => $cpf]); // Atualiza o valor do request
                // Validação para CPF (11 dígitos) durante a atualização, ignorando o CPF do cliente atual
                $validator->addRules(['cpfcnpj' => 'required|digits:11|unique:clientes,cpfcnpj,' . $id]);
                // Validação para sexo (somente 'M' ou 'F')
                $validator->addRules(['sexo' => 'required|string|in:M,F']);
                // Validação para data de nascimento (formato de data válido)
                $validator->addRules(['data_nascimento' => 'required|date']);
            } elseif ($request->tipo === 'J') {
                // Preencher CNPJ com zeros à esquerda se estiver incompleto
				$cnpj							= preg_replace('/\D/', '', $request->cpfcnpj);
                $cnpj 							= str_pad($cnpj, 14, '0', STR_PAD_LEFT);
                $request->merge(['cpfcnpj' => $cnpj]); // Atualiza o valor do request
                // Validação para CNPJ (14 dígitos) durante a atualização, ignorando o CNPJ do cliente atual
                $validator->addRules(['cpfcnpj' => 'required|digits:14|unique:clientes,cpfcnpj,' . $id]);
            }
        });
    
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
		if ($request->tipo === 'F') 
		{
			$cpfcnpj							= preg_replace('/\D/', '', $request->cpfcnpj);
            $cpfcnpj 							= str_pad($cpfcnpj, 11, '0', STR_PAD_LEFT);
		} else {
			$cpfcnpj							= preg_replace('/\D/', '', $request->cpfcnpj);
            $cpfcnpj 							= str_pad($cpfcnpj, 14, '0', STR_PAD_LEFT);
		}
		
		$cliente                    				= Cliente::where('cpfcnpj','=', $cpfcnpj)
															   ->where('id','<>',$id)
															   ->first();

		if (isset($cliente->id)) 
		{
			return response()->json(['error' => 'O CPF/CNPJ já está cadastrado para o cliente: ' . $cliente->nome ], 404);
		}
		  
        $validated                  = $validator->validated();
    
        $cliente                    = Cliente::find($id);

        if (!isset($cliente->id)) {
            return response()->json(['error' => 'Cliente não encontrado.'], 404);
        }
    
        if ((!isset($request->observacao)) or (is_null($request->observacao)))
        {
            unset($validated['observacao']);
        }

		if ($request->tipo =='F')
		{
			$cliente->sexo							= $request->sexo;
			$cliente->data_nascimento				= $request->data_nascimento;
			$cpf									= preg_replace('/\D/', '', $request->cpfcnpj);
            $cpf 									= str_pad($cpf, 11, '0', STR_PAD_LEFT);
			$cliente->cpfcnpj						= $cpf;
		} else {
			$cnpj									= preg_replace('/\D/', '', $request->cpfcnpj);
            $cnpj 									= str_pad($cnpj, 14, '0', STR_PAD_LEFT);
			$cliente->cpfcnpj						= $cnpj;
		}
		
		$cliente->tipo								= $request->tipo;
        $cliente->nome								= $request->nome;
        $cliente->telefone							= $request->telefone;
        $cliente->email								= $request->email;
        $cliente->cep								= $request->cep;
        $cliente->logradouro						= $request->logradouro;
        $cliente->numero							= $request->numero;
        $cliente->complemento						= $request->complemento;
        $cliente->bairro							= $request->bairro;
        $cliente->cidade							= $request->cidade;
        $cliente->estado							= $request->estado;
		if ((isset($request->saldo)) and (is_numeric($request->saldo)))
		{
			$cliente->saldo 						= str_replace(",",".",$request->saldo);
		}
		if (isset($request->cor_primaria))
		{
			$cliente->cor_primaria 					= $request->cor_primaria;
		}
		if (isset($request->cor_secundaria))
		{
			$cliente->cor_secundaria 				= $request->cor_secundaria;
		}
		$cliente->ativo								= $request->ativo;
		$cliente->save();
		
       // $cliente->update($validated + $request->only(['data_nascimento', 'sexo']));
        /*
        $retorno				= new stdClass();
		$retorno->id 			= $id;
        $retorno->cpfcnpj       = Cas::formatarCPFCNPJ($cliente->cpfcnpj,$cliente->tipo);
        $retorno->telefone      = Cas::formatarTelefone($cliente->telefone);
        */
        return response()->json($id, 200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.clientes')) {
            return response()->json(['error' => 'Não autorizado para excluir clientes.'], 403);
        }

        $cliente                    = Cliente::find($id);

        if (!$cliente) {
            return response()->json(['error' => 'Cliente não encontrado.'], 404);
        }
    
        if ($cliente->contratos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o cliente, pois ele possui contratos vinculados.'], 400);
        }

        if ($cliente->beneficiarios()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o cliente, pois ele possui beneficiarios vinculados.'], 400);
        }
    
        $cliente->delete();
        return response()->json($id, 200);
    }

	public function storeUpdate(Request $request)
    {
		//if (!$request->user()->tokenCan('edit.clientes')) 
		//{
        //    return response()->json(['error' => 'Não autorizado para atualizar clientes.'], 403);
		//  'tipo'          => 'required|string|max:1|in:F,J',
        //}
		
		$validator = Validator::make($request->all(), [
          
            'cpfcnpj'       => 'required|string|max:20',
            'nome'          => 'required|string|max:100',
            'telefone'      => 'required|string|max:15',
            'email'         => 'required|string|max:200|email',
            'cep'           => 'required|string|max:9',
            'logradouro'    => 'required|string|max:100',
            'numero'        => 'required|string|max:20',
            'complemento'   => 'nullable|string|max:100',
            'bairro'        => 'required|string|max:100',
            'cidade'        => 'required|string|max:100',
            'estado'        => 'required|string|max:2'
        ]);
		
		if ((!isset($request->tipo)) or (is_null($request->tipo)))
		{
			$request->tipo = 'F';
		}
		
        if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		if ($request->tipo == 'F')
		{
			$cpfcnpj				= preg_replace('/\D/', '', $request->cpfcnpj);
			$cpfcnpj 				= str_pad($cpfcnpj, 11, '0', STR_PAD_LEFT);
			
			$validator = Validator::make($request->all(), [
				'sexo'            => 'required|string|max:1|in:M,F',
				'data_nascimento' => 'required|date',
			]);
			
			if ($validator->fails()) 
			{
				return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
			}
		} else {
			  $cpfcnpj				= preg_replace('/\D/', '', $request->cpfcnpj);
			  $cpfcnpj 				= str_pad($cpfcnpj, 14, '0', STR_PAD_LEFT);
		}
		
		$achou 										= false;
		
		if ((isset($request->id)) and ($request->id > 0))
		{
			$cliente               					= Cliente::find($request->id);
			
			if (isset($cliente->id))
			{
				$ccpfcnpj							= preg_replace('/\D/', '', $cliente->cpfcnpj);
				$ccpfcnpj 							= str_pad($ccpfcnpj, 11, '0', STR_PAD_LEFT);
				if ($ccpfcnpj == $cpfcnpj)
				{
					$achou 							= true;
				}
			}
		} 
		
		if (!$achou)
		{
			$cliente               					= \App\Models\Cliente::where('cpfcnpj','=',$cpfcnpj)->first();
			
			if (isset($cliente->id))
			{
				$achou 								= true;
			}
		}
		
		if (!$achou)
		{
			$cliente            					= new \App\Models\Cliente();
			$cliente->cpfcnpj 						= $cpfcnpj;
			$cliente->ativo 						= true;
		}
		 
		if ($request->tipo =='F')
		{
			$cliente->sexo							= $request->sexo;
			$cliente->data_nascimento				= $request->data_nascimento;
		}
		
		$cliente->tipo								= $request->tipo;
        $cliente->nome								= $request->nome;
        $cliente->telefone							= $request->telefone;
        $cliente->email								= $request->email;
        $cliente->cep								= $request->cep;
        $cliente->logradouro						= $request->logradouro;
        $cliente->numero							= $request->numero;
        $cliente->complemento						= $request->complemento;
        $cliente->bairro							= $request->bairro;
        $cliente->cidade							= $request->cidade;
        $cliente->estado							= $request->estado;
		$cliente->save();
			
		return response()->json($cliente->id, 200);
	}
	
	public function upload_logo(Request $request, $id)
	{
		
		$cliente                    				= Cliente::find($id);

        if (!isset($cliente->id)) 
		{
            return response()->json(['error' => 'Cliente não encontrado.'], 404);
        }
		
		if ($request->hasFile('logo') && $request->file('logo')->isValid())
		{
			$file                       			= $request->logo;
			$codigo 								= bin2hex(random_bytes(6));
			$folderName								= 'logo' . '/' . $id;
			$originalName 							= $file->getClientOriginalName();
			$extension 			        			= $file->getClientOriginalExtension();
			$fileName 								= $codigo . '-' . $originalName;
			$destinationPath 						= public_path() . '/' . $folderName;
			$file->move($destinationPath, $fileName);
			$caminho                    			= url("/") . '/' . $folderName . '/' . $fileName;
			$cliente->url_logo 						= $caminho;
			$cliente->save();
		}
		
		return response()->json(true, 200);
	}
	
	public function delete_logo(Request $request, $id)
	{
		
		$cliente                    				= Cliente::find($id);

        if (!isset($cliente->id)) 
		{
            return response()->json(['error' => 'Cliente não encontrado.'], 404);
        }
		
		$cliente->url_logo 							= "";
		$cliente->save();
		
		return response()->json(true, 200);
	}
	
    public function obter_cep(Request $request)
    {
        $cep 					= $request->input('cep','');
        $endereco               = Cas::obterCep($cep);
        return response()->json($endereco, 200);
    }

    public function cpfcnpj(Request $request)
    {
        
        if ((!$request->user()->tokenCan('view.contratos')) and (!$request->user()->tokenCan('view.agendamentos')) and (!$request->user()->tokenCan('view.beneficiarios')))
		{
            return response()->json(['mensagem' => 'Não autorizado para visualizar clientes.'], 403);
        }

        $tipo 					        = $request->input('tipo','');
        $cpfcnpj 					    = $request->input('cpfcnpj','');

        $cpfcnpj                        = str_replace(array('.','-','/'), '', $cpfcnpj);

        $retorno 				        = new stdClass();
        $retorno->tipo                  = "";
        $retorno->cliente_id            = 0;
        $retorno->nome                  = "";
        $retorno->cpfcnpj               = $cpfcnpj;
		$retorno->situacao 				= "";
		$retorno->mensagem 				= "";

		if ($tipo == 'F') 
		{
            $cpfcnpj                    = str_pad($cpfcnpj, 11, '0', STR_PAD_LEFT);
			if (!Cas::validarCpf($cpfcnpj))
			{
				$retorno->situacao 		= "";
				$retorno->mensagem 		= "O CPF informado não é válido.";
				return response()->json($retorno, 200);
			}
        } else  {
            $cpfcnpj                    = str_pad($cpfcnpj, 14, '0', STR_PAD_LEFT);
        } 
		
        $cliente 	                    = DB::connection('mysql')
                                                ->table('clientes')
                                                ->select('id','tipo','nome','sexo','data_nascimento','telefone','email','cep','logradouro','numero','complemento','bairro','cidade','estado')
                                                ->where('cpfcnpj','=',$cpfcnpj)
                                                ->first();   
        if (!isset($cliente->id))
        {
			$retorno->situacao 			= "A";
			$retorno->mensagem 			= "Não existe o cadastro do cliente. Favor informar os dados para realizar o cadastro do cliente!";
			return response()->json($retorno, 200);
		}
		
        $retorno->tipo              	= $cliente->tipo;
        $retorno->cliente_id        	= $cliente->id;
        $retorno->nome              	= $cliente->nome;
		$retorno->sexo              	= $cliente->sexo;
		$retorno->data_nascimento   	= $cliente->data_nascimento;
		$retorno->telefone				= $cliente->telefone;
        $retorno->email					= $cliente->email;
        $retorno->cep					= $cliente->cep;
        $retorno->logradouro			= $cliente->logradouro;
        $retorno->numero				= $cliente->numero;
        $retorno->complemento			= $cliente->complemento;
        $retorno->bairro				= $cliente->bairro;
        $retorno->cidade				= $cliente->cidade;
        $retorno->estado				= $cliente->estado;
			
		$contrato                   	= \App\Models\Contrato::where('cliente_id','=',$cliente->id)
															->whereIn('status',array('active','waitingPayment'))
															->first();

		if (isset($contrato->id))
		{
			$contrato_id    			= $contrato->id;
			$retorno->situacao 			= "E";
			$retorno->mensagem 			= 'Já existe o contrato ID ' . $contrato_id . " ativo para este cliente. Novo contrato não permitido.";
			return response()->json($retorno, 200);
		}
		
		$contratos 						= array();
		
		$lcontratos                   	= \App\Models\Contrato::where('cliente_id','=',$cliente->id)
															  ->where('status','=','canceled')
															  ->orderBy('created_at','desc')
															  ->get();

		$inadimplente 					= false;
		
		foreach ($lcontratos as $contrato)
		{
			$reg 				        = new stdClass();
			$reg->id					= $contrato->id;
			$reg->assinado 				= "";
			$reg->valor                 = $contrato->valor;
			$reg->data                  = substr($contrato->updated_at,0,10);
			$reg->parcelas 				= array();
			
			if (!is_null($contrato->contractacceptedAt))
			{
				list($ano,$mes,$dia) 	= explode("-",substr($contrato->contractacceptedAt,0,10));
				$reg->assinado			= $dia . "/" . $mes . "/". $ano . " " . substr($contrato->contractacceptedAt,11,05);
			}
			
			$lparcelas 					= \App\Models\Parcela::where('contrato_id','=',$contrato->id)
															   ->orderBy('nparcela','desc') 
															   ->get();
			
			foreach ($lparcelas as $parcela)
			{
				if (!isset($reg->statusDescription))
				{
					$reg->statusDescription 	= $parcela->statusDescription;
				}
				$preg 				    = new stdClass();
				$preg->id				= $parcela->id;
				$preg->nparcela			= $parcela->nparcela;
				$preg->data_vencimento	= $parcela->data_vencimento;
				$preg->data_pagamento	= $parcela->data_pagamento;
				$preg->data_baixa		= $parcela->data_baixa;
				$preg->valor 			= $parcela->valor;
				$reg->parcelas[]		= $preg;
			}
			
			$contratos[] 				= $reg;
			//if (($contrato->motivo == 'I') and (!is_null($contrato->contractacceptedAt)))
			//{
				if (\App\Models\Parcela::where('contrato_id','=',$contrato->id)
								   ->where('data_pagamento','<>',null)
								   ->where('galaxPayId','>',0)
								   ->count() > 0)
				{
					$inadimplente			= true;
				}
			//}				
		}
			
		$retorno->ccontratos 			= $contratos;
			
		if ($inadimplente)
		{
			$retorno->situacao 			= "A";
			$retorno->mensagem 			= 'Existe o contrato ID ' . $contrato->id . " cancelado por inadimplência para este cliente. Analise os contratos para tomar a descisão.";
			return response()->json($retorno, 200);
		}
		
		if (count($retorno->ccontratos) > 0)
		{
			$retorno->situacao 			= "A";
			$retorno->mensagem 			= "Verifique os contratos cancelados.";
			return response()->json($retorno, 200);
		}
		
		$beneficiario 		   			= \App\Models\Beneficiario::with('contrato')
																  ->where('cliente_id','=',$cliente->id)
																  ->where('ativo','=',1)
																  ->first();
		if (isset($beneficiario->id))
		{
			if ($beneficiario->tipo =='T')
			{
				$tipo 					= 'Titular';
			} else {
				$tipo 					= 'Dependente';
			}
			if ($beneficiario->contrato->tipo =='F')
			{
				$tipoc 					= 'PFisica';
			} else {
				$tipoc 					= 'PJuridica';
			}
			$retorno->situacao 			= "E";
			$retorno->mensagem 			= 'O Cliente é beneficiário ' . $tipo . ' ativo no contrato ID ' . $beneficiario->contrato_id . "  tipo de contrato: " . $tipoc . ". Entre em contato com o financeiro.";
			return response()->json($retorno, 200);
		}
		
		$retorno->situacao 				= "A";
		$retorno->mensagem 				= 'A situação do cliente foi verificada com sucesso. Novo contrato permitido!';
		return response()->json($retorno, 200);
	}
	
	public function beneficiario(Request $request)
    {
        
        if (!$request->user()->tokenCan('view.clientes')) {
            return response()->json(['error' => 'Não autorizado para visualizar clientes.'], 403);
        }

        $tipo 					        = $request->input('tipo','F');
        $cpf					    	= $request->input('cpf','');

        $cpf                       	 	= str_replace(array('.','-','/'), '', $cpf);

        $retorno 				        = new stdClass();
        $retorno->tipo                  = "";
        $retorno->nome                  = "";
        $retorno->cpf              		= $cpf;
	    
        $cpf                    		= str_pad($cpf, 11, '0', STR_PAD_LEFT);
		
		if (!Cas::validarCpf($cpf))
		{
			$retorno->mensagem 		= "O CPF informado não é válido.";
			return response()->json($retorno, 200);
		}
       
        $cliente 	                    = DB::connection('mysql')
                                                ->table('clientes')
                                                ->select('id','tipo','nome','sexo','data_nascimento','telefone','email','cep','logradouro','numero','complemento','bairro','cidade','estado')
                                                ->where('cpfcnpj','=',$cpf)
                                                ->first();   
        if (!isset($cliente->id))
        {
			$retorno->mensagem 			= "CPF informado não cadastrado";
			return response()->json($retorno, 200);
		}
		
        $retorno->nome              			= $cliente->nome;
		$retorno->sexo              			= $cliente->sexo;
		$retorno->data_nascimento   			= $cliente->data_nascimento;
		$retorno->telefone						= $cliente->telefone;
        $retorno->email							= $cliente->email;
        $retorno->cep							= $cliente->cep;
        $retorno->logradouro					= $cliente->logradouro;
        $retorno->numero						= $cliente->numero;
        $retorno->complemento					= Cas::nulltoSpace($cliente->complemento);
        $retorno->bairro						= $cliente->bairro;
        $retorno->cidade						= $cliente->cidade;
        $retorno->estado						= $cliente->estado;
		$retorno->status_beneficiario 			= "";			
		$retorno->status_contrato				= "";
		$retorno->status_pagamento 				= "";
		$retorno->dias_vencido 					= 0;
		$retorno->qtde_beneficiarios			= 0;
		
		$beneficiario 		   					= \App\Models\Beneficiario::with('contrato')
																		 ->where('cliente_id','=',$cliente->id)
																		 ->where('desc_status', '=', 'ATIVO')
																		 ->orderBy('id','desc')
																		 ->first();
		
		if (isset($beneficiario->id))
		{
			$retorno->tipo						= $beneficiario->tipo;
			$retorno->status_beneficiario       = substr(ucfirst(strtolower($beneficiario->desc_status)),0,1);
			$retorno->status_contrato           = substr(Cas::obterSituacaoContrato($beneficiario->contrato->status),0,1); 
		} 
		
		if ($retorno->tipo == 'T')
		{
			$query									= DB::connection('mysql')
																		->table('beneficiarios')
																		->select('clientes.cpfcnpj as cpf',
																				 'clientes.nome as nome',
																				 'clientes.data_nascimento',
																				 DB::raw('TIMESTAMPDIFF(YEAR, clientes.data_nascimento, CURDATE()) as idade'),
																				 'clientes.sexo')
																		->join('clientes','beneficiarios.cliente_id','=','clientes.id');
																		
			if ($beneficiario->contrato->tipo == 'F')
			{
				$query->where('beneficiarios.contrato_id','=',$beneficiario->contrato_id);
				$plano              				= \App\Models\Plano::select('id','nome','qtde_beneficiarios')->find($beneficiario->contrato->plano_id);
			} else {
				$query->where('beneficiarios.parent_id','=',$beneficiario->id);
				$plano              				= \App\Models\Plano::select('id','nome','qtde_beneficiarios')->find($beneficiario->plano_id);
			}		
			
			$query->where('beneficiarios.desc_status', '=', 'ATIVO');
			$query->where('beneficiarios.tipo','=','D');
			$retorno->dependentes					= $query->get();		
				
			if (isset($plano->id))
			{
				$retorno->qtde_beneficiarios  		= ($plano->qtde_beneficiarios - count($retorno->dependentes)) - 1;
			}
		} else {
			if ($retorno->tipo == 'D')
			{
				$retorno->cpf_titular 			= "";
				if ($beneficiario->contrato->tipo == 'J')
				{
					$titular 		   					= \App\Models\Beneficiario::with('contrato','cliente')->find($beneficiario->parent_id);
		
					if (isset($titular->id))
					{
						$retorno->cpf_titular 		 	= preg_replace('/\D/', '', $titular->cliente->cpfcnpj);
						$retorno->status_contrato    	= substr(Cas::obterSituacaoContrato($titular->contrato->status),0,1); 
					}
				} else {
					$titular 		   					= \App\Models\Beneficiario::with('contrato','cliente')->where('contrato_id','=',$beneficiario->contrato_id)->where('tipo','=','T')->first();
		
					if (isset($titular->id))
					{
						$retorno->cpf_titular 			= preg_replace('/\D/', '', $titular->cliente->cpfcnpj);
						$retorno->status_contrato    	= substr(Cas::obterSituacaoContrato($titular->contrato->status),0,1);  
					}
				}
			}
		}
		
		if ($retorno->tipo !="")
		{
			$parcela 							= \App\Models\Parcela::where('contrato_id','=',$beneficiario->contrato_id)
																	 ->where('data_pagamento','=',null)
																	 ->where('data_vencimento','<',date('Y-m-d'))
																	 ->orderBy('data_vencimento','asc')
																	 ->first();
		
			if (!isset($parcela->id))
			{
				$retorno->status_pagamento 		= 'A';
			} else {
				$date 							= $parcela->data_vencimento. " 23:59:59";
				$vencimento 					= Carbon::createFromDate($date);
				$now 							= Carbon::now();
				$diferenca 						= $vencimento->diffInDays($now);
				$retorno->dias_vencido			= $diferenca;
				
				if ($diferenca >= 30)
				{
					$retorno->status_pagamento 	= 'A';
				} else {
					$retorno->status_pagamento 	= 'I';
				}
			}
		}
		
		return response()->json($retorno, 200);
	}
        
}