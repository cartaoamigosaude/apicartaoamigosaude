<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SabemiEndosso extends Model
{
    use HasFactory;

    protected $table = 'sabemi_endossos';

    protected $fillable = [
        'numero_endosso',
        'codigo_apolice',
        'codigo_grupo',
        'status_endosso',
        'data_abertura',
        'data_fechamento',
        'total_inclusoes',
        'total_exclusoes',
        'total_alteracoes',
        'total_sucesso',
        'total_erro',
        'erro_abertura',
        'erro_fechamento'
    ];

    protected $casts = [
        'data_abertura' => 'datetime',
        'data_fechamento' => 'datetime',
    ];
}
