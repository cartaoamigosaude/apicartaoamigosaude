<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Especialidade;
use App\Helpers\Cas;

class EspecialidadeController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.especialidades')) {
            return response()->json(['error' => 'Não autorizado para visualizar especialidades.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

        $especialidades                         = Especialidade::select('id','nome','tipo','ativo')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $especialidades->getCollection()->transform(function ($especialidade) 
        {
            if ($especialidade->agendamentos()->exists()) 
            {
                $especialidade->pexcluir 			= 0;
            }  else {
				if ($especialidade->clinicas()->exists()) 
				{
					$especialidade->pexcluir 		= 0;
				} else {
					$especialidade->pexcluir 		= 1;
				}
            }  
			if ($especialidade->tipo =='C')
			{
				$especialidade->dtipo				= 'Consulta';
			} else {
				$especialidade->dtipo				= 'Exame';
			}
            $especialidade->ativo                   = $especialidade->ativo_label;                          
            return $especialidade;
         });
                    
         return response()->json($especialidades, 200);
        
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.especialidades')) {
            return response()->json(['error' => 'Não autorizado para visualizar especialidades.'], 403);
        }

        $especialidade              				= Especialidade::select('id','nome','tipo','ativo')->find($id);
        
        if (!$especialidade) 
        {
            return response()->json(['error' => 'Especialidade não encontrado.'], 404);
        }

        return response()->json($especialidade, 200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.especialidades')) {
            return response()->json(['error' => 'Não autorizado para criar especialidades.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'              => 'required|string|max:100|unique:especialidades,nome',
			'tipo'          	=> 'required|string|max:1|in:C,E',
            'ativo'             => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $validated = $validator->validated();
        return Especialidade::create($validated);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.especialidades')) {
            return response()->json(['error' => 'Não autorizado para atualizar especialidades.'], 403);
        }

        $validator              = Validator::make($request->all(), [
            'nome'              => 'required|string|max:100|unique:especialidades,nome,' . $id . ',id',
			'tipo'          	=> 'required|string|max:1|in:C,E',
            'ativo'             => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $especialidade                  = Especialidade::find($id);

        if (!$especialidade) 
        {
            return response()->json(['error' => 'Especialidade não encontrado.'], 404);
        }

        $validated              = $validator->validated();
        $especialidade->update($validated);

        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.especialidades')) {
            return response()->json(['error' => 'Não autorizado para excluir especialidades.'], 403);
        }

        $especialidade                  = Especialidade::find($id);

        if (!$especialidade) {
            return response()->json(['error' => 'Especialidade não encontrado.'], 404);
        }

        if ($especialidade->agendamentos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o especialidade, pois há agendamentos vinculados a ele.'], 400);
        }
		
		if ($especialidade->clinicas()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o especialidade, pois há clinicas vinculadas a ele.'], 400);
        }

        $especialidade->delete();
        return response()->json($id, 200);
    }
}
