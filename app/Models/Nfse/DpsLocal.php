<?php

namespace App\Models\Nfse;

class DpsLocal extends RegistroNfse
{
    protected $table = 'nfse_dps_locais';
    protected $fillable = ['sequencia_id', 'nota_id', 'lote_id', 'certificado_id', 'versao_nota', 'ambiente', 'numero', 'id_dps', 'config_hash', 'xml_hash', 'xml_criptografado', 'status', 'usuario_id'];
    protected $hidden = ['xml_criptografado', 'config_hash'];
    protected $casts = ['xml_criptografado' => 'encrypted', 'ambiente' => 'integer', 'numero' => 'integer', 'versao_nota' => 'integer'];
}
