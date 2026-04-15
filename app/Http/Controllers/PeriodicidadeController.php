<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Periodicidade;
use App\Helpers\Cas;

class PeriodicidadeController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.periodicidades')) {
            return response()->json(['error' => 'Não autorizado para visualizar periodicidades.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', 'nome');
        $conteudo            					= $request->input('conteudo', '');

        $periodicidades                         = Periodicidade::select('id','nome','ativo')
                                                                ->where(function ($query) use ($campo,$conteudo) {
                                                                    if (($campo != "") and ($conteudo != "")) 
                                                                    {
                                                                        $query->where($campo, 'like', "%$conteudo%");
                                                                    }
                                                                })
                                                                ->orderBy($orderby,$direction)
                                                                ->paginate($limite);

        $periodicidades->getCollection()->transform(function ($periodicidade) 
        {
            if ($periodicidade->planos()->exists()) 
            {
                $periodicidade->pexcluir 		= 0;
            }  else {
                $periodicidade->pexcluir 		= 1;
            }      
            $periodicidade->ativo                    = $periodicidade->ativo_label;                        
            return $periodicidade;
         });
                    
         return response()->json($periodicidades, 200);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.periodicidades')) {
            return response()->json(['error' => 'Não autorizado para visualizar periodicidades.'], 403);
        }

        $periodicidade       =  Periodicidade::select('id','nome','ativo')->find($id);

        if (!isset($periodicidade->id))
        {
            return response()->json(['error' => 'Periodicidade não encontrada.'], 404);
        }

        return response()->json($periodicidade,200);
       
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.periodicidades')) {
            return response()->json(['error' => 'Não autorizado para criar periodicidades.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  => 'required|string|max:100|unique:periodicidades,nome',
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        // Se a validação passar, obtenha os dados validados
        $validated = $validator->validated();

        return Periodicidade::create($validated);

    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.periodicidades')) {
            return response()->json(['error' => 'Não autorizado para atualizar periodicidades.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  =>  [
                'required',
                'string',
                'max:100',
                // A regra unique agora exclui o registro com o ID atual
                'unique:periodicidades,nome,' . $id . ',id',
            ],
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $periodicidade = Periodicidade::find($id);

        if (!isset($periodicidade->id))
        {
            return response()->json(['error' => 'Periodicidade não encontrada.'], 404);
        }

         // Se a validação passar, obtenha os dados validados
        $validated = $validator->validated();

        $periodicidade->update($validated);

        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.periodicidades')) {
            return response()->json(['error' => 'Não autorizado para excluir periodicidades.'], 403);
        }

        $periodicidade = Periodicidade::find($id);

        if (!isset($periodicidade->id))
        {
            return response()->json(['error' => 'Periodicidade não encontrada.'], 404);
        }

        if ($periodicidade->planos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir a periodicidade, pois há planos vinculados a ela.'], 400);
        }

        $periodicidade->delete();
        return response()->json($id, 200);
    }
}

