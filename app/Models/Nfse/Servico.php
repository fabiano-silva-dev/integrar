<?php

namespace App\Models\Nfse;

class Servico extends RegistroNfse
{
    protected $table = 'nfse_servicos';
    protected $fillable = ['codigo', 'nome', 'descricao', 'codigo_tributacao_nacional', 'codigo_tributacao_municipal', 'nbs', 'valor_padrao', 'ativo'];
    protected $casts = ['ativo' => 'boolean', 'valor_padrao' => 'decimal:2'];
}
