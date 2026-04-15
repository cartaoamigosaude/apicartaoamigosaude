<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PlanoVendedor extends Pivot
{
    protected $table 		= 'plano_vendedor';
    public $incrementing 	= true;
	public $timestamps 		= true;

    public function plano()
    {
        return $this->belongsTo(Plano::class);
    }

    public function vendedor()
    {
        return $this->belongsTo(Vendedor::class);
    }
	
}