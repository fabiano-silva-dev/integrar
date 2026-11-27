<?php

namespace App\Models\Nfse;

class RecorrenciaPausa extends RegistroNfse
{
    protected $table = 'nfse_recorrencia_pausas';
    protected $fillable = ['recorrencia_id', 'competencia', 'pausada', 'usuario_id'];
    protected $casts = ['competencia' => 'date', 'pausada' => 'boolean'];
}
