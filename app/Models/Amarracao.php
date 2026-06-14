<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;

class Amarracao extends Model
{
    use BelongsToOperadora;

    protected $table = 'amarracoes';
    protected $guarded = [];
    protected $fillable = [
        'terceiro',
        'detalhes_operacao',
        'conta_debito',
        'conta_credito',
        'codigo_sistema_empresa',
        'empresa_operadora_id',
    ];
    protected $casts = [
        // detalhes_operacao agora é string, não array
    ];
} 