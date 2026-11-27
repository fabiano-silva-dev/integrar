<?php

namespace App\Models\Nfse;

class AcessoEmissao extends RegistroNfse
{
    protected $table = 'nfse_acessos_emissao';
    protected $fillable = ['modo', 'canal', 'certificado_digital_id', 'usuario_criptografado', 'senha_criptografada', 'documento_prestador', 'procuracao_ate', 'status_validacao', 'usuario_id'];
    protected $hidden = ['usuario_criptografado', 'senha_criptografada'];
    protected $casts = ['usuario_criptografado' => 'encrypted', 'senha_criptografada' => 'encrypted', 'procuracao_ate' => 'date'];
}
