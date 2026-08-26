<?php

namespace App\Models\Documentos;

use App\Enums\Documentos\StatusConexaoWhatsapp;
use App\Models\Concerns\BelongsToOperadora;
use App\Models\EmpresasOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConexaoWhatsapp extends Model
{
    use BelongsToOperadora;

    protected $table = 'conexoes_whatsapp';

    protected $fillable = [
        'empresa_operadora_id',
        'status',
        'telefone_exibicao',
        'url_base_evolution',
        'nome_instancia',
        'credenciais',
    ];

    protected $hidden = [
        'credenciais',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusConexaoWhatsapp::class,
            'credenciais' => 'encrypted:array',
        ];
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(GrupoWhatsapp::class, 'conexao_whatsapp_id');
    }

    public function apiKeyEvolution(): ?string
    {
        $credenciais = $this->credenciais;

        if (! is_array($credenciais)) {
            return null;
        }

        $valor = $credenciais['apikey'] ?? $credenciais['api_key'] ?? null;

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    public static function daOperadora(?int $operadoraId = null): ?self
    {
        $query = $operadoraId === null
            ? static::query()
            : static::withoutGlobalScope('operadora')->where('empresa_operadora_id', $operadoraId);

        return $query->first();
    }
}
