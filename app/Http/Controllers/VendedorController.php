<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Vendedor;
use App\Helpers\Cas;
use DB;

class VendedorController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.vendedores')) {
            return response()->json(['error' => 'Não autorizado para visualizar vendedores.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', 'nome');
        $conteudo            					= $request->input('conteudo', '');

        $vendedores                              = Vendedor::select('id','nome','user_id','ativo')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $vendedores->getCollection()->transform(function ($vendedor) 
        {
            if ($vendedor->contratos()->exists()) 
            {
                $vendedor->pexcluir 			= 0;
            }  else {
                $vendedor->pexcluir 			= 1;
            }    
            $vendedor->ativo                    = $vendedor->ativo_label;                            
            return $vendedor;
         });
                    
         return response()->json($vendedores, 200);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.vendedores')) {
            return response()->json(['error' => 'Não autorizado para visualizar vendedores.'], 403);
        }

        $vendedor                               =  \App\Models\Vendedor::select('id','nome','ativo','user_id','token')->find($id);
		
        if (!isset($vendedor->id)) 
		{
            return response()->json(['error' => 'Vendedor não encontrado.'], 404);
        }

		$lplanos           						=  \App\Models\Plano::select('id','nome')
																	->where('galaxPayId','>', 0)
																	->where('ativo','=',1)
																	->orderBy('nome')
																	->get();
        
        $splanos            					= array();

        foreach ($lplanos as $plano)
        {
            $planovendedor   					= \App\Models\PlanoVendedor::where('vendedor_id','=',$id)
																		   ->where('plano_id','=',$plano->id)
																		   ->first();
        
            if (isset($planovendedor->id))
            {
                $plano->incluso           		= 1;
				$chave 							= "0" . $plano->id; 
            } else {
                $plano->incluso          		= 0;
				$chave 							= "1" . $plano->id; 
            }
			
            $splanos[$chave]                     		= $plano;
        }

		$planos            						= array();
		if (count($splanos) > 0)
		{		
			ksort($splanos);
			foreach ($splanos as $splano)
			{
				$planos[]						= $splano;
			}
		}
        $vendedor->planos                    	= $planos;
		
        return response()->json($vendedor,200);
    }
    
    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.vendedores')) {
            return response()->json(['error' => 'Não autorizado para criar vendedores.'], 403);
        }

		$validator = Validator::make($request->all(), [
			'nome'      => 'required|string|max:100|unique:vendedores,nome',
			'ativo'     => 'required|boolean',
			'user_id'   => 'required',
			'token'     => 'nullable',
		]);

		// Adiciona uma validação condicional para 'user_id'
		$validator->sometimes('user_id', 'exists:users,id', function ($input) {
			return $input->user_id > 0;
		});

        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

		$vendedor            					= new \App\Models\Vendedor();
		$vendedor->nome							= $request->nome; 
		$vendedor->user_id		       			= $request->user_id; 
		$vendedor->ativo						= $request->ativo;
        
		if (isset($request->token))
		{
			$vendedor->token					= $request->token;
		} else {
			$vendedor->token					= "";
		}
		
        if ($vendedor->save())
        {
			foreach($request->planos as $plano)
            {
                if ($plano['incluso'] == 1)
                {
                    $pvendedor 					= new \App\Models\PlanoVendedor();
                    $pvendedor->plano_id		= $plano['id'];
                    $pvendedor->vendedor_id	    = $vendedor->id;
                    $pvendedor->save();
                }
            }
		}
		
		return response()->json($vendedor, 200);
 
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.vendedores')) {
            return response()->json(['error' => 'Não autorizado para atualizar vendedores.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'      => 'required|string|max:100|unique:vendedores,nome,' . $id . ',id',
            'ativo'     => 'required|boolean',
            'user_id'   => 'required',
			'token'		=> 'nullable',
        ]);

		// Adiciona uma validação condicional para 'user_id'
		$validator->sometimes('user_id', 'exists:users,id', function ($input) {
			return $input->user_id > 0;
		});
		
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $vendedor 								= \App\Models\Vendedor::find($id);
		
        if (!$vendedor) {
            return response()->json(['error' => 'Vendedor não encontrado.'], 404);
        }

		$vendedor->nome							= $request->nome; 
		$vendedor->user_id		       			= $request->user_id; 
		$vendedor->ativo						= $request->ativo;
		
		if (isset($request->token))
		{
			$vendedor->token					= $request->token;
		} else {
			$vendedor->token					= "";
		}
        
        if ($vendedor->save())
        {
			$delete 			    			= DB::connection('mysql')->table('plano_vendedor')
													->where('vendedor_id','=',$id)
													->delete();
			foreach($request->planos as $plano)
            {
                if ($plano['incluso'] == 1)
                {
                    $pvendedor 					= new \App\Models\PlanoVendedor();
                    $pvendedor->plano_id		= $plano['id'];
                    $pvendedor->vendedor_id	    = $vendedor->id;
                    $pvendedor->save();
                }
            }
		}
        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.vendedores')) {
            return response()->json(['error' => 'Não autorizado para excluir vendedores.'], 403);
        }

        $vendedor = Vendedor::find($id);
        if (!$vendedor) {
            return response()->json(['error' => 'Vendedor não encontrado.'], 404);
        }

        if ($vendedor->contratos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir o vendedor, pois há contratos vinculados a ele.'], 400);
        }

        if ($vendedor->delete())
		{
			$delete 			    			= DB::connection('mysql')->table('plano_vendedor')
													->where('vendedor_id','=',$id)
													->delete();
		}
        return response()->json($id, 200);
    }

    public function combos(Request $request)
    {
        $tabelas           = $request->input('tabelas', '');
        $combo             = Cas::obterCombo($tabelas, $request->user()->id);
		$combo->id 		   = $request->user()->id;
        return response()->json($combo, 200);
    }
    
}
