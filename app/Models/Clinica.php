<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Helpers\Cas;

class Clinica extends Model
{
    use HasFactory;

    protected $table = 'clinicas';

    protected $fillable = [
		'tipo',
        'cnpj',
        'nome',
        'telefone',
        'email',
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
		'mostrar_valor',
        'ativo',
    ];

    public function especialidades()
    {
        return $this->belongsToMany(\App\Models\Especialidade::class)->using(ClinicaEspecialidade::class);
    }

	public function beneficiarios()
    {
        return $this->belongsToMany(\App\Models\Beneficiario::class)->using(ClinicaBeneficiario::class);
    }
	
	public function agendamentos()
    {
        return $this->hasMany(Agendamento::class);
    }
	
    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }

    // Mutator para formatar CPF/CNPJ com zeros à esquerda antes de salvar no banco
    public function setCnpjAttribute($value)
    {
        $this->attributes['cnpj'] = str_pad($value, 14, '0', STR_PAD_LEFT);
    }

    public function getCnpjAttribute($value)
    {
        return Cas::formatarCPFCNPJ($value,'J');
    }
}
