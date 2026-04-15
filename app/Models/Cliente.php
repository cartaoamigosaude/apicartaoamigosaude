<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Helpers\Cas;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    protected $fillable = [
        'tipo',
        'cpfcnpj',
        'nome',
        'telefone',
        'email',
        'data_nascimento',
        'sexo',
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
        'ativo',
        'observacao'
    ];

    public function contratos()
    {
        return $this->hasMany(Contrato::class);
    }

    public function beneficiarios()
    {
        return $this->hasMany(Beneficiario::class);
    }
	
	public function agendamentos()
    {
        return $this->hasMany(ClinicaBeneficiario::class);
    }

    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }

    // Mutator para formatar CPF/CNPJ com zeros à esquerda antes de salvar no banco
    public function setCpfcnpjAttribute($value)
    {
        // Verificar se a propriedade 'tipo' existe e está definida
        $tipo = isset($this->attributes['tipo']) ? $this->attributes['tipo'] : (isset($this->tipo) ? $this->tipo : null);
        
        if ($tipo === 'F') {
            // Preencher com zeros à esquerda para CPF (11 dígitos)
			$value						 = preg_replace('/\D/', '', $value);
            $this->attributes['cpfcnpj'] = str_pad($value, 11, '0', STR_PAD_LEFT);
        } elseif ($tipo === 'J') {
            // Preencher com zeros à esquerda para CNPJ (14 dígitos)
			$value						 = preg_replace('/\D/', '', $value);
            $this->attributes['cpfcnpj'] = str_pad($value, 14, '0', STR_PAD_LEFT);
        } else {
			$value						 = preg_replace('/\D/', '', $value);
            // Se o tipo não está definido, assumir CPF como padrão (11 dígitos)
            $this->attributes['cpfcnpj'] = str_pad($value, 11, '0', STR_PAD_LEFT);
        }
    }

    // Accessor para formatar CPF ou CNPJ quando exibir
    public function getCpfcnpjAttribute($value)
    {
        return Cas::formatarCPFCNPJ($value,$this->tipo);
    }
}
