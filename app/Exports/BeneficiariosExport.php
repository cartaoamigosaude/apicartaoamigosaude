<?php

namespace App\Exports;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithMapping;
use App\Helpers\Cas;
use DB;

class BeneficiariosExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize, WithColumnFormatting {

	public $beneficiarios;
	
    public function __construct($beneficiarios)
    {
        $this->beneficiarios           		= $beneficiarios;		 
    }

    // set the headings
	public function startCell(): string
    {
        return 'A4';
    }
	
    public function headings(): array
    {

        $titulo                         = array();
        $titulo[]                       = array('CARTÃO AMIGO SAÚDE');
        $titulo[]                       = array('Beneficiarios');
        $cabecalho                      = array();

		$cabecalho[]                    = 'ID';
        $cabecalho[]                    = 'NºContrato';
		$cabecalho[]                    = 'Cliente';
        $cabecalho[]                    = 'Situação';
		$cabecalho[]                    = 'Plano';
		$cabecalho[]                    = 'Tipo';
        $cabecalho[]                    = 'CPF';
        $cabecalho[]                    = 'Beneficiário';
		$cabecalho[]                    = 'Data Nascimento';
		$cabecalho[]                    = 'Sexo';
        $cabecalho[]                    = 'Telefone';
		$cabecalho[]                    = 'Email';
		$cabecalho[]                    = 'Cep';
		$cabecalho[]                    = 'Endereço';
        $cabecalho[]                    = 'Número';
        $cabecalho[]                    = 'Complemento';
		$cabecalho[]                    = 'Bairro';
		$cabecalho[]                    = 'Cidade';
		$cabecalho[]                    = 'Estado';
		$cabecalho[]                    = 'Situação';
		$cabecalho[]                    = 'Clube Certo';
		$cabecalho[]                    = 'Epharma';
		$cabecalho[]                    = 'Conexa';
		$cabecalho[]                    = 'Seguro';
		$cabecalho[]                    = 'Inicio';
		$cabecalho[]                    = 'Fim';
        $titulo[]                       = $cabecalho;

        return  $titulo; 
    }

    // freeze the first row with headings
    public function registerEvents(): array
    {
		 
        return [            
		  AfterSheet::class => function(AfterSheet $event) {
			  
                $event->sheet->getDelegate()->getStyle('A1:Z3')->getFont()->setSize(12);
                $event->sheet->mergeCells('A1:Z1');
                $event->sheet->mergeCells('A2:Z2');
                
                $event->sheet->getStyle('A1:Z2')->ApplyFromArray([
                                                        'borders' => [
                                                            'outline' => [
                                                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                                                ],
                                                            ],
                                                        ]);
                                                        
                $event->sheet->getDelegate()->getStyle('A1:Z1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->getDelegate()->getStyle('A2:Z2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->freezePane('A4');		                
			},
        ];
		 
    }

    public function collection()
    {
        $data   							= [];
     
        foreach ($this->beneficiarios as $beneficiario) 
		{
			/*		
					$cabecalho[]                    = 'Clube Certo';
					$cabecalho[]                    = 'Epharma';
					$cabecalho[]                    = 'Conexa';
					$cabecalho[]                    = 'Seguro';
			*/		
			
			if ($beneficiario->tipo_contrato == 'F')
			{
				$plano              		= \App\Models\Plano::select('id','nome')->find($beneficiario->cplano_id);
			} else {
				$plano              		= \App\Models\Plano::select('id','nome')->find($beneficiario->bplano_id);
			}	
			
			$nome_plano						= "";
			
			if (isset($plano->id))
			{
				$nome_plano 				= $plano->nome;
			}
			
			list($ano,$mes,$dia) 			= explode("-",$beneficiario->data_nascimento);
            $colunas                        = array();
			$colunas[]                      = $beneficiario->id;
            $colunas[]                      = $beneficiario->contrato_id;
            $colunas[]                      = $beneficiario->cliente;
			$colunas[]                      = Cas::obterSituacaoContrato($beneficiario->status);
			$colunas[]                      = $nome_plano;
			$colunas[]                      = ucfirst(strtolower($beneficiario->tipo_usuario));
            $colunas[]                      = $this->formatCnpjCpf($beneficiario->cpfcnpj);
            $colunas[]                      = $beneficiario->beneficiario;
			$colunas[]                      = "$dia/$mes/$ano";
			$colunas[]                      = $beneficiario->sexo;
            $colunas[]                      = $this->formatarTelefone($beneficiario->telefone);
			$colunas[]                      = $beneficiario->email;
			$colunas[]                      = $beneficiario->cep;
			$colunas[]                      = $beneficiario->logradouro;
			$colunas[]                      = $beneficiario->numero;
			$colunas[]                      = $beneficiario->complemento;
			$colunas[]                      = $beneficiario->bairro;
			$colunas[]                      = $beneficiario->cidade;
			$colunas[]                      = $beneficiario->estado;
			$colunas[]                      = ucfirst(strtolower($beneficiario->desc_status));
			
			
			$beneficiarioproduto  			= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario->id)
																			 ->where('produto_id','=',2)
																			 ->first();														 
			if ((isset($beneficiarioproduto)) and ($beneficiarioproduto->ativacao==1))
			{
				$colunas[]              	= 'X';
			} else {
				$colunas[]              	= '';
			}
					
			$beneficiarioproduto  			= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario->id)
																		 ->where('produto_id','=',3)
																		 ->first();														 
			if ((isset($beneficiarioproduto)) and ($beneficiarioproduto->ativacao==1))
			{
				$colunas[]              	= 'X';
			} else {
				$colunas[]              	= '';
			}
					
			$beneficiarioproduto  			= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$beneficiario->id)
																				 ->where('produto_id','=',4)
																				 ->first();														 
			if ((isset($beneficiarioproduto))and ($beneficiarioproduto->ativacao==1))
			{
				$colunas[]              	= 'X';
			} else {
				$colunas[]              	= '';
			}
			
			$colunas[]              		= '';
			list($ano,$mes,$dia) 			= explode("-",$beneficiario->vigencia_inicio);
			$colunas[]                  	= "$dia/$mes/$ano";
			list($ano,$mes,$dia) 			= explode("-",$beneficiario->vigencia_fim);
			$colunas[]                  	= "$dia/$mes/$ano";
			
			$colunas[]                  	= '';
		
            $data[]                         = $colunas;
			
			if ($beneficiario->tipo == 'T')
			{
				if ($beneficiario->tipo_contrato == 'F')
				{	
					$dependentes 				= \App\Models\Beneficiario::with('cliente')
																		 ->where('contrato_id','=',$beneficiario->contrato_id)
																		 ->where('tipo','=','D')
																		 ->where('desc_status','=','ATIVO')
																		 ->get();
				} else {
					$dependentes 				= \App\Models\Beneficiario::with('cliente')
																	     ->where('parent_id','=',$beneficiario->id)
																		 ->where('tipo','=','D')
																		 ->where('desc_status','=','ATIVO')
																		  ->get();
				}
			
				foreach ($dependentes as $dependente)
				{
					list($ano,$mes,$dia) 		= explode("-",$dependente->cliente->data_nascimento);
					$colunas                    = array();
					$colunas[]                  = "";
					$colunas[]                  = "";
					$colunas[]                  = "";
					$colunas[]                  = "";
					$colunas[]                  = $nome_plano;
					$colunas[]                  = ucfirst(strtolower($dependente->tipo_usuario));
					$colunas[]                  = $this->formatCnpjCpf($dependente->cliente->cpfcnpj);
					$colunas[]                  = $dependente->cliente->nome;
					$colunas[]                  = "$dia/$mes/$ano";
					$colunas[]                  = $dependente->cliente->sexo;
					$colunas[]                  = $this->formatarTelefone($dependente->cliente->telefone);
					$colunas[]                  = $dependente->cliente->email;
					$colunas[]                  = $dependente->cliente->cep;
					$colunas[]                  = $dependente->cliente->logradouro;
					$colunas[]                  = $dependente->cliente->numero;
					$colunas[]                  = $dependente->cliente->complemento;
					$colunas[]                  = $dependente->cliente->bairro;
					$colunas[]                  = $dependente->cliente->cidade;
					$colunas[]                  = $dependente->cliente->estado;
					$colunas[]                  = ucfirst(strtolower($dependente->desc_status));
					
					$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$dependente->id)
																				 ->where('produto_id','=',2)
																				 ->first();														 
					if ((isset($beneficiarioproduto)) and ($beneficiarioproduto->ativacao==1))
					{
						$colunas[]              = 'X';
					} else {
						$colunas[]              = '';
					}
					
					$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$dependente->id)
																				 ->where('produto_id','=',3)
																				 ->first();														 
					if ((isset($beneficiarioproduto)) and ($beneficiarioproduto->ativacao==1))
					{
						$colunas[]              = 'X';
					} else {
						$colunas[]              = '';
					}
					
					$beneficiarioproduto  		= \App\Models\BeneficiarioProduto::where('beneficiario_id','=',$dependente->id)
																				 ->where('produto_id','=',4)
																				 ->first();														 
					if ((isset($beneficiarioproduto)) and ($beneficiarioproduto->ativacao==1))
					{
						$colunas[]              = 'X';
					} else {
						$colunas[]              = '';
					}
					
					$colunas[]              		= '';
					list($ano,$mes,$dia) 			= explode("-",$dependente->vigencia_inicio);
					$colunas[]                  	= "$dia/$mes/$ano";
					list($ano,$mes,$dia) 			= explode("-",$dependente->vigencia_fim);
					$colunas[]                  	= "$dia/$mes/$ano";
			
					$colunas[]                  = '';
					
					$data[]                     = $colunas;
				}
			}
        }

        return collect($data);
    }

    public function columnFormats(): array
    {
        return [
            'I' => 'dd/mm/yyyy',
        ];
    }

    function formatCnpjCpf($value)
	{
		$CPF_LENGTH = 11;
		$cnpj_cpf = preg_replace("/\D/", '', $value);
		
		if (strlen($cnpj_cpf) === $CPF_LENGTH) {
			return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
		} 
		
		return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
	}

	public static function formatarTelefone($value) 
	{
		$value 				= preg_replace("/\D/", '', $value);

		if (($value == '') or ($value == null))
		{
			return '';
		}

		$telefone 			 = '(';
		$telefone 			.= substr($value,0, 2);
		$telefone 			.= ') ';
		if (strlen($value) == 10)
		{
			$telefone 		.= '9' . substr($value,2, 4);
			$telefone 		.= '-';
			$telefone 		.= substr($value,6,4);
		} else
		{
			$telefone 		.= substr($value,2, 5);
			$telefone 		.= '-';
			$telefone 		.= substr($value,7,4);
	
		}
		
		return $telefone;
	}
}