<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SabemiMovimentacao extends Model
{
    use HasFactory;

    protected $table = 'sabemi_movimentacoes';

    protected $fillable = [
        'beneficiario_id',
        'codigo_endosso',
        'tipo_movimentacao', // I, E, A
        'status_envio',
        'payload_enviado',
        'resposta_sabemi',
        'erro',
        'data_envio'
    ];

    protected $casts = [
        'data_envio' => 'datetime',
        'payload_enviado' => 'array',
        'resposta_sabemi' => 'array',
    ];

    public function beneficiario()
    {
        return $this->belongsTo(Beneficiario::class);
    }
}