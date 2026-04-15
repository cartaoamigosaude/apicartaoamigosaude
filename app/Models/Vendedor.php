<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendedor extends Model
{
    use HasFactory;

    protected $table = 'vendedores';

    protected $fillable = [
        'nome',
        'ativo',
        'user_id'
    ];

    public function contratos()
    {
        return $this->hasMany(Contrato::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

	public function planos()
    {
        return $this->belongsToMany(\App\Models\Plano::class)->using(PlanoVendedor::class);
    }
	
    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }
}
