<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Helpers\Cas;
use App\Helpers\Conexa;
use App\Jobs\ConexaAtivarAdimplentesJob;
use App\Jobs\ConexaDesativarInadimplentesJob;
use Carbon\Carbon;
use stdClass;
use DB;

class ConexaSaudeController extends Controller
{
	
	 public function listarPacientes(Request $request)
    {
		$pagina          					= $request->input('pagina', '1');	
		$paciente 							= Conexa::listarPacientes($pagina);
		
		return response()->json($paciente, 200);
		
    }
	
    public function buscarPaciente(Request $request)
    {
		$cpf          						= $request->input('cpf', '0');
		$cpf								= str_pad($cpf, 11, "0", STR_PAD_LEFT);
		
		$paciente 							= Conexa::buscarPaciente($cpf);
		
		return response()->json($paciente, 200);
		
    }
	
	public function buscarPacienteStatus(Request $request)
    {
		$cpf          							= $request->input('cpf', '0');
		$cpf									= str_pad($cpf, 11, "0", STR_PAD_LEFT);
		$status 								= Conexa::buscarPacienteStatus($cpf);
		return response()->json($status, 200);
	}
	
	public function activate($id)
    {
		$activate 							= Conexa::activate($id);
		return response()->json($activate, 200);
	}
	
	public function inactivate($id)
    {
		$inactivate 						= Conexa::inactivate($id);
		return response()->json($inactivate, 200);
	}
	
	public function acceptTerm(Request $request)
    {
		$payload							= (object) $request->all();
		$acceptTerm 						= Conexa::acceptTerm($payload);
		return response()->json($acceptTerm, 200);
	}
	
	public function termAccept($id)
    {
		$acceptTerm 						= Conexa::termAccept($id);
		return response()->json($acceptTerm, 200);
	}
	
	public function generateMagicLinkAccessapp($id)
    {
		$magiclink 						= Conexa::generateMagicLinkAccessapp($id);
		return response()->json($magiclink, 200);
	}
	
    public function createOrUpdatePatient(Request $request)
    {
       
		$data 								= [];
		// Adicionando os campos ao array $data um por um
		$data['name'] 						= $request->input('nome', '');
		$data['mail'] 						= $request->input('email', '');
		$data['dateBirth'] 					= $request->input('nascimento', '');
		$data['sex'] 						= $request->input('sexo', '');
		$data['cpf'] 						= $request->input('cpf', '');
		$data['cellphone'] 					= $request->input('telefone', '');
		/*
		$data['patientHolderId'] 			= null;
		$data['kinshipOfTheHolder'] 		= null;
		$data['healthCardNumber'] 			= null;
		$data['additionalInformation'] 		= null;
		$data['passport'] 					= null;
		$data['specialist'] 				= null;
		$data['nationalId'] 				= null;
		
		$data['address'] 					= [];
		$data['address']['additionalAddressDetails'] 	= null;
		$data['address']['city'] 						= null;
		$data['address']['country'] 					= null;
		$data['address']['region'] 						= null;
		$data['address']['state'] 						= null;
		$data['address']['streetAddress'] 				= null;
		$data['address']['zipCode'] 					= null;

		$data['motherName'] 							= null;
		$data['socialName'] 							= null;
		$data['idRaceColor'] 							= null;
		$data['idNationality'] 							= null;
		$data['naturalizationDate'] 					= null;
		$data['cns'] 									= null;
		$data['idCbo'] 									= null;
		$data['religion'] 								= null;
		$data['otherReligions'] 						= null;
		$data['workplace'] 								= null;
		$data['freeObservations'] 						= null;
		$data['unknowMother'] 							= null;
		$data['idHomeArea'] 							= null;
		$data['homeSituation'] 							= null;
		$data['idSchooling'] 							= null;
		$data['socialVulnerability'] 					= null;
		$data['ethnicity'] 								= null;
		$data['idGender'] 								= null;
		$data['birthCounty'] 							= null;
		$data['idBirthUF'] 								= null;
		$data['idPassportIssuingCountry'] 				= null;
		$data['passportIssuingDate'] 					= null;
		$data['passportExpiryDate'] 					= null;
		$data['idBirthCountry'] 						= null;
		$data['kinshipProcurator'] 						= null;
		$data['cpfProcurator'] 							= null;
		$data['nameProcurator'] 						= null;
		$data['identityIssuingDate'] 					= null;
		$data['idIdentityUF'] 							= null;
		$data['identityIssuingBody'] 					= null;
		$data['nisNumber'] 								= null;
		*/
		$paciente 							= Conexa::createOrUpdatePatient($data);
		
		return response()->json($paciente, 200);
    }
    /**
     * ========================================================
     *  MENU CONEXA — Rotinas de Sincronização em Lote
     * ========================================================
     */

    /**
     * Ativa todos os beneficiários adimplentes na Conexa.
     * POST /api/conexa/ativar-adimplentes
     *
     * Lógica:
     *  1. Sem idintegracao  → cria o paciente na Conexa via associateBeneficiario() e já ativa
     *  2. Com idintegracao  → consulta status atual na Conexa:
     *       - Já ativo       → pula (sem chamada extra)
     *       - Inativo/bloq.  → chama activate()
     */
    public function ativarAdimplentes(Request $request)
    {
        $total    = 0;
        $sucesso  = 0;
        $pulados  = 0;
        $erros    = 0;
        $detalhes = [];

        // Busca adimplentes COM ou SEM idintegracao (produto Telemedicina = 4) — apenas Titulares
        $beneficiarios = DB::table('beneficiarios as b')
            ->join('contratos as c', 'c.id', '=', 'b.contrato_id')
            ->join('clientes as cl', 'cl.id', '=', 'b.cliente_id')
            ->leftJoin('beneficiario_produto as bp', function ($join) {
                $join->on('bp.beneficiario_id', '=', 'b.id')
                     ->where('bp.produto_id', '=', 4);
            })
            ->where('b.ativo', '=', 1)
            ->where('b.tipo', '=', 'T')
            ->where('c.situacao_pagto', '=', 'A')
            ->whereNotNull('b.cliente_id')
            ->select([
                'b.id as beneficiario_id',
                'b.tipo as beneficiario_tipo',
                'b.plano_id as beneficiario_plano_id',
                'b.parent_id',
                'c.plano_id as contrato_plano_id',
                'c.tipo as contrato_tipo',
                'bp.idintegracao',
                'bp.ativacao',
                'cl.cpfcnpj as cpf',
            ])
            ->get();

        foreach ($beneficiarios as $benef) {
            $total++;
            $plano_id = $this->resolverPlanoId($benef);

            // --- SEM idintegracao: cadastrar e ativar ---
            if (empty($benef->idintegracao)) {
                $resultado = Conexa::associateBeneficiario($benef->beneficiario_id, 4);
                if ($resultado->ok === 'S') {
                    $sucesso++;
                } else {
                    $erros++;
                    $detalhes[] = [
                        'beneficiario_id' => $benef->beneficiario_id,
                        'acao'            => 'cadastrar+ativar',
                        'mensagem'        => $resultado->mensagem ?? 'Erro ao cadastrar na Conexa',
                    ];
                }
                continue;
            }

            // --- COM idintegracao: verificar status antes ---
            $status = Conexa::buscarPacienteStatus($benef->cpf, $plano_id);

            // Se já está ativo na Conexa, pula
            if ($status->ok === 'S' && isset($status->status) && strtoupper($status->status) === 'ACTIVE') {
                $pulados++;
                continue;
            }

            // Ativar
            $resultado = Conexa::activate($benef->idintegracao, $plano_id);
            if ($resultado->ok === 'S') {
                $sucesso++;
            } else {
                $erros++;
                $detalhes[] = [
                    'beneficiario_id' => $benef->beneficiario_id,
                    'idintegracao'    => $benef->idintegracao,
                    'acao'            => 'ativar',
                    'mensagem'        => $resultado->mensagem ?? 'Erro ao ativar na Conexa',
                ];
            }
        }

        return response()->json([
            'ok'       => 'S',
            'mensagem' => 'Rotina de ativação concluída.',
            'total'    => $total,
            'sucesso'  => $sucesso,
            'pulados'  => $pulados,
            'erros'    => $erros,
            'detalhes' => $detalhes,
        ], 200);
    }

    /**
     * Busca Titulares adimplentes para seleção na interface.
     * GET /api/conexa/titulares-adimplentes
     */
    public function titularesAdimplentes(Request $request)
    {
        $search = $request->input('q', '');

        $query = DB::table('beneficiarios as b')
            ->join('contratos as c', 'c.id', '=', 'b.contrato_id')
            ->join('clientes as cl', 'cl.id', '=', 'b.cliente_id')
            ->leftJoin('beneficiario_produto as bp', function ($join) {
                $join->on('bp.beneficiario_id', '=', 'b.id')
                     ->where('bp.produto_id', '=', 4);
            })
            ->where('b.ativo', '=', 1)
            ->where('b.tipo', '=', 'T')
            ->where('c.situacao_pagto', '=', 'A')
            ->whereNotNull('b.cliente_id')
            ->select([
                'b.id as beneficiario_id',
                'cl.nome',
                'cl.cpfcnpj as cpf',
                DB::raw("COALESCE(bp.idintegracao, '') as idintegracao"),
                DB::raw("COALESCE(bp.ativacao, 0) as ativacao"),
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('cl.nome', 'like', "%{$search}%")
                  ->orWhere('cl.cpfcnpj', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('cl.nome')->limit(50)->get(), 200);
    }

    /**
     * Ativa Titulares específicos na Conexa.
     * POST /api/conexa/ativar-titulares-especificos
     * Body: { "beneficiario_ids": [1, 2, 3] }
     */
    public function ativarTitularesEspecificos(Request $request)
    {
        $ids = $request->input('beneficiario_ids', []);

        if (empty($ids) || !is_array($ids)) {
            return response()->json(['ok' => 'N', 'mensagem' => 'Informe ao menos um beneficiario_id.'], 422);
        }

        $total    = 0;
        $sucesso  = 0;
        $pulados  = 0;
        $erros    = 0;
        $detalhes = [];

        $beneficiarios = DB::table('beneficiarios as b')
            ->join('contratos as c', 'c.id', '=', 'b.contrato_id')
            ->join('clientes as cl', 'cl.id', '=', 'b.cliente_id')
            ->leftJoin('beneficiario_produto as bp', function ($join) {
                $join->on('bp.beneficiario_id', '=', 'b.id')
                     ->where('bp.produto_id', '=', 4);
            })
            ->where('b.tipo', '=', 'T')
            ->whereIn('b.id', $ids)
            ->select([
                'b.id as beneficiario_id',
                'b.tipo as beneficiario_tipo',
                'b.plano_id as beneficiario_plano_id',
                'b.parent_id',
                'c.plano_id as contrato_plano_id',
                'c.tipo as contrato_tipo',
                'bp.idintegracao',
                'bp.ativacao',
                'cl.cpfcnpj as cpf',
                'cl.nome',
            ])
            ->get();

        foreach ($beneficiarios as $benef) {
            $total++;
            $plano_id = $this->resolverPlanoId($benef);

            // Sem idintegracao: cadastrar e ativar
            if (empty($benef->idintegracao)) {
                $resultado = Conexa::associateBeneficiario($benef->beneficiario_id, 4);
                if ($resultado->ok === 'S') {
                    $sucesso++;
                } else {
                    $erros++;
                    $detalhes[] = [
                        'beneficiario_id' => $benef->beneficiario_id,
                        'nome'            => $benef->nome,
                        'acao'            => 'cadastrar+ativar',
                        'mensagem'        => $resultado->mensagem ?? 'Erro ao cadastrar na Conexa',
                    ];
                }
                continue;
            }

            // Com idintegracao: verificar status antes
            $status = Conexa::buscarPacienteStatus($benef->cpf, $plano_id);
            if ($status->ok === 'S' && isset($status->status) && strtoupper($status->status) === 'ACTIVE') {
                $pulados++;
                continue;
            }

            $resultado = Conexa::activate($benef->idintegracao, $plano_id);
            if ($resultado->ok === 'S') {
                $sucesso++;
            } else {
                $erros++;
                $detalhes[] = [
                    'beneficiario_id' => $benef->beneficiario_id,
                    'nome'            => $benef->nome,
                    'idintegracao'    => $benef->idintegracao,
                    'acao'            => 'ativar',
                    'mensagem'        => $resultado->mensagem ?? 'Erro ao ativar na Conexa',
                ];
            }
        }

        return response()->json([
            'ok'       => 'S',
            'mensagem' => 'Ativação de titulares concluída.',
            'total'    => $total,
            'sucesso'  => $sucesso,
            'pulados'  => $pulados,
            'erros'    => $erros,
            'detalhes' => $detalhes,
        ], 200);
    }

    /**
     * Desativa todos os beneficiários inadimplentes na Conexa.
     * POST /api/conexa/desativar-inadimplentes
     * Body: { "dias_inadimplencia": 30 }
     */
    public function desativarInadimplentes(Request $request)
    {
        $diasInadimplencia = (int) $request->input(
            'dias_inadimplencia',
            (int) env('CONEXA_DIAS_INADIMPLENCIA', 30)
        );

        $total    = 0;
        $sucesso  = 0;
        $erros    = 0;
        $detalhes = [];

        $beneficiarios = DB::table('beneficiario_produto as bp')
            ->join('beneficiarios as b', 'b.id', '=', 'bp.beneficiario_id')
            ->join('contratos as c', 'c.id', '=', 'b.contrato_id')
            ->whereNotNull('bp.idintegracao')
            ->where('bp.idintegracao', '!=', '')
            ->where('bp.ativacao', '=', 1)
            ->where('c.situacao_pagto', '=', 'I')
            ->where('c.dias_inadimplente', '>=', $diasInadimplencia)
            ->select([
                'bp.beneficiario_id', 'bp.idintegracao',
                'b.tipo as beneficiario_tipo', 'b.plano_id as beneficiario_plano_id', 'b.parent_id',
                'c.plano_id as contrato_plano_id', 'c.tipo as contrato_tipo', 'c.dias_inadimplente',
            ])
            ->get();

        foreach ($beneficiarios as $benef) {
            $total++;
            $resultado = Conexa::inactivate($benef->idintegracao, $this->resolverPlanoId($benef));
            if ($resultado->ok === 'S') {
                $sucesso++;
            } else {
                $erros++;
                $detalhes[] = [
                    'beneficiario_id'   => $benef->beneficiario_id,
                    'idintegracao'      => $benef->idintegracao,
                    'dias_inadimplente' => $benef->dias_inadimplente,
                    'mensagem'          => $resultado->mensagem ?? 'Erro desconhecido',
                ];
            }
        }

        return response()->json([
            'ok'                 => 'S',
            'mensagem'           => 'Rotina de desativação concluída.',
            'dias_inadimplencia' => $diasInadimplencia,
            'total'              => $total,
            'sucesso'            => $sucesso,
            'erros'              => $erros,
            'detalhes'           => $detalhes,
        ], 200);
    }

    /**
     * Resolve o plano_id correto conforme tipo de contrato/beneficiário.
     */
    private function resolverPlanoId($benef): int
    {
        if ($benef->contrato_tipo === 'F') {
            return (int) ($benef->contrato_plano_id ?? 0);
        }
        if ($benef->beneficiario_tipo === 'T') {
            return (int) ($benef->beneficiario_plano_id ?? 0);
        }
        if ($benef->parent_id) {
            $titular = DB::table('beneficiarios')->select('plano_id')->where('id', $benef->parent_id)->first();
            return (int) ($titular->plano_id ?? 0);
        }
        return 0;
    }

}
