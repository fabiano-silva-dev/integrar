<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Importacao extends Model
{
    protected $table = 'importacoes';
    
    protected $fillable = [
        'nome_arquivo',
        'nome',
        'tipo',
        'total_registros',
        'registros_processados',
        'status',
        'erro_mensagem',
        'usuario',
        'codigo_empresa',
        'cnpj_empresa',
        'empresa_id',
        'layout_avancado',
        'conta_banco',
        'user_id',
        'data_inicial',
        'data_final'
    ];

    protected $casts = [
        'total_registros' => 'integer',
        'registros_processados' => 'integer',
    ];

    public function lancamentos()
    {
        return $this->hasMany(Lancamento::class);
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
