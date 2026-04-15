<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AgendamentoHistorico extends Model
{
	
    protected $table = 'clinica_beneficiario_historico';
	
	public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

}
