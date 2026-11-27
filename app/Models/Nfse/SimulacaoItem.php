<?php

namespace App\Models\Nfse;

class SimulacaoItem extends RegistroNfse
{
    protected $table = 'nfse_simulacao_itens';
    protected $fillable = ['simulacao_id', 'nota_id', 'status', 'mensagem', 'reconciliado_por', 'reconciliado_em'];
    protected $casts = ['reconciliado_em' => 'datetime'];
}
