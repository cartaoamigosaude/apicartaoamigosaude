<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Clinica;
use App\Models\Especialidade;
use App\Models\ClinicaEspecialidade;
use App\Helpers\Cas;
use stdClass;
use DB;

class ClinicaController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.clinicas')) {
            return response()->json(['error' => 'Não autorizado para visualizar clinicas.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

        $clinicas                               = Clinica::select('id','cnpj','nome','telefone','email','ativo')
															->where('tipo','=','C')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $clinicas->getCollection()->transform(function ($clinica) 
        {
            if ($clinica->agendamentos()->exists())
            {
                $clinica->pexcluir 			= 0;
            }  else {
                $clinica->pexcluir 			= 1;
            }  
            $clinica->ativo                 = $clinica->ativo_label;       
            $clinica->telefone              = Cas::formatarTelefone($clinica->telefone);                      
            return $clinica;
         });
                    
         return response()->json($clinicas, 200);
        
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.clinicas')) {
            return response()->json(['error' => 'Não autorizado para visualizar clinicas.'], 403);
        }

        $clinica              				= Clinica::find($id);
        
        if (!$clinica) 
        {
            return response()->json(['error' => 'Clinica não encontrado.'], 404);
        }

        return response()->json($clinica, 200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.clinicas')) {
            return response()->json(['error' => 'Não autorizado para criar clinicas.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'cnpj'       	=> 'required|string|max:20|unique:clinicas,cnpj',
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
			'mostrar_valor'  => 'required|boolean',
            'ativo'         => 'required|boolean',
        ]);
    
        if ($validator->fails()) 
        {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        $validated                  = $validator->validated();
		$validated 					= array_merge($validated, ['tipo' => 'C']);
	   
        $clinica                    = Clinica::create($validated);

        return response()->json($clinica->id, 201);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.clinicas')) 
		{
            return response()->json(['error' => 'Não autorizado para atualizar clinicas.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'cnpj'       	=> 'required|string|max:20|unique:clinicas,cnpj,' . $id . ',id',
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
			'mostrar_valor'  => 'required|boolean',
            'ativo'         => 'required|boolean'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        $validated                  = $validator->validated();
    
        $clinica                    = Clinica::find($id);

        if (!$clinica) {
            return response()->json(['error' => 'Clinica não encontrado.'], 404);
        }
    
        $clinica->update($validated);
        /*
        $retorno				= new stdClass();
		$retorno->id 			= $id;
        $retorno->cpfcnpj       = Cas::formatarCPFCNPJ($clinica->cpfcnpj,$clinica->tipo);
        $retorno->telefone      = Cas::formatarTelefone($clinica->telefone);
        */
        return response()->json($id, 200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.clinicas')) {
            return response()->json(['error' => 'Não autorizado para excluir clinicas.'], 403);
        }

        $clinica                    = Clinica::find($id);

        if (!$clinica) {
            return response()->json(['error' => 'Clinica não encontrado.'], 404);
        }
    
        if ($clinica->agendamentos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o clinica, pois ele possui agendamentos vinculados.'], 400);
        }

        $clinica->delete();
        return response()->json($id, 200);
    }

	public function clinica_especialidade_index(Request $request, $id)
	{
		$atende              = $request->input('atende', 'T');
		$limite              = $request->input('limite', 999);
		$orderby             = $request->input('orderby', 'especialidades.nome');
		$direction           = $request->input('direction', 'asc');
		$campo               = $request->input('campo', '');
		$conteudo            = $request->input('conteudo', '');
		
		$query 				 = DB::connection('mysql')->table('especialidades');
															   
		switch ($atende) 
		{
			case 'S':
				// Especialidades que a clínica ATENDE (com INNER JOIN)
				$query->select(
						'especialidades.id',
						'especialidades.nome',
						'clinica_especialidade.especialidade_id',
						'clinica_especialidade.valor_cliente',
						'clinica_especialidade.valor_clinica'
					)
					->join('clinica_especialidade', 'clinica_especialidade.especialidade_id', '=', 'especialidades.id')
					->where('clinica_especialidade.clinica_id', '=', $id);
				break;
				
			case 'N':
				// Especialidades que a clínica NÃO ATENDE (LEFT JOIN + WHERE NULL)
				$query->select(
						'especialidades.id',
						'especialidades.nome',
						DB::raw('null as especialidade_id'),
						DB::raw('0 as valor_cliente'),
						DB::raw('0 as valor_clinica')
					)
					->leftJoin('clinica_especialidade', function($join) use ($id) {
						$join->on('clinica_especialidade.especialidade_id', '=', 'especialidades.id')
							 ->where('clinica_especialidade.clinica_id', '=', $id);
					})
					->whereNull('clinica_especialidade.especialidade_id');
				break;
				
			case 'T':
				// TODAS as especialidades (LEFT JOIN sem WHERE NULL)
				$query->select(
						'especialidades.id',
						'especialidades.nome',
						'clinica_especialidade.especialidade_id',
						'clinica_especialidade.valor_cliente',
						'clinica_especialidade.valor_clinica'
					)
					->leftJoin('clinica_especialidade', function($join) use ($id) {
						$join->on('clinica_especialidade.especialidade_id', '=', 'especialidades.id')
							 ->where('clinica_especialidade.clinica_id', '=', $id);
					});
				break;
		}
		
		// Filtro por campo e conteúdo
		if (($campo != "") and ($conteudo != "")) 
		{
			$query->where($campo, 'like', "%$conteudo%");
		}
		
		$query->orderBy($orderby, $direction);
		$especialidades = $query->paginate($limite);
		
		// Transformação para adicionar o campo 'atende'
		$especialidades->getCollection()->transform(function ($especialidade) 
		{
			if ((isset($especialidade->especialidade_id)) and (!is_null($especialidade->especialidade_id)))
			{
				$especialidade->atende = 'Sim';
			} else {
				$especialidade->atende = 'Não';
			}
			return $especialidade;
		});
		
		return response()->json($especialidades, 200);
	}
	
    public function clinica_especialidade_store(Request $request, $id)
    {
        $atende              							= $request->input('atende', 'T');
        $retorno 	                        			= new stdClass;
		$retorno->id 									= $id;
		$retorno->atende 								= $atende;
		$retorno->especialidade_id						= $request->especialidade_id;
		$retorno->ids_salvos 	            			= [];
		$retorno->ids_erros  	            			= [];
		
        $index 				                			= 0;

        switch ($atende) 
		{
            case 'S':
                foreach ($request->especialidade_id as $especialidade_id) 
                {
                    $clinicaespecialidade               = \App\Models\ClinicaEspecialidade::where('clinica_id','=',$id)
                                                                                          ->where('especialidade_id','=',$especialidade_id)
                                                                                          ->first();
                    if (!isset($clinicaespecialidade->id))
                    {
                        $clinicae 			            = new \App\Models\ClinicaEspecialidade();
                        $clinicae->clinica_id	        = $id;
                        $clinicae->especialidade_id	    = $especialidade_id;
						$clinicae->valor_cliente 		= 0;
						$clinicae->valor_clinica 		= 0;
                        if ($clinicae->save())
						{
							$retorno->ids_salvos[]  	= $especialidade_id;
						}
                    }   
                }
                break;
            case 'N':
                foreach ($request->especialidade_id as $especialidade_id) 
                {
                    $clinicaespecialidade               = \App\Models\ClinicaEspecialidade::where('clinica_id','=',$id)
																						  ->where('especialidade_id','=',$especialidade_id)
																						  ->first(); 
                    if (isset($clinicaespecialidade->id))
                    {
                        if ($clinicaespecialidade->delete())
						{
							$retorno->ids_salvos[]  	= $especialidade_id;
						}
                    }   
                }
                break;
        }

        return response()->json($retorno, 200);

    }

	public function clinica_especialidade_valor(Request $request, $id)
    {
		$validator = Validator::make($request->all(), [
			'especialidade_id'			=> 'required|exists:especialidades,id',
         	'valor_cliente' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			'valor_clinica' 			=> 'required|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
        ]);

        if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
		
		$especialidade                   		= \App\Models\ClinicaEspecialidade::where('clinica_id','=',$id)
																				  ->where('especialidade_id','=',$request->especialidade_id)
																				  ->first();
																				  
																				  
		if (!isset($especialidade->id))
		{
			return response()->json(['error' => 'Clinica/Especialidade não encontrada'], 422);
		}
		
		$especialidade->valor_cliente 			= str_replace(',','.',$request->valor_cliente);
		$especialidade->valor_clinica 			= str_replace(',','.',$request->valor_clinica);
		$especialidade->save();
		
		 return response()->json(true, 200);
		
	}
	
    public function obter_cep(Request $request)
    {
        $cep 					= $request->input('cep','');
        $endereco               = Cas::obterCep($cep);
        return response()->json($endereco, 200);
    }
}