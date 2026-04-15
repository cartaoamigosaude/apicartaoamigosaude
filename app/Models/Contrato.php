<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contrato extends Model
{

    protected $table = 'contratos';

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function plano()
    {
        return $this->belongsTo(Plano::class);
    }

    public function vendedor()
    {
        return $this->belongsTo(Vendedor::class);
    }

    public function situacao()
    {
        return $this->belongsTo(Situacao::class);
    }

    public function beneficiarios()
    {
        return $this->hasMany(Beneficiario::class);
    }

    public function parcelas()
    {
        return $this->hasMany(Parcela::class);
    }

    // =========================================================================
    // SCOPES SABEMI
    // =========================================================================

    /**
     * Filtra apenas contratos que possuem seguro Sabemi (Produtos 7, 11, 12, 13).
     */
    public function scopeSabemi($query)
    {
        return $query->whereHas('plano.produtos', function ($q) {
            $q->whereIn('produtos.id', [7, 11, 12, 13]);
        });
    }

    /**
     * Filtra contratos ativos e elegíveis para inclusão na Sabemi.
     * Regra: Status 'active' ou 'waitingPayment' E 1ª Parcela Paga.
     */
    public function scopeAtivoSabemi($query)
    {
        return $query->whereIn('status', ['active', 'waitingPayment'])
                     ->whereHas('parcelas', function ($q) {
                         $q->where('nparcela', 1)
                           ->whereNotNull('data_pagamento');
                     });
    }

    /**
     * Filtra contratos cancelados ou parados na Sabemi.
     * Regra: Status 'closed', 'canceled', 'stopped', 'suspended'.
     */
    public function scopeCanceladoSabemi($query)
    {
        return $query->whereIn('status', ['closed', 'canceled', 'stopped', 'suspended']);
    }
}
