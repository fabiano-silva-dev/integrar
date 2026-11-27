<?php

namespace App\Models\Nfse;

class TransmissaoRestritaEvento extends RegistroNfse
{
    protected $table = 'nfse_transmissoes_restritas_eventos';
    protected $fillable = ['transmissao_id', 'usuario_id', 'acao', 'resultado', 'http', 'codigos'];
    protected $casts = ['codigos' => 'array'];
}
