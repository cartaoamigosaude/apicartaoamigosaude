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

class ParcelasExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize, WithColumnFormatting {

	public $parcelas;
	
    public function __construct($parcelas)
    {
        $this->parcelas           		= $parcelas;		 
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
        $titulo[]                       = array('Parcelas');
        $cabecalho                      = array();

        $cabecalho[]                    = 'NºContrato';
        $cabecalho[]                    = 'Situação';
        $cabecalho[]                    = 'CPF/CNPJ';
        $cabecalho[]                    = 'Cliente';
        $cabecalho[]                    = 'Telefone';
        $cabecalho[]                    = 'Plano';
        $cabecalho[]                    = 'Forma de pagto';
        $cabecalho[]                    = 'NºParcela';
        $cabecalho[]                    = 'Vencimento';
		$cabecalho[]                    = 'Dias';
        $cabecalho[]                    = 'Pagamento';
        $cabecalho[]                    = 'Baixa';
		$cabecalho[]                    = 'Valor';
		$cabecalho[]                    = 'Situação';
		$cabecalho[]                    = 'Observação';

        $titulo[]                       = $cabecalho;

        return  $titulo; 
    }

    // freeze the first row with headings
    public function registerEvents(): array
    {
		 
        return [            
		  AfterSheet::class => function(AfterSheet $event) {
			  
                $event->sheet->getDelegate()->getStyle('A1:O3')->getFont()->setSize(12);
                $event->sheet->mergeCells('A1:O1');
                $event->sheet->mergeCells('A2:O2');
                
                $event->sheet->getStyle('A1:O2')->ApplyFromArray([
                                                        'borders' => [
                                                            'outline' => [
                                                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                                                ],
                                                            ],
                                                        ]);
                                                        
                $event->sheet->getDelegate()->getStyle('A1:O1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->getDelegate()->getStyle('A2:O2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->freezePane('A4');		                
			},
        ];
		 
    }


    public function collection()
    {
        $data   							= [];
     
        foreach ($this->parcelas as $parcela) 
		{
			
			$parcela->psituacao 			= Cas::obterSituacaoParcela($parcela->data_vencimento,$parcela->data_pagamento,$parcela->data_baixa);
			$parcela->csituacao             = Cas::obterSituacaoContrato($parcela->status);
			
			if ((!is_null($parcela->data_pagamento)) and ($parcela->valor_pago > 0))
			{
				$parcela->valor 			= $parcela->valor_pago;
			}
            if  ($parcela->psituacao == 'Paga')
            {
                $parcela->formapagamento    = $parcela->statusDescription;
            }  
			
			if  ($parcela->psituacao != 'Vencida')
            {
				$parcela->dias 				= "";
			}	
			
			 if (($parcela->psituacao == 'Baixada') and ($parcela->negociar =='S'))
            {
                $parcela->psituacao    			= 'Negociada';
            } 
			
            $colunas                        = array();

            $colunas[]                      = $parcela->id;
            $colunas[]                      = $parcela->csituacao;
            $colunas[]                      = $this->formatCnpjCpf($parcela->cpfcnpj);
            $colunas[]                      = $parcela->cliente;
            $colunas[]                      = $this->formatarTelefone($parcela->telefone);
            $colunas[]                      = $parcela->plano;
            $colunas[]                      = $parcela->formapagamento;
            $colunas[]                      = $parcela->nparcela;
            $colunas[]                      = date("d/m/Y", strtotime($parcela->data_vencimento));
			$colunas[]                      = $parcela->dias;
			
			if (!is_null($parcela->data_pagamento))
			{
				$colunas[]                  = date("d/m/Y", strtotime($parcela->data_pagamento));
			} else {
				$colunas[]                  = "";
			}
			if (!is_null($parcela->data_baixa))
			{
				$colunas[]                  = date("d/m/Y", strtotime($parcela->data_baixa));
			} else {
				$colunas[]                  = "";
			}
			$colunas[]                      = $parcela->valor;
            $colunas[]                      = $parcela->psituacao;
			$colunas[]                      = $parcela->observacao;

            $data[]                         = $colunas;
        }

        return collect($data);
    }

    public function columnFormats(): array
    {
        return [
            'M' => 'R$ #,##0.00',
            'I' => 'dd/mm/yyyy',
			'K' => 'dd/mm/yyyy',
			'L' => 'dd/mm/yyyy',
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