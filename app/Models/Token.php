<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    use HasFactory;

    protected $table = 'cancelamento_tokens';

    // ajuste conforme seus campos reais
    protected $fillable = ['token', 'user_id'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // adiciona os campos humanos ao JSON
    protected $appends = ['criado', 'usado', 'usuario'];
    /** Relacionamento opcional com users */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /** Campo virtual: criado (humano) */
    public function getCriadoAttribute(): ?string
    {
        $locale = app()->getLocale() ?: 'pt_BR';
        return $this->created_at?->locale($locale)->diffForHumans();
    }

    /** Campo virtual: usado (humano) */
    public function getUsadoAttribute(): ?string
    {
        $locale = app()->getLocale() ?: 'pt_BR';
        return $this->updated_at?->locale($locale)->diffForHumans();
    }

    /** Campo virtual: snapshot do usuário relacionado (id, nome, email) */
    public function getUsuarioAttribute(): ?array
    {
        // carrega sob demanda, só se existir user_id
        if (!$this->relationLoaded('user') && $this->user_id) {
            $this->load(['user:id,name,email']);
        }

        return $this->user
            ? [
                'id'    => $this->user->id,
                'nome'  => $this->user->name,
                'email' => $this->user->email,
            ]
            : null;
    }
}

