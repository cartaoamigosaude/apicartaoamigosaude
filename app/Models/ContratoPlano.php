<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ContratoPlano extends Pivot
{
    protected $table 		= 'contrato_planos';
    public $incrementing 	= true;
	public $timestamps 		= true;

    public function contrato()
    {
        return $this->belongsTo(Contrato::class);
    }

    public function plano()
    {
        return $this->belongsTo(Plano::class);
    }

}