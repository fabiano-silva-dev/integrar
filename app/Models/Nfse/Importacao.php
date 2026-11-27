<?php

namespace App\Models\Nfse;

class Importacao extends RegistroNfse
{
    protected $table = 'nfse_importacoes';
    protected $fillable = ['layout_id', 'usuario_id', 'competencia', 'hash_arquivo', 'arquivo_original', 'storage_path', 'relatorio', 'quantidade_gerada', 'quantidade_pendente'];
    protected $casts = ['competencia' => 'date', 'relatorio' => 'array'];
}
