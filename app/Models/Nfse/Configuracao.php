<?php

namespace App\Models\Nfse;

class Configuracao extends RegistroNfse
{
    protected $table = 'nfse_configuracoes';
    protected $fillable = ['habilitado_preparo', 'regime_tributario', 'serie_dps'];
    protected $casts = ['habilitado_preparo' => 'boolean'];
}
