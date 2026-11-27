<?php

namespace App\Models\Nfse;

class Recorrencia extends RegistroNfse
{
    protected $table = 'nfse_recorrencias';
    protected $fillable = ['tomador_id', 'servico_id', 'referencia', 'inicio', 'fim', 'dia', 'usuario_id'];
    protected $casts = ['inicio' => 'date', 'fim' => 'date', 'dia' => 'integer'];
}
