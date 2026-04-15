<?php

namespace App\Services\Cas;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use App\Helpers\CelCash;
use App\Helpers\ChatHot;
use App\Helpers\Cas;
use App\Helpers\Conexa;
use App\Helpers\ClubeCerto;
use App\Helpers\Epharma;
use App\Jobs\ChatHotJob;
use App\Jobs\CelCashParcelasAvulsaJob;
use Carbon\Carbon;
use PDF;
use DB;
use stdClass;

class BeneficiarioService
{

	public static function getStoreClienteBeneficiario($beneficiario)
	{
		
		 $cpf                        	= str_replace(array('.','-','/'), '', $beneficiario->cpf);	 
		 $cpf                   		= str_pad($cpf, 11, '0', STR_PAD_LEFT);
		  
		 $cliente                    	= \App\Models\Cliente::where('cpfcnpj','=',$cpf)
															 ->where('tipo','=','F')
															 ->first();

		 if (!isset($cliente->id)) 
		 {
			 $cliente 					= new \App\Models\Cliente();
			 $cliente->tipo 			= 'F';
			 $cliente->cpfcnpj 			= $cpf;
			 $cliente->nome 			= $beneficiario->nome;
			 $cliente->sexo 			= 'M'; //$beneficiario->sexo;
			 $cliente->data_nascimento	= $beneficiario->data_nascimento;
			 $cliente->telefone			= "";
			 $cliente->email			= "";
			 $cliente->cep				= "";
			 $cliente->logradouro		= "";
			 $cliente->numero			= "";
			 $cliente->complemento		= "";
			 $cliente->bairro			= "";
			 $cliente->cidade			= "";
			 $cliente->estado			= "";
			 $cliente->galaxPayId		= 0;
			 $cliente->ativo			= true;
			 $cliente->observacao		= "";
			 $cliente->save();
		 }
		 
		return $cliente;
	}

	public static function storeClienteTitular($titular)
	{
		
		$cep 							= $titular->CEP;
        $endereco               		= Cas::obterCep($cep);
		
		$cpf                        	= str_replace(array('.','-','/'), '', $titular->CPF);	 
		$cpf                   			= str_pad($cpf, 11, '0', STR_PAD_LEFT);
		  
		$cliente                    	= \App\Models\Cliente::where('cpfcnpj','=',$cpf)->first();
															 
		if (!isset($cliente->id))
		{
			$cliente            		= new \App\Models\Cliente();
			$cliente->cpfcnpj 			= $cpf;
			$cliente->ativo 			= true;
		}
		
		list($dia,$mes,$ano) 			= explode("/", $titular->NASCIMENTO);
		$cliente->tipo					= 'F';
		$cliente->nome					= $titular->NOME;
		$cliente->data_nascimento		= "$ano-$mes-$dia";
		$cliente->telefone				= $titular->TELEFONE;
		$cliente->sexo					= $titular->SEXO;
		$cliente->email					= $titular->EMAIL;
		$cliente->cep					= $titular->CEP;
		$cliente->estado				= $titular->ESTADO;
		$cliente->cidade				= $titular->CIDADE;
		$cliente->bairro				= $titular->BAIRRO;
		$cliente->complemento			= $titular->COMPLEMENTO;
		$cliente->logradouro			= $titular->LOGRADOURO;
		$cliente->numero				= $titular->NUMERO;
		
		if ($endereco->ok == 'S')
		{
			if (($titular->ESTADO== "") and ($endereco->estado !=""))
			{
				$cliente->estado		= $titular->ESTADO;
			}
			if (($titular->CIDADE == "") and ($endereco->cidade !=""))
			{
				$cliente->cidade		= $titular->CIDADE;
			}
			if (($titular->BAIRRO == "") and ($endereco->bairro !=""))
			{
				$cliente->bairro		= $titular->BAIRRO;
			}
			if (($titular->LOGRADOURO == "") and ($endereco->endereco !=""))
			{
				$cliente->logradouro	= $titular->LOGRADOURO;
			}
		}
		$cliente->save();
		return $cliente;
	}

	public static function storeUpdateCliente($request)
	{
		$cpfcnpj									= preg_replace('/\D/', '', $request->cpfcnpj);
		$cpfcnpj 									= str_pad($cpfcnpj, 11, '0', STR_PAD_LEFT);
		$achou 										= false;
		
		if ((isset($request->cliente_id)) and ($request->cliente_id > 0))
		{
			$cliente               					= \App\Models\Cliente::find($request->cliente_id);
			
			if (isset($cliente->id))
			{
				$ccpfcnpj							= preg_replace('/\D/', '', $cliente->cpfcnpj);
				
				if ($ccpfcnpj == $cpfcnpj)
				{
					$achou 							= true;
				}
			}
		} 
		
		if (!$achou)
		{
			$cliente               					= \App\Models\Cliente::where('cpfcnpj','=',$cpfcnpj)->first();
			
			if (isset($cliente->id))
			{
				$achou 								= true;
			}
		}
		
		if (!$achou)
		{
			$cliente            					= new \App\Models\Cliente();
			$cliente->cpfcnpj 						= $cpfcnpj;
			$cliente->ativo 						= true;
		}
		
		if ($request->tipo == 'F')
		{
			$cliente->sexo							= $request->sexo;
			$cliente->data_nascimento				= $request->data_nascimento;
		} 
		
		$cliente->tipo								= $request->tipo;
        $cliente->nome								= $request->nome;
        $cliente->telefone							= $request->telefone;
		if ((!isset($request->email)) or ($request->email == "") or (is_null($request->email)))
		{
        	$request->email							= 'sememail@cartaoamigosaude.com.br';
		}
		$cliente->email 							= $request->email;
        $cliente->cep								= $request->cep;
        $cliente->logradouro						= $request->logradouro;
        $cliente->numero							= $request->numero;
        $cliente->complemento						= $request->complemento;
        $cliente->bairro							= $request->bairro;
        $cliente->cidade							= $request->cidade;
        $cliente->estado							= $request->estado;
		
		if ($cliente->save())
		{
			 //$ccliente                            	= CelCash::storeCliente($cliente->id);
		}
		
		return $cliente;
	}

	public static function storeClienteTitularLote($beneficiarios)
	{
		$beneficiariosProcessados				= array();
		
		foreach ($beneficiarios as $index => $beneficiarioData) 
		{
			$retorno 								= $beneficiarioData;
			$titular 								= (object) $beneficiarioData;
			$cpf 									= str_replace(array('.','-','/'), '', $titular->CPF);	 
			$cpf 									= str_pad($cpf, 11, '0', STR_PAD_LEFT);
			
			$cliente                    			= \App\Models\Cliente::where('cpfcnpj','=',$cpf)->first();
															 
			if (!isset($cliente->id))
			{
				$cliente            				= new \App\Models\Cliente();
				$cliente->cpfcnpj 					= $cpf;
			} 
			
			list($dia,$mes,$ano) 				= explode("/", $titular->NASCIMENTO);
			$cliente->tipo						= 'F';
			$cliente->nome						= $titular->NOME;
			$cliente->data_nascimento			= "$ano-$mes-$dia";
			$cliente->telefone					= $titular->TELEFONE;
			$cliente->sexo						= $titular->SEXO;
			$cliente->email						= $titular->EMAIL;
			$cliente->cep						= $titular->CEP;
			$cliente->estado					= $titular->ESTADO;
			$cliente->cidade					= $titular->CIDADE;
			$cliente->bairro					= $titular->BAIRRO;
			$cliente->complemento				= $titular->COMPLEMENTO;
			$cliente->logradouro				= $titular->LOGRADOURO;
			$cliente->numero					= $titular->NUMERO;
			$cliente->ativo 					= true;
			if ($cliente->save())
			{
				$retorno['cliente_id'] 			= $cliente->id; 	
			} else {
				$retorno['erro'] 				= 'Não foi criado ou atualizado o cliente';
				$retorno['cliente_id']  		= 0;
			}					
			$beneficiariosProcessados[]			= $retorno;
		}
		
		return $beneficiariosProcessados;
		
	}

	public static function permiteProdutoBeneficio($beneficiario_id,$produto_id)
	{
		
		$retorno 									= new stdClass();
        $retorno->ok                    			= "N";
		
		$beneficiario                              	= \App\Models\Beneficiario::with('contrato')->find($beneficiario_id);

        if (!isset($beneficiario->id))
        {
			$retorno->mensagem 						= 'Não encontrado o beneficiario, permissão';
			return $retorno;
		}
		
		$tipo										= $beneficiario->tipo;
		
		if ($beneficiario->contrato->tipo == 'F')
		{
			 $plano_id              				= $beneficiario->contrato->plano_id;
		} else {
			if ($beneficiario->tipo == 'D')
			{
				$beneficiario                       = \App\Models\Beneficiario::where('id','=',$beneficiario->parent_id)->first();
				if (!isset($beneficiario->id))
				{
					$retorno->mensagem 				= 'Não encontrado o titular ou beneficiario, permissão';
					return $retorno;
				}
			}
			$plano_id              					= $beneficiario->plano_id;
		}
				
		$planoproduto  								= \App\Models\PlanoProduto::where('plano_id','=',$plano_id)
																			  ->where('produto_id','=',$produto_id)
																			  ->whereIn('beneficiario',array('A',$tipo))
																			  ->first();
		if (!isset($planoproduto->id))
		{
			//$retorno->mensagem 						= "Produto " . $produto_id . " não autorizado para o tipo de beneficiário " . $beneficiario->tipo . ' no plano ' . $plano_id ;
			$retorno->mensagem 						= "Beneficio não autorizado para o tipo de beneficiário.";
			return $retorno;
		}
		
		$retorno->ok                    			= "S";
		return $retorno;
	}

	public static function beneficiarioProduto($beneficiario_id,$produto_id)
	{
		$contrato_id 								= 0;
		$contrato_tipo								= "";
		$tipo										= "";
		$plano_id 									= 0;
		
		$beneficiario 								= \App\Models\Beneficiario::with('contrato')->find($beneficiario_id);
		
		if (isset($beneficiario->id))
		{
			$contrato_id							= $beneficiario->contrato->id;
			$contrato_tipo 							= $beneficiario->contrato->tipo;
			
			$tipo									= $beneficiario->tipo;
			if ($beneficiario->contrato->tipo == 'F')
			{
				$plano_id              				= $beneficiario->contrato->plano_id;
			} else {
				if ($tipo == 'T')
				{
					$plano_id              			= $beneficiario->plano_id;
				} else {
					$beneficiario 					= \App\Models\Beneficiario::find($beneficiario->parent_id);
		
					if (isset($beneficiario->id))
					{
						$plano_id          			= $beneficiario->plano_id;;
					}
				}
			}		
		}
		
		if ($plano_id > 0)
		{
			$permitido  							= \App\Models\PlanoProduto::where('produto_id','=',$produto_id)
																			  ->where('plano_id','=',$plano_id)
																			  ->whereIn('beneficiario',array('A',$tipo))
																			  ->first();
																			  
			if (isset($permitido->id))
			{
				return true;
			}
		}
		
		return false;
	}

	public static function planoBeneficiarioTipo($beneficiario_id,$ativar=0)
	{
		
		$retorno 									= new stdClass();
		$retorno->plano_id 							= 0;
		$retorno->tipo 								= "";
		$retorno->dependentes 						= array();
		
		$contrato_id 								= 0;
		$contrato_tipo								= "";
		
		$beneficiario 								= \App\Models\Beneficiario::with('contrato')->find($beneficiario_id);
		
		if (isset($beneficiario->id))
		{
			$contrato_id							= $beneficiario->contrato->id;
			$contrato_tipo 							= $beneficiario->contrato->tipo;
			
			$retorno->tipo							= $beneficiario->tipo;
			if ($beneficiario->contrato->tipo == 'F')
			{
				$retorno->plano_id              	= $beneficiario->contrato->plano_id;
			} else {
				if ($beneficiario->tipo == 'T')
				{
					$retorno->plano_id              = $beneficiario->plano_id;
				} else {
					$beneficiario 					= \App\Models\Beneficiario::find($beneficiario->parent_id);
		
					if (isset($beneficiario->id))
					{
						$retorno->plano_id          = $beneficiario->plano_id;;
					}
				}
			}		
		}
		
		if (($retorno->tipo == 'T') and ($retorno->plano_id > 0))
		{
			if ($contrato_tipo == 'F')
			{	
				$retorno->dependentes 				= \App\Models\Beneficiario::with('cliente')
																			  ->where('contrato_id','=',$contrato_id)
																			  ->where('tipo','=','D')
																			  ->where('ativo','=',$ativar)
																			  ->get();
			} else {
				$retorno->dependentes 				= \App\Models\Beneficiario::with('cliente')
																			  ->where('parent_id','=',$beneficiario_id)
																			  ->where('tipo','=','D')
																			  ->where('ativo','=',$ativar)
																			  ->get();
			}
		}
		
		return $retorno;
	}

	public static function ativarDesativarBeneficiario($beneficiario_id,$ativar)
	{
		$ativadesativa										= array();
		
		$planotipo 											= Cas::planoBeneficiarioTipo($beneficiario_id,$ativar);
		
		if ($planotipo->plano_id > 0)
		{
			$produtos 										= Cas::obterBeneficios($planotipo->plano_id,$planotipo->tipo, $beneficiario_id);
			if (count($produtos) > 0)
			{
				$payload 									= new stdClass();
				$payload->beneficiario_id					= $beneficiario_id;
				$payload->ativar 							= $ativar;
				$payload->produtos 							= $produtos;
				$ativardesativar							= $payload;
				$ativardesativar->produtos					= Cas::ativarDesativarProdutos($payload);
				$ativadesativa[]							= $ativardesativar;
				if ($planotipo->tipo == 'T')
				{
					if (count($planotipo->dependentes) > 0)
					{
						foreach ($planotipo->dependentes as $dependente)
						{
							$produtos 						= Cas::obterBeneficios($planotipo->plano_id,'D', $dependente->id);
							if (count($produtos) > 0)
							{
								$payload 					= new stdClass();
								$payload->beneficiario_id	= $beneficiario_id;
								$payload->ativar 			= $ativar;
								$payload->produtos 			= $produtos;
								$ativardesativar			= $payload;
								$ativardesativar->produtos	= Cas::ativarDesativarProdutos($payload);
								$ativadesativa[]			= $ativardesativar;
							}
						}
					}
				}
			}
		}
		
		return $ativadesativa;
	}

	public static function ativarDesativarProdutos($payload)
	{
		$produtos 									= array();
		
		foreach ($payload->produtos as $produto)
		{
			if (!is_object($produto)) 
			{
				$produto 							= (object) $produto;
			}
			
			switch ($produto->id) 
			{
				case 1: /* Desconto em Consultas & Exames */
					break;
				case 2: /* Clube Certo*/
					$associate 						= ClubeCerto::ativarDesativarBeneficiario($payload->beneficiario_id,$produto->id,$payload->ativar);
					if ($associate->ok =='S')
					{
						$produto->ok				= 'S';
						$produto->ativacao			= $associate->ativacao;
						$produto->data_ativacao		= $associate->data_ativacao;
						$produto->data_desativacao	= $associate->data_desativacao;
			
						if ($payload->ativar)
						{
							$produto->ativacao		= 'Sim';
							$produto->mensagem 		= 'Ativado com sucesso!';
						} else {
							$produto->ativacao		= 'Não';
							$produto->mensagem 		= 'Desativado com sucesso!';
						}
					} else {
						$produto->ok				= 'N';
						$produto->mensagem 			= $associate->mensagem;
					}
					break;
				case 3: /* Epharma*/
					$associate 						= Epharma::ativarDesativarBeneficiario($payload->beneficiario_id,$produto->id,$payload->ativar);
					if ($associate->ok =='S')
					{
						$produto->ok				= 'S';
						$produto->ativacao			= $associate->ativacao;
						$produto->data_ativacao		= $associate->data_ativacao;
						$produto->data_desativacao	= $associate->data_desativacao;
			
						if ($payload->ativar)
						{
							$produto->ativacao		= 'Sim';
							$produto->mensagem 		= 'Ativado com sucesso!';
						} else {
							$produto->ativacao		= 'Não';
							$produto->mensagem 		= 'Desativado com sucesso!';
						}
					} else {
						$produto->ok				= 'N';
						$produto->mensagem 			= $associate->mensagem;
					}
					break;
				case 4: /* Telemedicina (Conexa)*/
					$associate 						= Conexa::ativarDesativarBeneficiario($payload->beneficiario_id,$produto->id,$payload->ativar);
					if ($associate->ok =='S')
					{
						$produto->ok				= 'S';
						$produto->ativacao			= $associate->ativacao;
						$produto->data_ativacao		= $associate->data_ativacao;
						$produto->data_desativacao	= $associate->data_desativacao;
			
						if ($payload->ativar)
						{
							$produto->ativacao		= 'Sim';
							$produto->mensagem 		= 'Ativado com sucesso!';
						} else {
							$produto->ativacao		= 'Não';
							$produto->mensagem 		= 'Desativado com sucesso!';
						}
					} else {
						$produto->ok				= 'N';
						$produto->mensagem 			= $associate->mensagem;
					}
					break;
				case 5: /* Seguro funeral*/
					break;
			}
			$produtos[]								= $produto;
		}

		return $produtos;
	}

	public static function ativarDesativarProduto($beneficiario_id,$produto_id,$ativar=true,$id="")
	{
		$retorno 										= new stdClass();
        $retorno->ok                    				= "N";
		$retorno->ativacao								= false;
		$retorno->data_ativacao							= date("Y-m-d H:i:s");
		$retorno->data_desativacao						= date("Y-m-d H:i:s");
		
		$beneficiarioproduto  							= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario_id)
																						 ->where('produto_id','=',$produto_id)
																						 ->first();														 
		if (!$ativar)
		{
			if (isset($beneficiarioproduto))
			{
				$beneficiarioproduto->ativacao			= $retorno->ativacao;
				$beneficiarioproduto->data_desativacao	= $retorno->data_desativacao;
				$beneficiarioproduto->idintegracao		= $id;
				$beneficiarioproduto->data_fim 			= date("Y-m-d");
				if ($beneficiarioproduto->save())
				{
					 $retorno->ok                    	= "S";
				} else {
					 $retorno->ok                    	= "N";
					 $retorno->mensagem 				= "Ocorreu problema na tentativa de desativar na base";
				}
			} else {
				 $retorno->ok                    		= "S";
			}
			
		} else {
			if (!isset($beneficiarioproduto))
			{
				$beneficiarioproduto 					= new \App\Models\BeneficiarioProduto();
				$beneficiarioproduto->beneficiario_id	= $beneficiario_id;
				$beneficiarioproduto->produto_id		= $produto_id;
			}
			$beneficiarioproduto->idintegracao			= $id;
			$beneficiarioproduto->ativacao				= true;
			$beneficiarioproduto->data_ativacao			= $retorno->data_ativacao;
			$beneficiarioproduto->data_desativacao		= null;
			$beneficiarioproduto->data_inicio 			= date("Y-m-d");
			$beneficiarioproduto->data_fim 				= '2999-12-31';
			if ($beneficiarioproduto->save())
			{
				$retorno->ok                    		= "S";
			} else {
				$retorno->ok                    		= "N";
				$retorno->mensagem 						= "Ocorreu problema na tentativa de ativar na base";
			}
			$retorno->ativacao							= true;
			$retorno->data_desativacao					= null;
		}
		
		return $retorno;
	}

	public static function gerarLinkMagico($beneficiario_id,$produto_id,$ip)
	{
		$retorno               						= new stdClass;
		$retorno->ok								= 'N';
		$retorno->link								= "";
		
		$cbeneficiario                   			= \App\Models\Beneficiario::with('contrato')->find($beneficiario_id);
				
		if (!isset($cbeneficiario->id))
		{
			$plano_id								= 0;
		} else {
			$tipo                          			= $cbeneficiario->contrato->tipo ?? 'F';
			if ($tipo == 'F')
			{
				$plano_id 							= $cbeneficiario->contrato->plano_id ?? 0;
			} else {
				if ($cbeneficiario->tipo == 'T')
				{
					$plano_id 						= $cbeneficiario->plano_id;
				} else {
					$dbeneficiario                  = \App\Models\Beneficiario::where('id','=',$cbeneficiario->parent_id)->first();
					if (isset($dbeneficiario->id))
					{	
						$plano_id 					= $dbeneficiario->plano_id;
					} else {
						$plano_id					= 0;
					}
				}
			}
		}
			
		$beneficiarioproduto  						= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario_id)
																					 ->where('produto_id','=',$produto_id)
																					 ->first();														 
		if ((!isset($beneficiarioproduto->id)) or ($beneficiarioproduto->ativacao==0))
		{
			$retorno->ok							= 'N';
			$retorno->mensagem						= "É necessário ativar o produto antes";
			return $retorno;
		}
		
		switch ($produto_id)
		{
			case 1:  /* desconto consultas e examens*/ 		
				break;
			case 2:  /* Clube de desconto */
				$beneficiario  						= \App\Models\Beneficiario::with('cliente')->find($beneficiario_id);							 
				if (!isset($beneficiario->id))
				{
					$retorno->ok					= 'N';
					$retorno->mensagem				= "Beneficiário não encontrato";
					return $retorno;
				}
			    $retorno->ok						= 'S';
				$cpf 								= preg_replace('/\D/', '',$beneficiario->cliente->cpfcnpj);
			    $retorno->link 						= config('services.clubecerto.api_url') . '/?cpf=' . $cpf . "&showHeader=false&showFooter=false";
			    $beneficiarioproduto->magiclink		= $retorno->link;
				$retorno->linkweb                   = $retorno->link;
			    $beneficiarioproduto->save();
			    return $retorno;
			case 3:  /* desconto em farmacias */
			   break;
			case 4:  /* Telemedicina*/
			
			    /* verificar se já existe o aceite de termo*/
				 
				$termo								= Conexa::termAccept($beneficiarioproduto->idintegracao, $plano_id);
				
				if (($termo->ok == 'S') and (!$termo->object)) 
				{
					Log::info('termonao', ['termonao'	=> $termo]);
					//Log::info('termo', ['termo'	=> $termo]);
					$tpayload               		= new stdClass;
					$tpayload->idPatient			= $beneficiarioproduto->idintegracao;
					$tpayload->ip					= $ip;
					$tpayload->plano_id 			= $plano_id;
					$acceptterm						= Conexa::acceptTerm($tpayload);
				}
				
				Log::info('termo', ['termo'	=> $termo]);
				
				$magiclink 							= Conexa::generateMagicLinkAccessapp($beneficiarioproduto->idintegracao,$plano_id);
				
				if (($magiclink->ok == 'N') and (($magiclink->details == 'Não foi registrado o termo do paciente.') or ($magiclink->mensagem == 'Não foi registrado o termo do paciente.')))
				{
				
					$payload               			= new stdClass;
					$payload->idPatient				= $beneficiarioproduto->idintegracao;
					$payload->ip					= $ip;
					$payload->plano_id 				= $plano_id;
					$acceptterm						= Conexa::acceptTerm($payload);
					
					Log::info('acceptterm', ['acceptterm'	=> $acceptterm]);
					if ($acceptterm->ok =='S')
					{
						$magiclink 					= Conexa::generateMagicLinkAccessapp($beneficiarioproduto->idintegracao, $plano_id);
					} else {
						$retorno->ok				= 'N';
						$retorno->mensagem			= $acceptterm->mensagem;
						return $retorno;
					}						
				}
				
				if (($magiclink->ok == 'N') and ($magiclink->details == 'Paciente bloqueado.'))
				{
					$activate 						= Conexa::desbloquear($beneficiarioproduto->idintegracao, $plano_id);
					Log::info('activate', ['activate'	=> $activate]);
					if ($activate->ok == 'S')
					{
						$payload               		= new stdClass;
						$payload->idPatient			= $beneficiarioproduto->idintegracao;
						$payload->ip				= $ip;
						$payload->plano_id 			= $plano_id;
						$acceptterm					= Conexa::acceptTerm($payload);
						Log::info('acceptterm', ['acceptterm'	=> $acceptterm]);
						$magiclink 					= Conexa::generateMagicLinkAccessapp($beneficiarioproduto->idintegracao, $plano_id);
					} else {
						$retorno->ok				= 'N';
						$retorno->mensagem			= $acceptterm->mensagem;
						return $retorno;
					}						
				}
				
				if (($magiclink->ok == 'N') or (!isset($magiclink->object)))
				{
					$retorno->ok					= 'N';
					$retorno->mensagem				= $magiclink->mensagem;
					return $retorno;
				}
				if (!isset($magiclink->object->linkMagicoApp))
				{
					$retorno->ok					= 'N';
					$retorno->mensagem				= "O Link não foi gerado, favor entrar em contato com o suporte";
					return $retorno;
				}
				$retorno->ok						= 'S';
				$beneficiarioproduto->magiclink		= $magiclink->object->linkMagicoApp;
			    $beneficiarioproduto->save();
				$retorno->link						= $beneficiarioproduto->magiclink;
				$retorno->linkweb                   = $magiclink->object->linkMagicoWeb;
			    return $retorno;
			case 5:  /* Seguro funeral*/
			  break;
		}
		
		$retorno->ok								= 'N';
		$retorno->mensagem							= "Link ainda não implementado";
		return $retorno;
		
	}

	public static function obterPlanoBeneficios($beneficiario_id)
	{
		$plano               						= new stdClass;
		$plano->id									= 0;
		$plano->nome								= "";
		$plano->produtos 							= array();
		
		$beneficiario                              	= \App\Models\Beneficiario::with('contrato')->find($beneficiario_id);

        if (!isset($beneficiario->id))
        {
			$plano->produtos 						= array();
			return $plano;
		}
		
		$tipo										= $beneficiario->tipo;
		if ($beneficiario->contrato->tipo == 'F')
		{
			 $plano              					= \App\Models\Plano::select('id','nome','preco','qtde_beneficiarios')->find($beneficiario->contrato->plano_id);
		} else {
			if ($beneficiario->tipo == 'D')
			{
				$beneficiario                       = \App\Models\Beneficiario::with('contrato')->where('id','=',$beneficiario->parent_id)->first();
				if (!isset($beneficiario->id))
				{
					$plano->produtos 				= array();
					return $plano;
				}
			}
			$plano              					= \App\Models\Plano::select('id','nome','preco','qtde_beneficiarios')->find($beneficiario->plano_id);
		}
				
				
		if (!isset($plano->id))
		{
			$plano               					= new stdClass;
			$plano->id								= 0;
			$plano->nome							= "";
			$plano->produtos 						= array();
			return $plano;
		}
		if ($beneficiario->contrato->status == 'active')
		{
			$plano->produtos 						= Cas::obterBeneficios($plano->id,$tipo,$beneficiario_id);
		} else {
			$plano->produtos 						= array();
		}
		return $plano;
	}

	public static function obterBeneficios($plano_id,$tipo_beneficiario,$beneficiario_id)
	{
		$produtos 											= array();
		
		$planoprodutos   									= \App\Models\PlanoProduto::with('produto')
																	 ->where('plano_id','=',$plano_id)
																	 ->whereIn('beneficiario',array('A',$tipo_beneficiario))
																	 ->get();
		foreach ($planoprodutos as $planoproduto)
		{
			if ($planoproduto->produto->integracao)
			{
				$beneficiarioproduto 						= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario_id)
																							 ->where('produto_id','=',$planoproduto->produto->id)
																							 ->first();
				if (!isset($beneficiarioproduto->id))
				{
					$planoproduto->produto->integrado 		= 0;
					$planoproduto->produto->ativacao 		= 'Não';
					$planoproduto->produto->data_ativacao   = null;
					$planoproduto->produto->data_desativacao= null;
				} else {
					$planoproduto->produto->integrado 		= 1;
					if ($beneficiarioproduto->ativacao ==1)
					{
						$planoproduto->produto->ativacao 	= 'Sim';
					} else {
						$planoproduto->produto->ativacao 	= 'Não';
					}
					$planoproduto->produto->data_ativacao   = $beneficiarioproduto->data_ativacao;
					$planoproduto->produto->data_desativacao= $beneficiarioproduto->data_desativacao;
				}
			} 
			$produtos[]						= $planoproduto->produto;
		}	

		return $produtos;
	}

	public static function obterDadosBeneficiario($cpf)
	{
		$retorno 					= new stdClass();
		$retorno->id 				= 0;
		$retorno->cpf 				= "";
		$retorno->nome 				= "";
		$retorno->idade 			= 0;
		$retorno->email 			= "";
		$retorno->tipo 				= "";
		$retorno->cliente_id 		= 0;
		$retorno->plano_id 			= 0;
		$retorno->qtdedep			= 0;
		$retorno->patientholderid	= 0;
		$retorno->situacao 			= "";
		$retorno->dependentes 		= array();
		
		$cpf						= preg_replace('/[^0-9]/', '', $cpf);
		$cpf 						= str_pad($cpf, 11, "0", STR_PAD_LEFT);	
		
		$cliente                 	= \App\Models\Cliente::select('id','nome','user_id','data_nascimento','saldo')
												->where('cpfcnpj','=',$cpf)
												->where('tipo','=','F')
												->first();
													  
		if (!isset($cliente->id)) 
		{
			$retorno->situacao 		= "C";
            return $retorno;
        }											  
		
		$retorno->cliente_id		= $cliente->id;
		$retorno->cpf				= $cpf;
		$retorno->nome				= $cliente->nome;
		$retorno->email				= $cliente->email;
		
		if ($cliente->saldo > 0)
		{
			$retorno->saldo 		= "R$ " . str_replace('.',',',$cliente->saldo);
		} else {
			$retorno->saldo			= "";
		}
		
		$beneficiario 				= \App\Models\Beneficiario::with('contrato')
												->where('cliente_id', '=', $cliente->id)
												->where('ativo', '=', 1)
												->whereHas('contrato', function ($query) {
													$query->whereIn('status', array('active','waitingPayment'));
												})
												->first();
												
		if (!isset($beneficiario->id)) 
		{
			$retorno->situacao 		= "B";
			return $retorno;
		}
		
		$retorno->tipo				= $beneficiario->tipo;
		if ((!isset($beneficiario->contrato->status)) or (($beneficiario->contrato->status !='active') and ($beneficiario->contrato->status !='waitingPayment')))
		{
			$retorno->situacao 		= "B";
			return $retorno;
		}
		
		$retorno->id 				= $beneficiario->id;
		$retorno->idade 			= Carbon::createFromDate($cliente->data_nascimento)->age; 
		if (isset($beneficiario->contrato->situacao_pagto))
		{
			$retorno->situacao		= $beneficiario->contrato->situacao_pagto;
		} else {
			$retorno->situacao		= "I";
		}
		
		$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario->id)
																	 ->where('produto_id','=',4)
																	 ->first();		
		if (isset($beneficiarioproduto->id))
		{
			$retorno->patientholderid = $beneficiarioproduto->idintegracao;
		}				
		
		if ($beneficiario->contrato->tipo == 'F')
		{	
			$retorno->plano_id      = $beneficiario->contrato->plano_id;
			$dependentes 			= \App\Models\Beneficiario::with('cliente','parentesco')
												  ->where('contrato_id','=',$beneficiario->contrato_id)
												  ->where('tipo','=','D')
												  ->where('ativo','=',1)
												  ->get();
		} else {
			
			$retorno->situacao		= "A";
			$parcela 				= \App\Models\Parcela::where('contrato_id','=',$beneficiario->contrato_id)
														 ->where('data_pagamento','=',null)
														 ->where('data_baixa','=',null)
														 ->where('data_vencimento','<',date('Y-m-d'))
														 ->orderBy('data_vencimento','asc')
														 ->first();
			if (isset($parcela->id))
			{
				$date 								= $parcela->data_vencimento. " 23:59:59";
				$vencimento 						= Carbon::createFromDate($date);
				$now 								= Carbon::now();
				$diferenca 							= $vencimento->diffInDays($now);
					
				if ($diferenca >= 9)
				{
					$retorno->situacao				= 'I';
				}
			}
		
			$retorno->plano_id      = $beneficiario->plano_id;
			$dependentes 		    = \App\Models\Beneficiario::with('cliente','parentesco')
												  ->where('parent_id','=',$beneficiario->id)
												  ->where('tipo','=','D')
												  ->where('ativo','=',1)
												  ->get();
		}
		
		
		$sql 						= "SELECT id, titulo as nome, integracao FROM produtos where ativo=1 order by sequencia";
		$lprodutos					= DB::select($sql);
		$produtos 					= array();
		
		foreach ($lprodutos as $produto)
		{
				
			$planoproduto  			= \App\Models\PlanoProduto::where('plano_id','=',$retorno->plano_id)
															  ->where('produto_id','=',$produto->id)
															  ->whereIn('beneficiario',array('A',$beneficiario->tipo))
															  ->first();
			if (isset($planoproduto->id))
			{
				$registro 				= new stdClass();
				$registro->id 			= $produto->id; 
				$registro->nome 		= $produto->nome; 
				$registro->integra 		= $produto->integracao; 
				$registro->ativo 		= 1;
				if ($produto->integracao == 1)
				{
					$registro->ativo 	= 0;
					$beneficiarioproduto= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario->id)
																		 ->where('produto_id','=',$produto->id)
																		 ->first();		
					if (isset($beneficiarioproduto->id))
					{
						$registro->ativo = $beneficiarioproduto->ativacao;
					}		
				}
				$produtos[] 		= $registro;
			}
		}
		
		if ($beneficiario->tipo == 'T')
		{
			
			$plano              				= \App\Models\Plano::select('id','qtde_beneficiarios')->find($retorno->plano_id);
					
			if (isset($plano->id))
			{
				if ($plano->qtde_beneficiarios > 1)
				{
					if ($beneficiario->contrato->tipo == 'F')
					{
						$qtde_dependentes 		= \App\Models\Beneficiario::where('contrato_id','=',$beneficiario->contrato_id)
																		  ->where('tipo','=','D')
																		  ->where('desc_status','=','ATIVO')
																		  ->count();
					} else {
						$qtde_dependentes 		= \App\Models\Beneficiario::where('parent_id','=',$beneficiario->id)
																		  ->where('tipo','=','D')
																		  ->where('desc_status','=','ATIVO')
																		  ->count();
					}
					$retorno->qtdedep 			= ($plano->qtde_beneficiarios - $qtde_dependentes) -1;
				}
			}
		  
		
			foreach ($dependentes as $dependente)
			{
				$registro 					= new stdClass();
				$registro->id 				= $dependente->id; 
				$registro->cpf 				= $dependente->cliente->cpfcnpj; 
				$registro->nome 			= $dependente->cliente->nome; 
				$registro->idade 			= Carbon::createFromDate($dependente->cliente->data_nascimento)->age; 
				$registro->parentesco 		= $dependente->parentesco->nome; 
				$registro->cliente_id 		= $dependente->cliente_id;
				 
				$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$dependente->id)
																			 ->where('produto_id','=',4)
																			 ->first();		
				if (isset($beneficiarioproduto->id))
				{
					$registro->patientholderid = $beneficiarioproduto->idintegracao;
				} else {
					$registro->patientholderid = 0;
				}				
			
				$retorno->dependentes[]		= $registro;
			}
		}
		
		$retorno->produtos 					= $produtos;
		return $retorno;
	}

	public static function inativarBeneficiarioComParcelasVencidas($dias=9)
	{

		$desativar									= array();
		// Data limite: 9 dias atrás
		$dataLimite 								= Carbon::now()->subDays($dias);

		// Subconsulta para obter a menor data de vencimento por contrato
		$subQuery 									= \App\Models\Parcela::selectRaw('MIN(data_vencimento) as menor_vencimento, contrato_id')
															->whereNull('data_pagamento') // Pagamento é nulo
															->where('data_vencimento', '<', $dataLimite) // Vencimento há mais de 9 dias
															->groupBy('contrato_id'); // Agrupar por contrato

		// Consulta principal para obter os detalhes da parcela
		$parcelasvencidas 							= \App\Models\Parcela::joinSub($subQuery, 'sub', function ($join) {
																		$join->on('parcelas.contrato_id', '=', 'sub.contrato_id')
																			->on('parcelas.data_vencimento', '=', 'sub.menor_vencimento');
																	})
																	->whereHas('contrato', function ($query) {
																		$query->whereIn('status', array('active','waitingPayment')); // Contratos com situação ativa
																		$query->where('tipo','=','F');
																	})
																	->get();

		if (count($parcelasvencidas) > 0)
		{
			foreach ($parcelasvencidas as $parcela)
			{
				$beneficiarios 						= \App\Models\Beneficiario::where('contrato_id','=',$parcela->contrato_id)
																			  ->where('tipo','=','T')
																			  ->get();	
				foreach ($beneficiarios as $beneficiario)	
				{														  
					$desativar[]                    = Cas::ativarDesativarBeneficiario($beneficiario->id,false);
				}
			}
		}

		return $desativar;
	}

	public static function ativarBeneficiarioContratosValidos()
	{
		$ativar									= array();
		$contratosValidos 						= \App\Models\Contrato::whereIn('status', ['active', 'waitingPayment']) // Contratos ativos ou aguardando pagamento
																	 ->where('tipo', '=', 'F') // Tipo do contrato é 'F'
																	 ->where(function ($query) {
																			$query->whereDoesntHave('parcelas') // Contratos sem parcelas
																				->orWhere(function ($query) {
																					$query->whereHas('parcelas', function ($subQuery) {
																						$subQuery->where('data_vencimento', '>=', Carbon::now()) // Parcelas a vencer
																								 ->whereNull('data_pagamento'); // Parcelas não pagas
																					})
																					->whereDoesntHave('parcelas', function ($subQuery) {
																						$subQuery->where('data_vencimento', '<', Carbon::now()) // Parcelas vencidas
																								 ->whereNull('data_pagamento'); // Parcelas não pagas
																					});
																				});
																		})
																		->get();
		if (count($contratosValidos) > 0)
		{
			foreach ($contratosValidos as $contrato)
			{
				$beneficiarios 					= \App\Models\Beneficiario::where('contrato_id','=',$contrato->id)
																		  ->where('tipo','=','T')
																		  ->get();	
				foreach ($beneficiarios as $beneficiario)	
				{
					$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario->id)
																				 ->whereIn('produto_id',array(2,4))
																				 ->where('ativacao','=',1)
																				 ->count();														 
					if ($beneficiarioproduto < 2)
					{					
						$ativar[]               = $contrato->id; //Cas::ativarDesativarBeneficiario($beneficiario->id,true);
					}
				}
			}
		}
		
		return $ativar;
	}
}
