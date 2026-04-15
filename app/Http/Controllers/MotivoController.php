<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Motivo;
use App\Helpers\Cas;

class MotivoController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.motivos')) {
            return response()->json(['error' => 'Não autorizado para visualizar motivos.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', 'nome');
        $conteudo            					= $request->input('conteudo', '');

        $motivos                                = Motivo::select('id','nome','ativo')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $motivos->getCollection()->transform(function ($motivo) 
        {
            if ($motivo->contratos()->exists()) 
            {
                $motivo->pexcluir 			= 0;
            }  else {
                $motivo->pexcluir 			= 1;
            }        
            $motivo->ativo                    = $motivo->ativo_label;                   
            return $motivo;
         });
                    
         return response()->json($motivos, 200);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.motivos')) {
            return response()->json(['error' => 'Não autorizado para visualizar motivos.'], 403);
        }

        $motivo       =  Motivo::select('id','nome','ativo')->find($id);

        if (!isset($motivo->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

        return response()->json($motivo,200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.motivos')) {
            return response()->json(['error' => 'Não autorizado para criar motivos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  => 'required|string|max:100|unique:motivos,nome',
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        // Se a validação passar, obtenha os dados validados
        $validated = $validator->validated();

        return Motivo::create($validated);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.motivos')) {
            return response()->json(['error' => 'Não autorizado para atualizar motivos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  =>  [
                'required',
                'string',
                'max:100',
                // A regra unique agora exclui o registro com o ID atual
                'unique:motivos,nome,' . $id . ',id',
            ],
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $motivo = Motivo::find($id);

        if (!isset($motivo->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

         // Se a validação passar, obtenha os dados validados
         $validated = $validator->validated();

        $motivo->update($validated);

        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.motivos')) {
            return response()->json(['error' => 'Não autorizado para excluir motivos.'], 403);
        }

        $motivo = Motivo::find($id);

        if (!isset($motivo->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

        if ($motivo->contratos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir a situação, pois há contratos vinculados a ela.'], 400);
        }

        $motivo->delete();
        return response()->json($id, 200);
    }
}
