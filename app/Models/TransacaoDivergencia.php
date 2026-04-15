<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransacaoDivergencia extends Model
{
    use HasFactory;

    /**
     * Nome da tabela no banco de dados
     */
    protected $table = 'transacoes_divergencias';

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
        'transacao_data_id',
        'parcela_id',
        'galaxPayId',
        'transacao',
        'situacao',
    ];

    /**
     * Os atributos que devem ser convertidos para tipos nativos
     */
    protected $casts = [
        'id' => 'integer',
        'transacao_data_id' => 'integer',
        'parcela_id' => 'integer',
        'galaxPayId' => 'integer',
        'transacao' => 'array', // Considerando que é JSON
        'situacao' => 'string',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * Relacionamento: Uma divergência pertence a uma transação de data
     */
    public function transacaoData()
    {
        return $this->belongsTo(TransacaoData::class, 'transacao_data_id');
    }

    /**
     * Scope para buscar por situação
     */
    public function scopeComSituacao($query, $situacao)
    {
        return $query->where('situacao', $situacao);
    }

    /**
     * Scope para buscar por parcela
     */
    public function scopePorParcela($query, $parcelaId)
    {
        return $query->where('parcela_id', $parcelaId);
    }

    /**
     * Scope para buscar por GalaxPay ID
     */
    public function scopePorGalaxPayId($query, $galaxPayId)
    {
        return $query->where('galaxPayId', $galaxPayId);
    }

    /**
     * Accessor para situação formatada
     */
    public function getSituacaoFormatadaAttribute()
    {
        return ucfirst($this->situacao);
    }

    /**
     * Accessor para verificar se tem transação JSON
     */
    public function getTemTransacaoAttribute()
    {
        return !empty($this->transacao);
    }
}