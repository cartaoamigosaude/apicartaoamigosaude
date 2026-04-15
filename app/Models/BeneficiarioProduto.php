<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\Pivot;
use App\Helpers\Cas;

class BeneficiarioProduto extends Pivot
{
    protected $table 		= 'beneficiario_produto';
    public $incrementing 	= true;
	public $timestamps 		= true;

    public function beneficiario()
    {
        return $this->belongsTo(Beneficiario::class);
    }
	
	public function produto()
    {
        return $this->belongsTo(Produto::class);
    }
	
	
}