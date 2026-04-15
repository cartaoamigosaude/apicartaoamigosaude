<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Imagem;
use App\Helpers\Cas;

class ImagemController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.imagens')) {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', 'nome');
        $conteudo            					= $request->input('conteudo', '');

        $imagens                              = Imagem::select('id','nome','ativo','sequencia','imagem')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $imagens->getCollection()->transform(function ($Imagem) 
        {
            $Imagem->ativo                    = $Imagem->ativo_label;                   
            return $Imagem;
         });
                    
         return response()->json($imagens, 200);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.imagens')) {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

        $Imagem       =  Imagem::select('id','nome','ativo','sequencia')->find($id);

        if (!isset($Imagem->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

        return response()->json($Imagem,200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.imagens')) {
            return response()->json(['error' => 'Não autorizado para criar situações.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  => 'required|string|max:100|unique:imagens,nome',
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        // Se a validação passar, obtenha os dados validados
        $validated = $validator->validated();

        return Imagem::create($validated);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.imagens')) {
            return response()->json(['error' => 'Não autorizado para atualizar situações.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  =>  [
                'required',
                'string',
                'max:100',
                // A regra unique agora exclui o registro com o ID atual
                'unique:imagens,nome,' . $id . ',id',
            ],
            'ativo' => 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $Imagem = Imagem::find($id);

        if (!isset($Imagem->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

         // Se a validação passar, obtenha os dados validados
         $validated = $validator->validated();

        $Imagem->update($validated);

        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.imagens')) {
            return response()->json(['error' => 'Não autorizado para excluir situações.'], 403);
        }

        $Imagem = Imagem::find($id);

        if (!isset($Imagem->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }


        $Imagem->delete();
        return response()->json($id, 200);
    }
}
