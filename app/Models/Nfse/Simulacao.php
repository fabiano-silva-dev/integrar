<?php

namespace App\Models\Nfse;

class Simulacao extends RegistroNfse
{
    protected $table = 'nfse_simulacoes';
    protected $fillable = ['lote_id', 'cenario', 'status', 'snapshot', 'usuario_id', 'concluida_em'];
    protected $casts = ['snapshot' => 'array', 'concluida_em' => 'datetime'];
}
