<?php

namespace App\Models\Nfse;

class FilaHomologacao extends RegistroNfse
{
    protected $table = 'nfse_filas_homologacao';

    protected $fillable = ['usuario_id', 'competencia', 'quantidade'];

    protected $casts = ['competencia' => 'date', 'quantidade' => 'integer'];
}
