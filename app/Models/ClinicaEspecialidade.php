<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ClinicaEspecialidade extends Pivot
{
    protected $table 		= 'clinica_especialidade';
    public $incrementing 	= true;
	public $timestamps 		= true;

    public function clinica()
    {
        return $this->belongsTo(Clinica::class);
    }

    public function especialidade()
    {
        return $this->belongsTo(Especialidade::class);
    }
	
}