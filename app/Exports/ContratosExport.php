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

class ContratosExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize, WithColumnFormatting {

	public $contratos;
	
    public function __construct($contratos)
    {
        $this->contratos           		= $contratos;		 
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
        $titulo[]                       = array('Contratos');
        $cabecalho                      = array();

		$cabecalho[] 					= 'Vendedor';
        $cabecalho[]                    = 'NºContrato';
        $cabecalho[]                    = 'Data';
		$cabecalho[]                    = 'Situação';
        $cabecalho[]                    = 'CPF/CNPJ';
        $cabecalho[]                    = 'Cliente';
        $cabecalho[]                    = 'Telefone';
        $cabecalho[]                    = 'Plano';
		$cabecalho[]                    = 'Valor Plano';
        $cabecalho[]                    = 'Forma de pagto';
		$cabecalho[]					= 'Assinado em';
        $cabecalho[]                    = 'NºParcela';
        $cabecalho[]                    = 'Vencimento';
        $cabecalho[]                    = 'Pagamento';
        $cabecalho[]                    = 'Baixa';
		$cabecalho[]                    = 'Valor Parcela';
		$cabecalho[]                    = 'Situação';

        $titulo[]                       = $cabecalho;

        return  $titulo; 
    }

    // freeze the first row with headings
    public function registerEvents(): array
    {
		 
        return [            
		  AfterSheet::class => function(AfterSheet $event) {
			  
                $event->sheet->getDelegate()->getStyle('A1:Q3')->getFont()->setSize(12);
                $event->sheet->mergeCells('A1:Q1');
                $event->sheet->mergeCells('A2:Q2');
                
                $event->sheet->getStyle('A1:Q2')->ApplyFromArray([
                                                        'borders' => [
                                                            'outline' => [
                                                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                                                ],
                                                            ],
                                                        ]);
                                                        
                $event->sheet->getDelegate()->getStyle('A1:Q1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->getDelegate()->getStyle('A2:Q2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $event->sheet->freezePane('A4');		                
			},
        ];
		 
    }


    public function collection()
    {
        $data   							= [];
     
        foreach ($this->contratos as $contrato) 
		{
			
			$csituacao            			= Cas::obterSituacaoContrato($contrato->status);	
			$formapagamento					= $contrato->mainPaymentMethodId;
            $colunas                        = array();

			$colunas[]                      = $contrato->vendedor;
 
            $colunas[]                      = $contrato->id;
			$colunas[]                      = date("d/m/Y", strtotime($contrato->created_at));
            $colunas[]                      = $csituacao;
            $colunas[]                      = $this->formatCnpjCpf($contrato->cpfcnpj);
            $colunas[]                      = $contrato->cliente;
            $colunas[]                      = $this->formatarTelefone($contrato->telefone);
            $colunas[]                      = $contrato->plano;
            $colunas[]                      = $contrato->valor;
			
			$valor_parcela					= "";
			$psituacao						= "";
			$data_vencimento				= "";
			$data_pagamento					= "";
			$data_baixa						= "";
			$nparcela 						= "";
			$assinado 						= "";
			
			if (!is_null($contrato->contractacceptedAt))
			{
				list($ano,$mes,$dia) 		= explode("-",substr($contrato->contractacceptedAt,0,10));
				$assinado					= $dia . "/" . $mes . "/". $ano . " " . substr($contrato->contractacceptedAt,11,05);
			}
			
			$parcela 				        = \App\Models\Parcela::where('contrato_id','=',$contrato->id)  
																 ->where('nparcela','=',1)  
																 ->first();
                                                                  
			if (isset($parcela->id))
			{
				$nparcela					= $parcela->nparcela;
				$data_vencimento			= date("d/m/Y", strtotime($parcela->data_vencimento));
				$psituacao 					= Cas::obterSituacaoParcela($parcela->data_vencimento,$parcela->data_pagamento,$parcela->data_baixa);
				if (!is_null($parcela->data_pagamento))
				{
					$data_pagamento         = date("d/m/Y", strtotime($parcela->data_pagamento));
				} 
				if (!is_null($parcela->data_baixa))
				{
					$data_baixa             = date("d/m/Y", strtotime($parcela->data_baixa));
				} 
				if  ($psituacao == 'Paga')
				{
					$formapagamento    		= $parcela->statusDescription;
				} 
				$valor_parcela				= $parcela->valor;
			}
			
			$colunas[]                      = $formapagamento;
			$colunas[]						= $assinado;
            $colunas[]                      = $nparcela;
            $colunas[]                      = $data_vencimento;
			$colunas[]                  	= $data_pagamento;
			$colunas[]                  	= $data_baixa;
			$colunas[]                      = $valor_parcela;
            $colunas[]                      = $psituacao;
            $data[]                         = $colunas;
        }

        return collect($data);
    }

    public function columnFormats(): array
    {
        return [
            'P' => 'R$ #,##0.00',
			'I' => 'R$ #,##0.00',
            'M' => 'dd/mm/yyyy',
			'N' => 'dd/mm/yyyy',
			'O' => 'dd/mm/yyyy',
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