<?php

namespace App\Models\Nfse;

class PerfilTributario extends RegistroNfse
{
    protected $table = 'nfse_perfis_tributarios';

    protected $fillable = ['classificacao_catalogo_id', 'indop_catalogo_id', 'servico_id', 'usuario_id', 'escopo', 'vigencia', 'dados'];

    protected $casts = ['vigencia' => 'date', 'dados' => 'array'];
}
