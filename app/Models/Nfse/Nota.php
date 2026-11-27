<?php

namespace App\Models\Nfse;

class Nota extends RegistroNfse
{
    protected $table = 'nfse_notas';
    protected $fillable = ['tomador_id', 'servico_id', 'usuario_id', 'intencao', 'competencia', 'descricao', 'valor', 'status', 'snapshot'];
    protected $casts = ['competencia' => 'date', 'valor' => 'decimal:2', 'snapshot' => 'array'];
}
