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

class AtivarDesativarExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize, WithColumnFormatting {

	public $payload;
	public $beneficiarios;
	
    public function __construct($payload,$beneficiarios)
    {
        $this->payload           		= $payload;	
		$this->beneficiarios           	= $beneficiarios;		
    }

    // set the headings
	public function startCell(): string
    {
        return 'A2';
    }
	
    public function headings(): array
    {
        $titulo                         = array();
        $cabecalho                      = array();
        $titulo[]                       = $cabecalho;

        return  $titulo; 
    }

    // freeze the first row with headings
    public function registerEvents(): array
    {	 
	
	 return [            
		  AfterSheet::class => function(AfterSheet $event) {
			  
                $event->sheet->mergeCells('A1:W1');
                $event->sheet->mergeCells('A2:W2');
                $event->sheet->freezePane('A2');		                
			},
        ];
		
    }

    public function collection()
    {
        $data   								= [];
		
		if ($this->payload->ativarbloquear =='A')
		{
			$acao 								= 'I';
		} else {
			$acao 								= 'A';
		}
		
		 $colunas[]                      		= "Tipo de Registro (FIXO)";	
		 $colunas[]                      		= "Ação";	
		 $colunas[]                      		= "Código do Beneficio";	
		 $colunas[]                      		= "Carteira Beneficiario Titular";	
		 $colunas[]                      		= "Carteira Beneficiario ou dependente";	
		 $colunas[]                      		= "Nome Completo";	
		 $colunas[]                      		= "Nome Cartão"	;
		 $colunas[]                      		= "Data de Nascimento";	
		 $colunas[]                      		= "Sexo";	
		 $colunas[]                      		= "Numero do Documento"	;
		 $colunas[]                      		= "Órgao Emissor";	
		 $colunas[]                      		= "Numero CPF";
		 $colunas[]                      		= "Endereço";	
		 $colunas[]                      		= "Numero";	
		 $colunas[]                      		= "Complemento"	;
		 $colunas[]                      		= "Bairro";
		 $colunas[]                      		= "Cidade";	
		 $colunas[]                      		= "Estado";	
		 $colunas[]                      		= "CEP"	;
		 $colunas[]                      		= "Inicio de Vigência";	
		 $colunas[]                      		= "Termino de Vigencia";
		 $colunas[]                      		= "Código Processamento";	
		 $colunas[]                      		= "Matricula";	
		 $colunas[]                      		= "Filler";	
		 $colunas[]                      		= "Numero de Registro";
		 $data[]                     			= $colunas;

        foreach ($this->beneficiarios as $beneficiario) 
		{
			 $colunas[]                      	= '01';
			 $colunas[]                      	= $acao;
			 $colunas[]                      	= '244926';
			
			 $cpf_dependente					= $beneficiario->cpfcnpj;
			 $cpf_titular						= "";
			 
			 if ($beneficiario->tipo =='T')
			 {
				 $cpf_titular					= $beneficiario->cpfcnpj;
			 } else {
				if ($beneficiario->tipo_contrato = 'F')
				{
					$titular					= DB::connection('mysql')
															->table('beneficiarios')
															->select('clientes.cpfcnpj')
															->join('clientes','beneficiarios.cliente_id','=','clientes.id')
															->where('beneficiarios.contrato_id','=',$beneficiario->contrato_id)
															->where('beneficiarios.tipo','=','T')
															->first();	
					if (isset($titular->cpfcnpj))
					{
						$cpf_titular			= $$titular->cpfcnpj;
					} 
				} else {
					$titular					= DB::connection('mysql')
															->table('beneficiarios')
															->select('clientes.cpfcnpj')
															->join('clientes','beneficiarios.cliente_id','=','clientes.id')
															->where('beneficiarios.contrato_id','=',$beneficiario->contrato_id)
															->where('beneficiarios.id','=',$beneficiario->parent_id)
															->first();	
					if (isset($titular->cpfcnpj))
					{
						$cpf_titular			= $$titular->cpfcnpj;
					} 
				}
			 }
			 
			 $colunas[]                      	= $cpf_titular;
			 $colunas[]                      	= $cpf_dependente;
			 $colunas[]                      	= trim(Cas::removerAcentosEMaiusculo($beneficiario->cliente));
			 $colunas[]                      	= trim(Cas::removerAcentosEMaiusculo($beneficiario->cliente));
			 list($ano,$mes,$dia)         		= explode("-",$beneficiario->data_nascimento);
			 $colunas[]                      	= "$dia/$mes/$ano";
			 $colunas[]                      	= $beneficiario->sexo;
			 $colunas[]                      	= $cpf_dependente;
			 $colunas[]                      	= "";
			 $colunas[]                      	= $cpf_dependente;
			 $colunas[]                      	= "";
			 $colunas[]                      	= "";
			 $colunas[]                      	= "";
			 $colunas[]                      	= "";
			 $colunas[]                      	= "";
			 $colunas[]                      	= "";
		     $colunas[]                      	= "";
			 $colunas[]                      	= "";
			 $colunas[]                      	= "";
			 if ($acao=='I')
			 {
				$colunas[]                      = date('d/m/Y');
				$colunas[]                      = "";
			 } else {
				$colunas[]                      = "";
				$colunas[]                      = date('d/m/Y');
			 }
			 $colunas[]                      	= "2";
			 $colunas[]                      	= $cpf_dependente;
			 $colunas[]                      	= "";
			 $colunas[]                      	= "00002";
			 $data[]                     		= $colunas;
        }

        return collect($data);
    }
	
	public function columnFormats(): array
    {
        return [
            'H' => 'dd/mm/yyyy',
        ];
    }

}