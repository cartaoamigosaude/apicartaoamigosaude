<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;
use App\Helpers\Cas;

class ClinicaBeneficiario extends Pivot
{
    protected $table 		= 'clinica_beneficiario';
    public $incrementing 	= true;
	public $timestamps 		= true;
	protected $appends 		= ['dsituacao'];

    public function clinica()
    {
        return $this->belongsTo(Clinica::class);
    }

    public function beneficiario()
    {
        return $this->belongsTo(Beneficiario::class);
    }
	
	public function especialidade()
    {
        return $this->belongsTo(Especialidade::class);
    }
	
	public function situacao()
    {
        return $this->belongsTo(Asituacao::class);
    }
	
	public function predatas()
    {
        return $this->hasMany(ClinicaBeneficiarioData::class);
    }
	
	public function getDsituacaoAttribute()
    {
		/*
	  'aguardando_confirmacao',
	  'confirmado',
	  'cancelado',
	  'nao_compareceu',
	  'em_andamento',
	  'concluido',
	  'reagendado',
	  'pendencia_pagamento',
	  
	  Aguardando Confirmação: Quando o paciente agenda, mas ainda não confirmou.
	  Confirmado: Quando o paciente confirma o agendamento e/ou faz o pagamento.
	  Cancelado: O paciente decide cancelar por conflitos de agenda um já pago.
	  Reagendado: A consulta é transferida para uma nova data.

		*/
		
	   if (isset($this->attributes['situacao']))
	   {
		  return Cas::situacaoAgendamento($this->attributes['situacao']);
	   }
	   
	   return "";
    }
}