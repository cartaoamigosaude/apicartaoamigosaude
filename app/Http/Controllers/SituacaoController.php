<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Situacao;
use App\Helpers\Cas;

class SituacaoController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.situacoes')) {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', 'nome');
        $conteudo            					= $request->input('conteudo', '');

        $situacoes                              = Situacao::select('id','nome','ativo')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $situacoes->getCollection()->transform(function ($situacao) 
        {
            if ($situacao->contratos()->exists()) 
            {
                $situacao->pexcluir 			= 0;
            }  else {
                $situacao->pexcluir 			= 1;
            }        
            $situacao->ativo                    = $situacao->ativo_label;                   
            return $situacao;
         });
                    
         return response()->json($situacoes, 200);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.situacoes')) {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

        $situacao       =  Situacao::select('id','nome','ativo')->find($id);

        if (!isset($situacao->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

        return response()->json($situacao,200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.situacoes')) {
            return response()->json(['error' => 'Não autorizado para criar situações.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  => 'required|string|max:100|unique:situacoes,nome',
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        // Se a validação passar, obtenha os dados validados
        $validated = $validator->validated();

        return Situacao::create($validated);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.situacoes')) {
            return response()->json(['error' => 'Não autorizado para atualizar situações.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  =>  [
                'required',
                'string',
                'max:100',
                // A regra unique agora exclui o registro com o ID atual
                'unique:situacoes,nome,' . $id . ',id',
            ],
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $situacao = Situacao::find($id);

        if (!isset($situacao->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

         // Se a validação passar, obtenha os dados validados
         $validated = $validator->validated();

        $situacao->update($validated);

        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.situacoes')) {
            return response()->json(['error' => 'Não autorizado para excluir situações.'], 403);
        }

        $situacao = Situacao::find($id);

        if (!isset($situacao->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

        if ($situacao->contratos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir a situação, pois há contratos vinculados a ela.'], 400);
        }

        $situacao->delete();
        return response()->json($id, 200);
    }
}
