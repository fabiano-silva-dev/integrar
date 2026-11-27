<?php

namespace App\Models\Nfse;

class DiagnosticoAcesso extends RegistroNfse
{
    protected $table = 'nfse_diagnosticos_acesso';
    protected $fillable = ['acesso_id', 'certificado_id', 'usuario_id', 'ambiente', 'config_hash', 'chave_hash', 'status'];
    protected $hidden = ['config_hash', 'chave_hash'];
}
