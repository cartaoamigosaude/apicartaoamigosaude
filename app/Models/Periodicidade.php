<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Periodicidade extends Model
{
    use HasFactory;

    protected $table = 'periodicidades';

    protected $fillable = [
        'nome',
        'ativo'
    ];

    public function planos()
    {
        return $this->hasMany(Plano::class);
    }

    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }
}
