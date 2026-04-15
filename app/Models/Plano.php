<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plano extends Model
{
    use HasFactory;

    protected $table = 'planos';

    protected $fillable = [
        'nome',
        'preco',
        'taxa_ativacao',
        'periodicidade_id',
        'parcelas',
        'qtde_beneficiarios',
        'formapagamento',
        'ativo'
    ];

    public function periodicidade()
    {
        return $this->belongsTo(Periodicidade::class);
    }

    public function contratos()
    {
        return $this->hasMany(Contrato::class);
    }

    public function produtos()
    {
        return $this->belongsToMany(\App\Models\Produto::class)->using(PlanoProduto::class);
    }
    
	public function vendedores()
    {
        return $this->belongsToMany(\App\Models\Vendedor::class)->using(PlanoVendedor::class);
    }
	
    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }
}
