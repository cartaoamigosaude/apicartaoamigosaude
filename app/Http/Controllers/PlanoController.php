<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Plano;
use App\Models\Produto;
use App\Models\PlanoProduto;
use App\Helpers\Cas;
use DB;

class PlanoController extends Controller
{
    public function index(Request $request)
    {
        if ((!$request->user()->tokenCan('view.planos')) and (!$request->user()->tokenCan('view.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar planos.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', '');
        $conteudo            					= $request->input('conteudo', '');

        $planos                                 = Plano::with('periodicidade:id,nome as pnome','produtos')
                                                            ->select('id','nome','taxa_ativacao','preco','periodicidade_id','parcelas','qtde_beneficiarios','galaxPayId','clausulas','ativo')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $planos->getCollection()->transform(function ($plano) 
        {
            if ($plano->contratos()->exists()) 
            {
                $plano->pexcluir 			= 0;
            }  else {
                $plano->pexcluir 			= 1;
            }  
            $plano->qtde_produtos            = count($plano->produtos);
            $plano->ativo                    = $plano->ativo_label;    
            $plano->taxa_ativacao            = 'R$ ' .  str_replace(".",",",$plano->taxa_ativacao);     
            $plano->preco                    = 'R$ ' .  str_replace(".",",",$plano->preco);                       
            return $plano;
         });
                    
         return response()->json($planos, 200);
        
    }

    public function show(Request $request, $id)
    {
        if ((!$request->user()->tokenCan('view.planos')) and (!$request->user()->tokenCan('view.contratos'))) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar planos.'], 403);
        }

        $plano              = Plano::with('periodicidade:id,nome,periodicity')
                                    ->select('id','nome','formapagamento','taxa_ativacao','preco','periodicidade_id','qtde_beneficiarios','galaxPayId','parcelas','clausulas','token_loja','ativo')
                                    ->find($id);
        
        if (!isset($plano->id)) 
        {
            return response()->json(['error' => 'Plano não encontrado.'], 404);
        }

        $lprodutos           = Produto::select('id','nome')
                                        ->orderBy('nome')
                                        ->get();
        
        $produtos            = array();

        foreach ($lprodutos as $produto)
        {
            $planoproduto   = PlanoProduto::where('plano_id','=',$id)
                                          ->where('produto_id','=',$produto->id)
                                          ->first();
        
            if (isset($planoproduto->id))
            {
                $produto->incluso           = 1;
				$produto->beneficiario 		= $planoproduto->beneficiario;
            } else {
                $produto->incluso           = 0;
				$produto->beneficiario		= "";
            }
			
            $produtos[]                     = $produto;
        }

        $plano->produtos                    = $produtos;
        return response()->json($plano, 200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.planos')) {
            return response()->json(['error' => 'Não autorizado para criar planos.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'              => 'required|string|max:100|unique:planos,nome',
            'preco'             => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'taxa_ativacao'     => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'periodicidade_id'  => 'required|exists:periodicidades,id',
            'parcelas'          => 'required|integer|min:0',
            'qtde_beneficiarios'=> 'required|integer|min:1',
            'formapagamento'    => 'required',
			'galaxPayId'        => 'required|integer|min:0',
            'ativo'             => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		if (!isset($request->clausulas))
		{
			$request->clausulas			= "";
		}
		
        $plano            			    = new \App\Models\Plano();
		$plano->nome					= $request->nome; 
		$plano->preco		            = str_replace(",",".",$request->preco); 
        $plano->taxa_ativacao		    = str_replace(",",".",$request->taxa_ativacao); 
		$plano->periodicidade_id		= $request->periodicidade_id; 
		$plano->parcelas				= $request->parcelas; 
		$plano->qtde_beneficiarios 		= $request->qtde_beneficiarios; 
		$plano->galaxPayId 				= $request->galaxPayId; 
        $plano->formapagamento          = $request->formapagamento;
		$plano->clausulas				= $request->clausulas;
		$plano->token_loja				= $request->token_loja;
		$plano->ativo					= $request->ativo;
        
        if ($plano->save())
        {
            foreach($request->produtos as $produto)
            {
                if ($produto['incluso'] == 1)
                {
                    $rproduto 				= new \App\Models\PlanoProduto();
                    $rproduto->produto_id	= $produto['id'];
                    $rproduto->plano_id	    = $plano->id;
					$rproduto->beneficiario = $produto['beneficiario'];
                    $rproduto->save();
                }
            }
            return response()->json($plano, 200);
        }

        return response()->json(['error' => 'Ocorreu erro na tentativa de incluir'], 422);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.planos')) {
            return response()->json(['error' => 'Não autorizado para atualizar planos.'], 403);
        }

        $validator              = Validator::make($request->all(), [
            'nome'              => 'required|string|max:100|unique:planos,nome,' . $id . ',id',
            'preco'             => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'taxa_ativacao'     => 'required|min:0|regex:/^-?[0-9]+(?:.[0-9]{1,2})?$/',
            'periodicidade_id'  => 'required|exists:periodicidades,id',
            'parcelas'          => 'required|integer|min:0',
            'qtde_beneficiarios'=> 'required|integer|min:1',
			'galaxPayId'        => 'required|integer|min:0',
            'formapagamento'    => 'required',
            'ativo'             => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $plano                  		= Plano::find($id);

        if (!$plano) 
        {
            return response()->json(['error' => 'Plano não encontrado.'], 404);
        }

		if (!isset($request->clausulas))
		{
			$request->clausulas			= "";
		}
		
		$plano->nome					= $request->nome; 
		$plano->preco		            = str_replace(",",".",$request->preco); 
        $plano->taxa_ativacao		    = str_replace(",",".",$request->taxa_ativacao); 
		$plano->periodicidade_id		= $request->periodicidade_id; 
		$plano->parcelas				= $request->parcelas; 
		$plano->qtde_beneficiarios 		= $request->qtde_beneficiarios; 
		$plano->galaxPayId 				= $request->galaxPayId; 
        $plano->formapagamento          = $request->formapagamento;
		$plano->clausulas				= $request->clausulas;
		$plano->token_loja				= $request->token_loja;
		$plano->ativo					= $request->ativo;

		if ($plano->save())
		{
			$delete 			    	= DB::connection('mysql')->table('plano_produto')
												->where('plano_id','=',$id)
												->delete();

			foreach($request->produtos as $produto)
			{
				if ($produto['incluso'] == 1)
				{
					$rproduto 				= new \App\Models\PlanoProduto();
					$rproduto->produto_id	= $produto['id'];
					$rproduto->plano_id	    = $plano->id;
					$rproduto->beneficiario = $produto['beneficiario'];
					$rproduto->save();
				}
			}
		}
		
        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.planos')) {
            return response()->json(['error' => 'Não autorizado para excluir planos.'], 403);
        }

        $plano                  = Plano::find($id);

        if (!$plano) {
            return response()->json(['error' => 'Plano não encontrado.'], 404);
        }

        if ($plano->contratos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o plano, pois há contratos vinculados a ele.'], 400);
        }

        $plano->delete();
        $delete 			    = DB::connection('mysql')->table('plano_produto')
													->where('plano_id','=',$id)
													->delete();
        return response()->json($id, 200);
    }
}
