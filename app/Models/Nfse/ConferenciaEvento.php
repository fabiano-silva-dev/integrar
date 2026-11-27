<?php

namespace App\Models\Nfse;

class ConferenciaEvento extends RegistroNfse
{
    protected $table = 'nfse_conferencia_eventos';
    protected $fillable = ['nota_id', 'usuario_id', 'acao', 'antes', 'depois'];
    protected $casts = ['antes' => 'array', 'depois' => 'array'];
}
