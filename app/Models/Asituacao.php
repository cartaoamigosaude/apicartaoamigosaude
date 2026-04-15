<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asituacao extends Model
{
    use HasFactory;

    protected $table = 'asituacoes';

    protected $fillable = [
        'nome',
		'orientacao',
		'whatsapp',
		'whatsappc',
        'ativo'
    ];

    public function agendamentos()
    {
        return $this->hasMany(ClinicaBeneficiario::class);
    }

    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }
}
