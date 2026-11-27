<?php

namespace App\Models\Nfse;

class DpsSequencia extends RegistroNfse
{
    protected $table = 'nfse_dps_sequencias';
    protected $fillable = ['documento_prestador', 'ambiente', 'serie', 'numero_inicial', 'ultimo_numero', 'usuario_id'];
    protected $casts = ['ambiente' => 'integer', 'numero_inicial' => 'integer', 'ultimo_numero' => 'integer'];
}
