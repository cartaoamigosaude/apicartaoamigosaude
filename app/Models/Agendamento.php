<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agendamento extends Model
{
    use HasFactory;

    protected $table = 'agendamentos';

    public function clinica()
    {
        return $this->belongsTo(Clinica::class);
    }

    public function especialidade()
    {
        return $this->belongsTo(Especialidade::class);
    }

    public function beneficiario()
    {
        return $this->belongsTo(Beneficiario::class);
    }
    
}
