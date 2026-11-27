<?php

namespace App\Models\Nfse;

class TransmissaoRestrita extends RegistroNfse
{
    protected $table = 'nfse_transmissoes_restritas';
    protected $fillable = ['dps_local_id', 'nota_id', 'aprovado_por', 'status', 'xml_hash', 'acesso_hash', 'chave_acesso', 'nfse_xml_criptografado', 'nfse_xml_hash', 'envio_iniciado_em', 'ultima_consulta_em', 'fila_homologacao_id', 'fila_status', 'fila_iniciada_em', 'fila_finalizada_em'];
    protected $hidden = ['nfse_xml_criptografado', 'acesso_hash'];
    protected $casts = ['nfse_xml_criptografado' => 'encrypted', 'envio_iniciado_em' => 'datetime', 'ultima_consulta_em' => 'datetime', 'fila_iniciada_em' => 'datetime', 'fila_finalizada_em' => 'datetime'];
}
