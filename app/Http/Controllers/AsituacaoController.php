<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Asituacao;
use App\Helpers\Cas;

class AsituacaoController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->tokenCan('view.asituacoes')) {
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

        $limite              					= $request->input('limite', 10);
		$orderby             					= $request->input('orderby', 'nome');
		$direction          					= $request->input('direction', 'asc');
		$campo            					    = $request->input('campo', 'nome');
        $conteudo            					= $request->input('conteudo', '');

        $situacoes                              = Asituacao::select('id','nome','audio_url','ativo')
                                                            ->where(function ($query) use ($campo,$conteudo) {
                                                                if (($campo != "") and ($conteudo != "")) 
                                                                {
                                                                    $query->where($campo, 'like', "%$conteudo%");
                                                                }
                                                            })
                                                            ->orderBy($orderby,$direction)
                                                            ->paginate($limite);

        $situacoes->getCollection()->transform(function ($asituacao) 
        {
            if ($asituacao->agendamentos()->exists()) 
            {
                $asituacao->pexcluir 			= 0;
            }  else {
                $asituacao->pexcluir 			= 1;
            }     
			$asituacao->pexcluir 				= 0;			
            $asituacao->ativo                   = $asituacao->ativo_label;                   
            return $asituacao;
         });
                    
         return response()->json($situacoes, 200);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->tokenCan('view.asituacoes')) 
		{
            return response()->json(['error' => 'Não autorizado para visualizar situações.'], 403);
        }

        $asituacao       =  Asituacao::select('id','nome','orientacao','whatsapp','ativo')->find($id);

        if (!isset($asituacao->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

        return response()->json($asituacao,200);
    }

    public function store(Request $request)
    {
        if (!$request->user()->tokenCan('edit.asituacoes')) {
            return response()->json(['error' => 'Não autorizado para criar situações.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'nome'  		=> 'required|string|max:100|unique:situacoes,nome',
			'orientacao'	=> 'required',
			'whatsapp'		=> 'required',
			'whatsappc'		=> 'nullable',
            'ativo' 		=> 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }
    
        // Se a validação passar, obtenha os dados validados
        $validated = $validator->validated();

        return Asituacao::create($validated);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->tokenCan('edit.asituacoes')) {
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
			'orientacao'	=> 'required',
			'whatsapp'		=> 'required',
			'whatsappc'		=> 'nullable',
            'ativo' 		=> 'required|boolean',
        ]);
    
        // Se a validação falhar, retorne os erros
        if ($validator->fails()) {
            return response()->json(['error' => Cas::getMessageValidTexto($validator->errors())], 422);
        }

        $asituacao = Asituacao::find($id);

        if (!isset($asituacao->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

         // Se a validação passar, obtenha os dados validados
         $validated = $validator->validated();

        $asituacao->update($validated);

        return response()->json($id,200);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->tokenCan('delete.asituacoes')) {
            return response()->json(['error' => 'Não autorizado para excluir situações.'], 403);
        }

        $asituacao = Asituacao::find($id);

        if (!isset($asituacao->id))
        {
            return response()->json(['error' => 'Situação não encontrada.'], 404);
        }

        if ($asituacao->agendamentos()->exists()) {
            return response()->json(['error' => 'Não é possível excluir a situação, pois há contratos vinculados a ela.'], 400);
        }

        $asituacao->delete();
        return response()->json($id, 200);
    }
	
	public function upload_audio(Request $request)
	{
		$payload									= (object) $request->all();
		
		if ($request->hasFile('audio') && $request->file('audio')->isValid())
		{
			$file                       			= $request->audio;
			$codigo 								= bin2hex(random_bytes(6));
			$folderName								= 'audio' . '/' . $payload->asituacao_id;
			$originalName 							= $file->getClientOriginalName();
			$extension 			        			= $file->getClientOriginalExtension();
			$fileName 								= $codigo . '-' . $originalName;
			$destinationPath 						= public_path() . '/' . $folderName;
			$file->move($destinationPath, $fileName);
			
			$caminho                    			= url("/") . '/' . $folderName . '/' . $fileName;
		
			$asituacao 								= \App\Models\Asituacao::find($payload->asituacao_id);
																			 
			if (isset($asituacao->id))
			{
				$asituacao->audio_url 					= $caminho;
				$asituacao->save();
			}
		}
		
		return response()->json($payload, 200);
	}
	
	public function delete_audio(Request $request, $id)
	{
		
		$asituacao                    				= \App\Models\Asituacao::find($id);

        if (!isset($asituacao->id)) 
		{
            return response()->json(['error' => 'Situação não encontrado.'], 404);
        }
		
		$audio_url                                  = $asituacao->audio_url;
		$asituacao->audio_url 						= "";
		
		if ($asituacao->save())
		{
			// Verifica se existe audio_url antes de tentar excluir
			if (!empty($audio_url)) 
			{
				// Extrai o caminho relativo do arquivo da URL completa
				// De: "https://api.cartaoamigosaude.com.br/audio/4/187e169dbe28-01-carinha-de-anjo_42ZK9SxB.mp3"
				// Para: "audio/4/187e169dbe28-01-carinha-de-anjo_42ZK9SxB.mp3"
				$parsed_url 						= parse_url($audio_url);
				$file_path 							= ltrim($parsed_url['path'], '/');
				
				// Monta o caminho completo do arquivo no servidor
				$full_path 							= public_path($file_path);
				
				// Remove o arquivo fisicamente se ele existir
				if (file_exists($full_path)) 
				{
					unlink($full_path);
				}
			}
		}
		
		return response()->json(true, 200);
	}
}
