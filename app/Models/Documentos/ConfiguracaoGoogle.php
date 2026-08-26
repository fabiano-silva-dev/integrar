<?php

namespace App\Models\Documentos;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;

class ConfiguracaoGoogle extends Model
{
    use BelongsToOperadora;

    protected $table = 'configuracoes_google';

    protected $fillable = [
        'empresa_operadora_id',
        'client_id',
        'client_secret',
        'configurado_em',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'configurado_em' => 'datetime',
        ];
    }

    public function pronta(): bool
    {
        return is_string($this->client_id) && $this->client_id !== ''
            && is_string($this->client_secret) && $this->client_secret !== '';
    }

    public static function daOperadora(?int $operadoraId = null): ?self
    {
        $query = $operadoraId === null
            ? static::query()
            : static::withoutGlobalScope('operadora')->where('empresa_operadora_id', $operadoraId);

        return $query->first();
    }
}
