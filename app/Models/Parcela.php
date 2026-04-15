<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Parcela extends Model
{
   
    protected $table = 'parcelas';

    public function contrato()
    {
        return $this->belongsTo(Contrato::class);
    }

    public function getCriadoemAttribute()
    {
        if (isset($this->attributes['created_at']))
	    {
            return Carbon::parse($this->attributes['created_at'])->diffForHumans();
	    } 
	  
	    return '';
    
	}

    public function getSituacaoAttribute()
    {
		
	    if ((isset($this->attributes['data_pagamento'])) and ($this->attributes['data_pagamento'] !=null))
	    {
            return 'Paga';
	    }

        if ((isset($this->attributes['data_baixa'])) and ($this->attributes['data_baixa'] !=null))
	    {
            return 'Baixada';
	    } 
		  
	    if (isset($this->attributes['data_vencimento']))
	    {
		    if ($this->attributes['data_vencimento'] <= date('Y-m-d'))
		    {
			    return 'Vencida';  
		    }			  
	    }
	  
	    return 'Á vencer';  
    }

    public function getDiasavencerAttribute()
    {
		$diferenca 						= 0;
		
		if (isset($this->attributes['data_vencimento']) && ($this->attributes['data_vencimento'] > date('Y-m-d')))
		{
			$date 						= $this->attributes['data_vencimento'] . " 23:59:59";
			$vencimento 				= Carbon::createFromDate($date);
			$now 						= Carbon::now();
			$diferenca 					= $vencimento->diffInDays($now);
		} 
	  
		return $diferenca;
    
	}


}
