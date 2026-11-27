<?php

namespace App\Models\Nfse;

class RecorrenciaReajuste extends RegistroNfse
{
    protected $table = 'nfse_recorrencia_reajustes';
    protected $fillable = ['recorrencia_id', 'vigencia', 'valor', 'descricao', 'usuario_id'];
    protected $casts = ['vigencia' => 'date', 'valor' => 'decimal:2'];
}
