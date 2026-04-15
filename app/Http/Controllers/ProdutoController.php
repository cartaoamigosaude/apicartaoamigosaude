<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Produto;
use App\Helpers\Cas;

class ProdutoController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.produtos')) {
            return response()->json(['error' => 'Não autorizado para visualizar produtos.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

        $produtos                               = Produto::select('id','nome','descricao','ativo','ativacao','desativacao','valor_por_vida','dias','imagem','sequencia')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $produtos->getCollection()->transform(function ($produto) 
        {
            if ($produto->planos()->exists()) 
            {
                $produto->pexcluir 			= 0;
            }  else {
                $produto->pexcluir 			= 1;
            }
			switch ($produto->ativacao) 
			{
				case "N": 
					$produto->ativacao   = 'Não ativar';
				    break;
				case "A": 
					$produto->ativacao   = 'Automatico';
				    break;
				case "U": 
					$produto->ativacao   = 'Pelo app';
				    break;	
				case "M": 
					$produto->ativacao   = 'Manual';
				    break;
			}		
			switch ($produto->desativacao) 
			{
				case "N": 
					$produto->desativacao   = 'Não desativar';
				    break;
				case "A": 
					$produto->desativacao   = 'Automatico';
				    break;
				case "M": 
					$produto->desativacao   = 'Manual';
				    break;
			}					
            $produto->ativo                 = $produto->ativo_label;                          
            return $produto;
         });
                    
         return response()->json($produtos, 200);
        
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.produtos')) {
            return response()->json(['error' => 'Não autorizado para visualizar produtos.'], 403);
        }

        $produto              = Produto::select('id','nome','descricao','ativo','ativacao','desativacao','dias','sequencia','imagem','corretorId','planoId','produtoId')->find($id);
        
        if (!$produto) 
        {
            return response()->json(['error' => 'Produto não encontrado.'], 404);
        }

        return response()->json($produto, 200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.produtos')) {
            return response()->json(['error' => 'Não autorizado para criar produtos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'              => 'required|string|max:100|unique:produtos,nome',
            'descricao'         => 'required',
            'ativo'             => 'required|boolean',
			'ativacao'			=> 'required|in:A,M,N,U',
			'desativacao'		=> 'required|in:A,M,N',
			'dias'				=> 'required',
			'corretorId'		=> 'required',
			'planoId'			=> 'required',
			'produtoId'			=> 'required',
			'valor_por_vida'    => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			'sequencia'			=> 'required'
        ]);

		if ($validator->fails()) 
		{
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }


		$produto            	= new \App\Models\Produto();
		$produto->nome			= $request->nome;
		$produto->descricao		= $request->descricao;
		$produto->ativo			= $request->ativo;
		$produto->sequencia		= $request->sequencia;
		$produto->controle		= 'O';
		$produto->titulo		= $request->nome;
		$produto->ativacao		= $request->ativacao;
		$produto->desativacao	= $request->desativacao;
		$produto->dias			= $request->dias;
		$produto->corretorId	= $request->corretorId;
		$produto->produtoId		= $request->produtoId;
		$produto->planoId		= $request->planoId;
		$produto->orientacao	= 'Na palma da mão';
		$produto->valor_por_vida= str_replace(",",".",$request->valor_por_vida); 
		$produto->imagem		= "";
		$produto->ajuda			= "";
        $produto->save();  
       
        return response()->json($produto, 200);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.produtos')) {
            return response()->json(['error' => 'Não autorizado para atualizar produtos.'], 403);
        }

        $validator              = Validator::make($request->all(), [
            'nome'              => 'required|string|max:100|unique:produtos,nome,' . $id . ',id',
			'descricao'         => 'required',
            'ativo'             => 'required|boolean',
			'ativacao'			=> 'required|in:A,M,N,U',
			'desativacao'		=> 'required|in:A,M,N',
			'dias'				=> 'required',
			'corretorId'		=> 'required',
			'planoId'			=> 'required',
			'produtoId'			=> 'required',
			'valor_por_vida'    => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
			'sequencia'			=> 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $produto                  = Produto::find($id);

        if (!$produto) 
        {
            return response()->json(['error' => 'Produto não encontrado.'], 404);
        }

        $produto->nome			= $request->nome;
		$produto->descricao		= $request->descricao;
		$produto->ativo			= $request->ativo;
		$produto->sequencia		= $request->sequencia;
		$produto->ativacao		= $request->ativacao;
		$produto->desativacao	= $request->desativacao;
		$produto->dias			= $request->dias;
		$produto->corretorId	= $request->corretorId;
		$produto->produtoId		= $request->produtoId;
		$produto->planoId		= $request->planoId;
		$produto->valor_por_vida= str_replace(",",".",$request->valor_por_vida); 
        $produto->save();  

        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.produtos')) {
            return response()->json(['error' => 'Não autorizado para excluir produtos.'], 403);
        }

        $produto                  = Produto::find($id);

        if (!$produto) {
            return response()->json(['error' => 'Produto não encontrado.'], 404);
        }

        if ($produto->planos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o produto, pois há planos vinculados a ele.'], 400);
        }

        $produto->delete();
        return response()->json($id, 200);
    }
}
