<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Especialidade extends Model
{
    use HasFactory;

    protected $table 	= 'especialidades';

    protected $fillable = [
        'nome',
		'tipo',
        'ativo'
    ];

    public function agendamentos()
    {
        return $this->hasMany(ClinicaBeneficiario::class);
    }

    public function clinicas()
    {
        return $this->belongsToMany(\App\Models\Clinica::class)->using(ClinicaEspecialidade::class);
    }

    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }
}
