<?php

    namespace App\Helpers;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use Laravel\Passport\Client;
    use Illuminate\Validation\ValidationException;;
    use App\Models\Cliente;
    use App\Models\Beneficiario;
    use DB;
    use stdClass;

    class Cartao
    {

        public static function obterCartao($Cpf, $CodOnix)
        {
            
            $retorno			    = new stdClass();
            $retorno->ok 		    = "";
            $retorno->mensagem	    = "";
            $dependentes 	    	= array();
            
            $url      			    = config('services.cartao_tem.api_url_auth') . '/v1/api-auth/login';
            $hresponse   		    = Http::post($url, ["companyId" => 7418708, "apiKey" => 'Kz9CiirETI']);
            $response 			    = $hresponse->object();
            
            if (!isset($response->data->token))
            {
                $retorno->ok 		= "E";
                $retorno->mensagem	= "Token inválido para o ID# 7418708";
                return $retorno;	
            }
            
            $token 				    = $response->data->token;
            
            $url      			    = config('services.cartao_tem.api_url') . '/tem_usertoken_cto';
            
            $hresponse   		    = Http::post($url, ["cpf" => $Cpf, "CodOnix" => $CodOnix, "tokenzeus" => $token ]);
            $response 			    = $hresponse->object();
            
            if (!isset($response->user_token))
            {
                $retorno->ok 		= "E";
                $retorno->mensagem	= "CPF nao encontrado";
                $retorno->response  = $response;
                return $retorno;	
            }
            
            $UserToken			    = $response->user_token;
            
            $url      			    = config('services.cartao_tem.api_url') . '/tem_status_cto';
            
            $hresponse   		    = Http::post($url, ["cpf" => $Cpf, "CodOnix" => $CodOnix, "tokenzeus" => $token, "UserToken"=> $UserToken ]);
            $response 			    = $hresponse->object();
            
            if (!isset($response->idcartao))
            {
                $retorno->ok 		= "E";
                $retorno->mensagem	= "CPF nao encontrado";
                return $retorno;	
            }
            
            $titular 			    = $response;
            $url      			    = config('services.cartao_tem.api_url') . '/tem_dependente';
            $hresponse   		    = Http::post($url, ["Cpf" => $Cpf, "CodOnix" => $CodOnix, "tokenzeus" => $token]);
            
            $response 			    = $hresponse->object();

            if ((isset($response->result)) and (count($response->result) > 0))
            {
                foreach ($response->result as $result)
                {
                    $dependentes[]= $result;
                }
            } 
            
            $retorno->ok 				= "S";
            $retorno->titular 			= $titular;	
            $retorno->dependentes 		= $dependentes;	
            return $retorno;	 
        }

        public static function CartaoMigrarBeneficiarios()
        {
            if (\Cache::has('cartao_start'))
            {
                $cartao_start               = \Cache::get('cartao_start');
            } else {
                $cartao_start               = 0;
                \Cache::forever('cartao_start',$cartao_start);
            }

            $contratos					    = DB::connection('mysql')
                                                ->table('contratos')
                                                ->select('contratos.id','contratos.vigencia_inicio','clientes.id as cliente_id','clientes.cpfcnpj',  'planos.nome as plano')
                                                ->leftJoin('clientes','contratos.cliente_id','=', 'clientes.id')
                                                ->leftJoin('planos',   'contratos.plano_id', '=', 'planos.id')
                                                ->where('contratos.id','>', $cartao_start)
                                                ->where('contratos.id','<', $cartao_start+100)
                                                ->where('contratos.tipo','=','F')
                                                ->whereIn('status',array('active','waitingPayment'))
                                                ->whereIn('planos.id',array(1,2,3,4))
                                                ->orderBy('contratos.id')
                                                ->get();
            if (count($contratos) > 0)
            {
                foreach ($contratos as $contrato)
                {
                    $CodOnix                    = substr($contrato->plano,0,4);
                    if (is_numeric($CodOnix))
                    {
                        $cartao 			    = Cartao::obterCartao($contrato->cpfcnpj, $CodOnix);
                        if (isset($cartao->ok))
                        {
                            if ($cartao->ok=='N')
                            {
                                if ($CodOnix == 7501)
                                {
                                    $CodOnix    = 7450;
                                } else {
                                    $CodOnix    = 7501;
                                }
                                $cartao         = Cartao::obterCartao($contrato->cpfcnpj, $CodOnix);
                            }
                        }
                        if ((isset($cartao->ok)) and ($cartao->ok=='S'))
                        {
                            $cartao->contrato_id        = $contrato->id;
                            $cartao->vigencia_inicio    = $contrato->vigencia_inicio;
                            $migrar                     = Cartao::CartaoMigrarBeneficiario($cartao);
                            Log::info("cartao", ['cartao' => $cartao ]);
                        } else {
                            $beneficiario                           = \App\Models\Beneficiario::where('contrato_id','=',$contrato->id)
                                                                                              ->where('cliente_id','=',$contrato->cliente_id)
                                                                                              ->first();

                            if (!isset($beneficiario->id)) 
                            {
                                $beneficiario                       = new \App\Models\Beneficiario();
                                $beneficiario->contrato_id          = $contrato->id;
                                $beneficiario->cliente_id           = $contrato->cliente_id;
                                $beneficiario->parent_id            = 0;
                                $beneficiario->ativo                = true;
                                $beneficiario->vigencia_inicio      = $contrato->vigencia_inicio;
                                $beneficiario->vigencia_fim         = '2999-12-31';
                               
                            }
                            $beneficiario->tipo                     = 'T';
                            $beneficiario->idcartao                 = 0;
                            $beneficiario->statuscartao             = 1;
                            $beneficiario->desc_status              = 'ATIVO';
                            $beneficiario->codonix                  = 0;
                            $beneficiario->numerocartao             = 0;
                            $beneficiario->data_inicio_associacao   = $contrato->vigencia_inicio;
                            $beneficiario->data_vencimento          = '2999-12-31';
                            $beneficiario->tipo_usuario             = 'TITULAR';
                            $beneficiario->save();
                        }
                    }
                    \Cache::forever('cartao_start', $contrato->id);
                    Log::info("cartao", ['contrato' => $contrato->id ]);
                }        
            } else {
               \Cache::forever('cartao_start', 0);
            }                            
        }
		
		public static function storeTitularContrato()
        {
			
			$contratos 											= \App\Models\Contrato::where('tipo', '=', 'F')
																		 ->whereIn('status', ['active', 'waitingPayment'])
																		 ->where(function($query) {
																			 $query->where('mainPaymentMethodId', '=', 'creditcard')
																				   ->orWhere('contractacceptedAt', '<>', null);
																		 })
																		 ->get();
								 																	
			foreach ($contratos as $contrato)
			{
				 $beneficiario                           	= \App\Models\Beneficiario::where('contrato_id','=',$contrato->id)->first();
				 if (!isset($beneficiario->id))
				 {
					$beneficiario                       	= new \App\Models\Beneficiario();
                    $beneficiario->contrato_id          	= $contrato->id;
                    $beneficiario->cliente_id           	= $contrato->cliente_id;
                    $beneficiario->parent_id            	= 0;
                    $beneficiario->ativo                	= true;
                    $beneficiario->vigencia_inicio      	= $contrato->vigencia_inicio;
                    $beneficiario->vigencia_fim         	= '2999-12-31';
                    $beneficiario->tipo                 	= 'T';
                    $beneficiario->idcartao                 = 0;
                    $beneficiario->statuscartao             = 1;
                    $beneficiario->desc_status              = 'ATIVO';
                    $beneficiario->codonix                  = 0;
                    $beneficiario->numerocartao             = 0;
                    $beneficiario->data_inicio_associacao   = $contrato->vigencia_inicio;
                    $beneficiario->data_vencimento          = '2999-12-31';
					$beneficiario->plano_id					= $contrato->plano_id;
                    $beneficiario->tipo_usuario             = 'TITULAR';
                    $beneficiario->save();
				 }
			}
			
			return true;
			
		}

        public static function CartaoMigrarBeneficiario($cartao)
		{
            if (isset($cartao->titular))
            {
                $cpf                                        = str_pad($cartao->titular->cpf, 11, "0", STR_PAD_LEFT);
                $titular                                    = \App\Models\Cliente::where('cpfcnpj','=',$cpf)->first();

                if (isset($titular->id)) 
                {
                    
                    $beneficiario                           = \App\Models\Beneficiario::where('contrato_id','=',$cartao->contrato_id)
                                                                              ->where('cliente_id','=',$titular->id)
                                                                              ->first();

                    if (!isset($beneficiario->id)) 
                    {
                        $beneficiario                       = new \App\Models\Beneficiario();
                        $beneficiario->contrato_id          = $cartao->contrato_id;
                        $beneficiario->cliente_id           = $titular->id;
                        $beneficiario->parent_id            = 0;
                        $beneficiario->ativo                = true;
                        $beneficiario->vigencia_inicio      = $cartao->vigencia_inicio;
                        $beneficiario->vigencia_fim         = '2999-12-31';
                        $beneficiario->tipo                 = 'T';
                    }
                    
                    $beneficiario->idcartao                 = $cartao->titular->idcartao;
                    $beneficiario->statuscartao             = $cartao->titular->statuscartao;
                    $beneficiario->desc_status              = $cartao->titular->desc_status;
                    $beneficiario->codonix                  = $cartao->titular->CodOnix;
                    $beneficiario->numerocartao             = $cartao->titular->NumeroCartao;
                    $beneficiario->data_inicio_associacao   = $cartao->titular->data_inicio_associacao;
                    $beneficiario->data_vencimento          = $cartao->titular->data_vencimento;
                    $beneficiario->tipo_usuario             = $cartao->titular->tipo_usuario;
                    $beneficiario->save();
                }
            }
            if (isset($cartao->dependentes))
            {
                foreach ($cartao->dependentes as $dependente)
                {
                    $cpf                                    = str_pad($dependente->CPF_DEPENDENTE, 11, "0", STR_PAD_LEFT);
                    $dep                                    = \App\Models\Cliente::where('cpfcnpj','=',$cpf)->first();
    
                    if (!isset($dep->id)) 
                    {
                        $dep                                = new \App\Models\Cliente();
                        $dep->tipo                          = 'F';
                        $dep->cpfcnpj                       = $cpf;
                        $dep->nome                          =  $dependente->NOME_DEPENDENTE;
                        $dep->data_nascimento               = $dependente->DATA_NASCIMENTO;
                        if (isset($dependente->EMAIL))
                        {
                            $dep->email                     = $dependente->EMAIL;
                        } else {
                            $dep->email                     = "";
                        }
                        $dep->sexo                          = substr($dependente->sexo,0,1);
                        if (isset($dependente->TELEFONE))
                        {
                            $dep->telefone                  = $dependente->TELEFONE;
                        } else {
                            $dep->telefone                  =  $titular->telefone;
                        }
                        $dep->ativo                         = true;
                        $dep->observacao                    = "";
                    }

                    $dep->cep                               = $titular->cep;
                    $dep->logradouro                        = $titular->logradouro;
                    $dep->numero                            = $titular->numero;
                    $dep->complemento                       = $titular->complemento;
                    $dep->bairro                            = $titular->bairro;
                    $dep->cidade                            = $titular->cidade;
                    $dep->estado                            = $titular->estado;
                    if ($dep->save())
                    {
                        $beneficiario                       = \App\Models\Beneficiario::where('contrato_id','=',$cartao->contrato_id)
                                                                              ->where('cliente_id','=',$dep->id)
                                                                              ->first();

                        if (!isset($beneficiario->id)) 
                        {
                            $beneficiario                    = new \App\Models\Beneficiario();
                            $beneficiario->contrato_id       = $cartao->contrato_id;
                            $beneficiario->cliente_id        = $dep->id;
                            $beneficiario->parent_id         = 0;
                            $beneficiario->ativo             = true;
                            $beneficiario->vigencia_inicio   = $cartao->vigencia_inicio;
                            $beneficiario->vigencia_fim      = '2999-12-31';
                        }
                        
                        $beneficiario->tipo                  = 'D';
                        $beneficiario->idcartao              = $cartao->titular->idcartao;
                        $beneficiario->statuscartao          = $dependente->COD_STATUS;
                        $beneficiario->desc_status           = $dependente->DESC_STATUS;
                        $beneficiario->codonix               = $cartao->titular->CodOnix;
                        $beneficiario->numerocartao          = $cartao->titular->NumeroCartao;
                        $beneficiario->data_inicio_associacao= $dependente->DATA_INICIO_ASSOCIACAO;
                        $beneficiario->data_vencimento       = $cartao->titular->data_vencimento;
                        $beneficiario->tipo_usuario          = "DEPENDENTE";
                        $beneficiario->save();
                    }
                }
            }
        }
		
		public static function CartaoMigrarDependentes()
        {
			
			return;
			
            if (\Cache::has('dcartao_start'))
            {
                $cartao_start               = \Cache::get('dcartao_start');
            } else {
                $cartao_start               = 4062;
                \Cache::forever('dcartao_start',$cartao_start);
            }

            $contratos					    = DB::connection('mysql')
                                                ->table('contratos')
                                                ->select('contratos.id',
														'contratos.vigencia_inicio',
														'clientes.id as cliente_id',
														'clientes.cpfcnpj',
														'planos.id as plano_id',
														'planos.nome as plano',
														'clientes.cep',
														'clientes.logradouro',
														'clientes.numero',
														'clientes.complemento',
														'clientes.bairro',
														'clientes.cidade',
														'clientes.estado',														
														'beneficiarios.id as parent_id')
												->leftJoin('beneficiarios',   'contratos.id', '=', 'beneficiarios.contrato_id')
                                                ->leftJoin('clientes','beneficiarios.cliente_id','=', 'clientes.id')
												->leftJoin('planos',  'beneficiarios.plano_id', '=', 'planos.id')
                                                ->where('contratos.id','>', $cartao_start)
                                                ->where('contratos.id','<', $cartao_start+100)
                                                ->where('contratos.tipo','=','J')
												->where('beneficiarios.tipo','=','T')
                                                ->where('contratos.status','=','active')
                                                ->orderBy('beneficiarios.id')
                                                ->get();
            if (count($contratos) > 0)
            {
                foreach ($contratos as $contrato)
                {
                    $CodOnix                    = substr($contrato->plano,0,4);
                    if (is_numeric($CodOnix))
                    {
                        $cartao 			    = Cartao::obterCartao($contrato->cpfcnpj, $CodOnix);
                        if (isset($cartao->ok))
                        {
                            if ($cartao->ok=='N')
                            {
                                if ($CodOnix == 7501)
                                {
                                    $CodOnix    = 7450;
                                } else {
                                    $CodOnix    = 7501;
                                }
                                $cartao         = Cartao::obterCartao($contrato->cpfcnpj, $CodOnix);
                            }
                        }
                        if ((isset($cartao->ok)) and ($cartao->ok=='S'))
                        {
                            $cartao->contrato_id        = $contrato->id;
                            $cartao->vigencia_inicio    = $contrato->vigencia_inicio;
							$cartao->parent_id 			= $contrato->parent_id;
							$cartao->plano_id 			= $contrato->plano_id;
							$cartao->cep                = $contrato->cep;
							$cartao->logradouro         = $contrato->logradouro;
							$cartao->numero             = $contrato->numero;
							$cartao->complemento        = $contrato->complemento;
							$cartao->bairro             = $contrato->bairro;
							$cartao->cidade             = $contrato->cidade;
							$cartao->estado             = $contrato->estado;
						
                            $migrar                     = Cartao::CartaoMigrarDependente($cartao);
                            Log::info("dcartao", ['dcartao' => $cartao ]);
                        } 
                    }
                    \Cache::forever('dcartao_start', $contrato->id);
                    Log::info("dcartao", ['contrato' => $contrato->id ]);
                }        
            } else {
               \Cache::forever('dcartao_start', 4062);
            }                            
        }
		
		public static function CartaoMigrarDependente($cartao)
        {
            if (isset($cartao->dependentes))
            {
                foreach ($cartao->dependentes as $dependente)
                {
                    $cpf                                    = str_pad($dependente->CPF_DEPENDENTE, 11, "0", STR_PAD_LEFT);
                    $dep                                    = \App\Models\Cliente::where('cpfcnpj','=',$cpf)->first();
					$achou 									= false;
					
                    if (!isset($dep->id)) 
                    {
                        $dep                                = new \App\Models\Cliente();
                        $dep->tipo                          = 'F';
                        $dep->cpfcnpj                       = $cpf;
                        $dep->nome                          = $dependente->NOME_DEPENDENTE;
                        $dep->data_nascimento               = $dependente->DATA_NASCIMENTO;
                        if (isset($dependente->EMAIL))
                        {
                            $dep->email                     = $dependente->EMAIL;
                        } else {
                            $dep->email                     = "";
                        }
                        $dep->sexo                          = substr($dependente->sexo,0,1);
                        if (isset($dependente->TELEFONE))
                        {
                            $dep->telefone                  = $dependente->TELEFONE;
                        } else {
                            $dep->telefone                  =  $titular->telefone;
                        }
                        $dep->ativo                         = true;
                        $dep->observacao                    = "";
                    } else {
						$achou 								= true;
					}

					if (!$achou)
					{
						$dep->cep                           = $cartao->cep;
						$dep->logradouro                    = $cartao->logradouro;
						$dep->numero                        = $cartao->numero;
						$dep->complemento                   = $cartao->complemento;
						$dep->bairro                        = $cartao->bairro;
						$dep->cidade                        = $cartao->cidade;
						$dep->estado                        = $cartao->estado;
					}
					
                    if ($dep->save())
                    {
                        $beneficiario                       = \App\Models\Beneficiario::where('contrato_id','=',$cartao->contrato_id)
                                                                              ->where('cliente_id','=',$dep->id)
                                                                              ->first();

                        if (!isset($beneficiario->id)) 
                        {
                            $beneficiario                    = new \App\Models\Beneficiario();
                            $beneficiario->contrato_id       = $cartao->contrato_id;
                            $beneficiario->cliente_id        = $dep->id;
                            $beneficiario->parent_id         = 0;
                            $beneficiario->ativo             = true;
                            $beneficiario->vigencia_inicio   = $cartao->vigencia_inicio;
                            $beneficiario->vigencia_fim      = '2999-12-31';
                        }
                        
                        $beneficiario->tipo                  = 'D';
                        $beneficiario->idcartao              = $cartao->titular->idcartao;
                        $beneficiario->statuscartao          = $dependente->COD_STATUS;
                        $beneficiario->desc_status           = $dependente->DESC_STATUS;
                        $beneficiario->codonix               = $cartao->titular->CodOnix;
                        $beneficiario->numerocartao          = $cartao->titular->NumeroCartao;
                        $beneficiario->data_inicio_associacao= $dependente->DATA_INICIO_ASSOCIACAO;
                        $beneficiario->data_vencimento       = $cartao->titular->data_vencimento;
                        $beneficiario->tipo_usuario          = "DEPENDENTE";
						$beneficiario->parent_id 			 = $cartao->parent_id;
						$beneficiario->plano_id 			 = $cartao->plano_id;
                        $beneficiario->save();
                    }
                }
            }
        }
    }