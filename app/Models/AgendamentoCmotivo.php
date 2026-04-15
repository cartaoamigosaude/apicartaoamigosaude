<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AgendamentoCmotivo extends Model
{

    protected $table = 'agendamento_cmotivos';

    public function agendamentos()
    {
        return $this->hasMany(ClinicaBeneficiario::class);
    }

}
