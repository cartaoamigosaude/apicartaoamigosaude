<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    use HasFactory;

    protected $table 	= 'produtos';

    protected $fillable = [
        'nome',
        'descricao',
        'ativo'
    ];

    public function planos()
    {
        return $this->belongsToMany(\App\Models\Plano::class)->using(PlanoProduto::class);
    }

    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }
}
