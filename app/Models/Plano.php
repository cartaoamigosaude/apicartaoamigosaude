<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plano extends Model
{
    use HasFactory;

    protected $table = 'planos';

    protected $fillable = [
        'nome',
        'preco',
        'taxa_ativacao',
        'periodicidade_id',
        'parcelas',
        'qtde_beneficiarios',
        'formapagamento',
        'ativo'
    ];

    public function periodicidade()
    {
        return $this->belongsTo(Periodicidade::class);
    }

    public function contratos()
    {
        return $this->hasMany(Contrato::class);
    }

    public function produtos()
    {
        return $this->belongsToMany(\App\Models\Produto::class)->using(PlanoProduto::class);
    }
    
	public function vendedores()
    {
        return $this->belongsToMany(\App\Models\Vendedor::class)->using(PlanoVendedor::class);
    }
	
    public function getAtivoLabelAttribute()
    {
        return $this->ativo ? 'Sim' : 'Não';
    }

    public static function casPlanosPermitemDependentes()
    {
        $valor = config('services.cas.dependent_plan_ids', '15');

        if (is_array($valor))
        {
            return array_map('intval', $valor);
        }

        $ids = array_filter(array_map('trim', explode(',', $valor)));

        return array_map('intval', $ids);
    }

    public static function permiteDependenteCas($plano_id)
    {
        return in_array((int) $plano_id, self::casPlanosPermitemDependentes());
    }

    public static function limiteDependentesCas()
    {
        return max(0, (int) config('services.cas.dependent_limit', 4));
    }

    public static function limiteDependentes($plano)
    {
        if (!isset($plano->id))
        {
            return 0;
        }

        if (self::permiteDependenteCas($plano->id))
        {
            return self::limiteDependentesCas();
        }

        return max(0, ((int) $plano->qtde_beneficiarios) - 1);
    }

    public static function vagasDependentes($plano, $qtde_dependentes)
    {
        return max(0, self::limiteDependentes($plano) - (int) $qtde_dependentes);
    }

    public static function planoIdBeneficiario($beneficiario)
    {
        if (!isset($beneficiario->id))
        {
            return 0;
        }

        if ((isset($beneficiario->contrato->tipo)) and ($beneficiario->contrato->tipo == 'F'))
        {
            return (int) $beneficiario->contrato->plano_id;
        }

        if (($beneficiario->tipo == 'D') and ((int) $beneficiario->plano_id == 0))
        {
            $titular = \App\Models\Beneficiario::find($beneficiario->parent_id);

            if (isset($titular->id))
            {
                return (int) $titular->plano_id;
            }
        }

        return (int) $beneficiario->plano_id;
    }

    public static function dependenteCasTelemedicina($beneficiario)
    {
        if ((!isset($beneficiario->id)) or ($beneficiario->tipo != 'D'))
        {
            return false;
        }

        return self::permiteDependenteCas(self::planoIdBeneficiario($beneficiario));
    }
}
