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

class AgendamentosExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize, WithColumnFormatting {

	public $agendamentos;
	
    public function __construct($agendamentos)
    {
        $this->agendamentos           		= $agendamentos;		 
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
        $titulo[]                       = array('Agendamentos');
        $cabecalho                      = array();

		$cabecalho[] 					= 'Id';
        $cabecalho[]                    = 'CPF';
		$cabecalho[]                    = 'Beneficiario';
		$cabecalho[]                    = 'Nascimento';
		$cabecalho[]                    = 'Idade';
		$cabecalho[]                    = 'Tipo';
        $cabecalho[]                    = 'Especialidade';
		$cabecalho[]                    = 'Cidade';
        $cabecalho[]                    = 'Estado';
        $cabecalho[]                    = 'Clinica';
		$cabecalho[]                    = 'Valor';
		$cabecalho[] 					= 'Solicitado';
        $cabecalho[]                    = 'Agendado';
		$cabecalho[]                    = 'Vencimento';
	    $cabecalho[]                    = 'Pagamento';
		$cabecalho[]                    = 'Cancelado';
		$cabecalho[]                    = 'Situação';
		$cabecalho[]                    = 'Motivo';
		$cabecalho[]                    = 'Observação';
        $titulo[]                       = $cabecalho;

        return  $titulo; 
    }

    // freeze the first row with headings
    public function registerEvents(): array
    {
		 
        return [            
		  AfterSheet::class => function(AfterSheet $event) {
			  
                $event->sheet->getDelegate()->getStyle('A1:S3')->getFont()->setSize(12);
                $event->sheet->mergeCells('A1:S1');
                $event->sheet->mergeCells('A2:S2');
                
                $event->sheet->getStyle('A1:S2')->ApplyFromArray([
                                                        'borders' => [
                                                            'outline' => [
                                                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                                                ],
                                                            ],
                                                        ]);
                                                        
                $event->sheet->getDelegate()->getStyle('A1:S1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->getDelegate()->getStyle('A2:S2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->freezePane('A4');		                
			},
        ];
		 
    }


    public function collection()
    {
        $data   							= [];
     
        foreach ($this->agendamentos as $agendamento) 
		{
			
            $colunas                        = array();
            $colunas[]                      = $agendamento->id;
			
			$colunas[]                      = $this->formatCnpjCpf($agendamento->cpf);
		    $colunas[]                      = $agendamento->beneficiario;
			$colunas[]                      = date("d/m/Y", strtotime($agendamento->data_nascimento));
			$colunas[]                      = $agendamento->idade;
			$colunas[]                      = $agendamento->tipo == 'T' ? 'Titular' : 'Dependente';
			$colunas[]                      = $agendamento->especialidade;
			$colunas[]                      = $agendamento->cidade;
			$colunas[]                      = $agendamento->estado;
			$colunas[]                      = $agendamento->clinica;
			$colunas[]                      = $agendamento->valor;
			
			$colunas[]                      = date("d/m/Y H:m", strtotime($agendamento->created_at));
			
			if (!is_null($agendamento->agendamento_data_hora))
			{
				list($ano,$mes,$dia) 		= explode("-",substr($agendamento->agendamento_data_hora,0,10));
				$hora 						= substr($agendamento->agendamento_data_hora,11,5);
				$colunas[]                  = $dia . "/" . $mes ."/" .$ano . " " .$hora;
			} else {
				$colunas[]					= "";		
			}
			
			if (!is_null($agendamento->vencimento))
			{
				list($ano,$mes,$dia) 		= explode("-",$agendamento->vencimento);
				$colunas[]                  =  "$dia/$mes/$ano";
			} else {
				$colunas[]					= "";	
			}
			
			if (!is_null($agendamento->pagamento_data_hora))
			{
				list($ano,$mes,$dia) 		= explode("-",substr($agendamento->pagamento_data_hora,0,10));
				$hora 						= substr($agendamento->pagamento_data_hora,11,5);
				$colunas[]                  = $dia . "/" . $mes ."/" .$ano . " " .$hora;
			} else {
				$colunas[]					= "";	
			}
			
			
			if (!is_null($agendamento->cancelado_data_hora))
			{
				list($ano,$mes,$dia) 		= explode("-",substr($agendamento->cancelado_data_hora,0,10));
				$hora 						= substr($agendamento->cancelado_data_hora,11,5);
				$colunas[]                  = $dia . "/" . $mes ."/" .$ano . " " .$hora;
			} else {
				$colunas[]					= "";	
			}
				
            $colunas[]                      = $agendamento->dsituacao;
            $colunas[]                      = $agendamento->motivo;
			$colunas[]                      = $agendamento->observacao;
            $data[]                         = $colunas;
        }

        return collect($data);
    }

    public function columnFormats(): array
    {
        return [
            'K' => 'R$ #,##0.00'
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