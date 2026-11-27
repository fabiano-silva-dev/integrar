<?php

namespace App\Models\Nfse;

class Lote extends RegistroNfse
{
    protected $table = 'nfse_lotes';
    protected $fillable = ['competencia', 'status', 'quantidade', 'valor_total', 'snapshot', 'justificativa_alertas', 'usuario_id', 'desfeito_por', 'desfeito_em'];
    protected $casts = ['competencia' => 'date', 'valor_total' => 'decimal:2', 'snapshot' => 'array', 'desfeito_em' => 'datetime'];
}
