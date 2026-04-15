<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SugestaoData extends Pivot
{
    protected $table 		= 'sugestao_datas';
    public $incrementing 	= true;
	public $timestamps 		= true;

    public function agendamento()
    {
        return $this->belongsTo(ClinicaBeneficiario::class);
    }

}