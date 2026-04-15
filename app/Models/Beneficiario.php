<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Beneficiario extends Model
{
    use HasFactory;

    protected $table = 'beneficiarios';

    protected $fillable = [
        'contrato_id',
        'cliente_id',
        'vigencia_inicio',
        'vigencia_fim',
        'tipo',
        'ativo',
        'parent_id'
    ];

    public function contrato()
    {
        return $this->belongsTo(Contrato::class);
    }
	
	public function plano()
    {
        return $this->belongsTo(Plano::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function parentesco()
    {
        return $this->belongsTo(Parentesco::class);
    }
     /**
     * Relacionamento: Um titular pode ter muitos dependentes.
     */
    public function dependentes()
    {
        return $this->hasMany(Beneficiario::class, 'parent_id');
    }
    /**
     * Relacionamento: Um dependente pertence a um titular.
     */
    public function titular()
    {
        return $this->belongsTo(Beneficiario::class, 'parent_id');
    }
	
	public function clinicas()
    {
        return $this->belongsToMany(\App\Models\Clinica::class)->using(ClinicaBeneficiario::class);
    }
}