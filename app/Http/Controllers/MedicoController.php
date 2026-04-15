<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Medico;
use App\Models\ClinicaEspecialidade;
use App\Helpers\Cas;
use stdClass;
use DB;

class MedicoController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.medicos')) {
            return response()->json(['error' => 'Não autorizado para visualizar medicos.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

        $medicos                               = Medico::select('id','cnpj','nome','telefone','email','ativo','crm')
															->where('tipo','=','M')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $medicos->getCollection()->transform(function ($medico) 
        {
            if ($medico->agendamentos()->exists())
            {
                $medico->pexcluir 			= 0;
            }  else {
                $medico->pexcluir 			= 1;
            }  
            $medico->ativo                 = $medico->ativo_label;       
            $medico->telefone              = Cas::formatarTelefone($medico->telefone);                      
            return $medico;
         });
                    
         return response()->json($medicos, 200);
        
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.medicos')) {
            return response()->json(['error' => 'Não autorizado para visualizar medicos.'], 403);
        }

        $medico              				= Medico::find($id);
        
        if (!$medico) 
        {
            return response()->json(['error' => 'Medico não encontrado.'], 404);
        }

		$medico->especialidade              = ClinicaEspecialidade::where('clinica_id','=',$id)->first();			
        return response()->json($medico, 200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.medicos')) {
            return response()->json(['error' => 'Não autorizado para criar medicos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'cnpj'       		=> 'required|string|max:20|unique:clinicas,cnpj',
			'crm'				=> 'required',
            'nome'          	=> 'required|string|max:100',
            'telefone'      	=> 'required|string|max:15',
            'email'         	=> 'required|string|max:200|email',
            'cep'           	=> 'required|string|max:9',
            'logradouro'    	=> 'required|string|max:100',
            'numero'        	=> 'required|string|max:20',
            'complemento'   	=> 'nullable|string|max:100',
            'bairro'        	=> 'required|string|max:100',
            'cidade'        	=> 'required|string|max:100',
            'estado'        	=> 'required|string|max:2',
			'especialidade_id' 	=> 'required|exists:especialidades,id',
			'valor'             => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'ativo'         	=> 'required|boolean',
        ]);
    
        if ($validator->fails()) 
        {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        $validated                 		= $validator->validated();
		
		// Removendo os campos 'especialidade_id' e 'valor' do array validado
		$validated 						= collect($validated)->except(['especialidade_id', 'valor'])->toArray();
		$validated 						= array_merge($validated, ['tipo' => 'M']);
	   
        $medico                    		= Medico::create($validated);
		
		if (isset($medico->id))
		{
			$clinicae 			       	= new \App\Models\ClinicaEspecialidade();
			$clinicae->clinica_id	   	= $medico->id;
			$clinicae->especialidade_id	= $request->especialidade_id;
			$clinicae->valor 			= str_replace(",",".",$request->valor);
			$clinicae->save();
		}

        return response()->json($medico->id, 201);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.medicos')) {
            return response()->json(['error' => 'Não autorizado para atualizar medicos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'cnpj'       	=> 'required|string|max:20|unique:clinicas,cnpj,' . $id . ',id',
			'crm'			=> 'required',
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
			'especialidade_id' 	=> 'required|exists:especialidades,id',
			'valor'             => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'ativo'         => 'required|boolean'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        $validated                 = $validator->validated();
    
		// Removendo os campos 'especialidade_id' e 'valor' do array validado
		$validated = collect($validated)->except(['especialidade_id', 'valor'])->toArray();
		
        $medico                    = Medico::find($id);

        if (!$medico) {
            return response()->json(['error' => 'Medico não encontrado.'], 404);
        }
    
        $medico->update($validated);
	
		$especialidade              			= ClinicaEspecialidade::where('clinica_id','=',$id)->first();
		
		if (isset($especialidade->id))
		{
			$especialidade->especialidade_id	= $request->especialidade_id;
			$especialidade->valor 				= str_replace(",",".",$request->valor);
			$especialidade->save();
		} else {
			$especialidade 			       		= new \App\Models\ClinicaEspecialidade();
			$especialidade->clinica_id	   		= $id;
			$especialidade->especialidade_id	= $request->especialidade_id;
			$especialidade->valor 				= str_replace(",",".",$request->valor);
			$especialidade->save();
		}
        /*
        $retorno				= new stdClass();
		$retorno->id 			= $id;
        $retorno->cpfcnpj       = Cas::formatarCPFCNPJ($medico->cpfcnpj,$medico->tipo);
        $retorno->telefone      = Cas::formatarTelefone($medico->telefone);
        */
        return response()->json($id, 200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.medicos')) {
            return response()->json(['error' => 'Não autorizado para excluir medicos.'], 403);
        }

        $medico                    = Medico::find($id);

        if (!$medico) {
            return response()->json(['error' => 'Medico não encontrado.'], 404);
        }
    
        if ($medico->agendamentos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o medico, pois ele possui agendamentos vinculados.'], 400);
        }

        if ($medico->delete())
		{
			$clinicaespecialidade               = ClinicaEspecialidade::where('clinica_id','=',$id)->first();
            if (isset($clinicaespecialidade->id))
            {
                $clinicaespecialidade->delete();
            }   
		}
        return response()->json($id, 200);
    }

    public function obter_cep(Request $request)
    {
        $cep 					= $request->input('cep','');
        $endereco               = Cas::obterCep($cep);
        return response()->json($endereco, 200);
    }
}