<?php

namespace App\Models\Nfse;

class PerfilTributario extends RegistroNfse
{
    protected $table = 'nfse_perfis_tributarios';

    protected $fillable = ['servico_id', 'usuario_id', 'escopo', 'vigencia', 'dados'];

    protected $casts = ['vigencia' => 'date', 'dados' => 'array'];
}
