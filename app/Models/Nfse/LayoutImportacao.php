<?php

namespace App\Models\Nfse;

class LayoutImportacao extends RegistroNfse
{
    protected $table = 'nfse_layouts_importacao';
    protected $fillable = ['nome', 'versao', 'assinatura', 'configuracao'];
    protected $casts = ['versao' => 'integer', 'configuracao' => 'array'];
}
