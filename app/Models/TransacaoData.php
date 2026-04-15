<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransacaoData extends Model
{
    use HasFactory;

    /**
     * Nome da tabela no banco de dados
     */
    protected $table = 'transacoes_data';

    /**
     * A chave primária da tabela
     */
    protected $primaryKey = 'id';

    /**
     * Indica se o modelo deve usar timestamps automáticos
     */
    public $timestamps = true;

    /**
     * Nome das colunas de timestamp
     */
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    /**
     * Os atributos que podem ser preenchidos em massa
     */
    protected $fillable = [
        'data',
        'total',
        'conciliados',
        'divergentes',
        'status',
    ];

    /**
     * Os atributos que devem ser convertidos para tipos nativos
     */
    protected $casts = [
        'id' => 'integer',
        'data' => 'date',
        'total' => 'integer',
        'conciliados' => 'integer',
        'divergentes' => 'integer',
        'status' => 'string',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * Relacionamento: Uma transação de data tem muitas divergências
     */
    public function divergencias()
    {
        return $this->hasMany(TransacaoDivergencia::class, 'transacao_data_id');
    }

    /**
     * Scope para buscar por status
     */
    public function scopeComStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para buscar por data
     */
    public function scopePorData($query, $data)
    {
        return $query->whereDate('data', $data);
    }

    /**
     * Accessor para status formatado
     */
    public function getStatusFormatadoAttribute()
    {
        return ucfirst($this->status);
    }
}