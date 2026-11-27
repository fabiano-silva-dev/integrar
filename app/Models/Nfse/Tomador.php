<?php

namespace App\Models\Nfse;

class Tomador extends RegistroNfse
{
    protected $table = 'nfse_tomadores';
    protected $fillable = ['documento', 'nome', 'email', 'telefone', 'codigo_externo', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'codigo_municipio_ibge', 'uf', 'inscricao_municipal', 'ativo'];
    protected $casts = ['ativo' => 'boolean'];
}
