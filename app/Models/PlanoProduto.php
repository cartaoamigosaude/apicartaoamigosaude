<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PlanoProduto extends Pivot
{
    protected $table 		= 'plano_produto';
    public $incrementing 	= true;
	public $timestamps 		= true;

    public function plano()
    {
        return $this->belongsTo(Plano::class);
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class);
    }
	
}