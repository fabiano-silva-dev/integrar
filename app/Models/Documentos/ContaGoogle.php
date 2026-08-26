<?php

namespace App\Models\Documentos;

use App\Enums\Documentos\StatusContaGoogle;
use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;

class ContaGoogle extends Model
{
    use BelongsToOperadora;

    protected $table = 'contas_google';

    protected $fillable = [
        'empresa_operadora_id',
        'google_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'scopes',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusContaGoogle::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function conectada(): bool
    {
        return $this->status === StatusContaGoogle::Conectado
            && is_string($this->refresh_token)
            && $this->refresh_token !== '';
    }

    public static function daOperadora(?int $operadoraId = null): ?self
    {
        $query = $operadoraId === null
            ? static::query()
            : static::withoutGlobalScope('operadora')->where('empresa_operadora_id', $operadoraId);

        return $query->first();
    }
}
