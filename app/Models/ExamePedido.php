<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ExamePedido extends Pivot
{
    protected $table 		= 'exame_pedidos';
    public $incrementing 	= true;
	public $timestamps 		= true;

    public function agendamento()
    {
        return $this->belongsTo(ClinicaBeneficiario::class);
    }

}