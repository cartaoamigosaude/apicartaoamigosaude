<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Motivo extends Model
{
    use HasFactory;

    protected $table = 'motivos';

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
