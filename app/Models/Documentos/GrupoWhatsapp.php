<?php

namespace App\Models\Documentos;

use App\Models\Concerns\BelongsToOperadora;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrupoWhatsapp extends Model
{
    use BelongsToOperadora;

    protected $table = 'grupos_whatsapp';

    protected $fillable = [
        'empresa_operadora_id',
        'conexao_whatsapp_id',
        'empresa_id',
        'jid',
        'nome',
        'monitorar',
    ];

    protected function casts(): array
    {
        return [
            'monitorar' => 'boolean',
        ];
    }

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(ConexaoWhatsapp::class, 'conexao_whatsapp_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function podeMonitorar(): bool
    {
        return $this->monitorar && $this->empresa_id !== null;
    }
}
