<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Situacao extends Model
{
    use HasFactory;

    protected $table = 'situacoes';

    protected $fillable = [
        'nome',
        'ativo'
    ];

    public function contratos()
    {
        return $this->hasMany(Contrato::class);
    }

    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }
}
