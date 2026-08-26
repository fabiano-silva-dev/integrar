<?php

namespace App\Models\Documentos;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;

class ConfiguracaoIaDocumento extends Model
{
    use BelongsToOperadora;

    protected $table = 'configuracoes_ia_documentos';

    protected $fillable = [
        'empresa_operadora_id',
        'gemini_api_key',
        'groq_api_key',
        'llama_cloud_api_key',
        'configurado_em',
    ];

    protected $hidden = [
        'gemini_api_key',
        'groq_api_key',
        'llama_cloud_api_key',
    ];

    protected function casts(): array
    {
        return [
            'gemini_api_key' => 'encrypted',
            'groq_api_key' => 'encrypted',
            'llama_cloud_api_key' => 'encrypted',
            'configurado_em' => 'datetime',
        ];
    }

    public function temGemini(): bool
    {
        return $this->chavePreenchida($this->gemini_api_key);
    }

    public function temGroq(): bool
    {
        return $this->chavePreenchida($this->groq_api_key);
    }

    public function temLlamaParse(): bool
    {
        return $this->chavePreenchida($this->llama_cloud_api_key);
    }

    public static function daOperadora(?int $operadoraId = null): ?self
    {
        $query = $operadoraId === null
            ? static::query()
            : static::withoutGlobalScope('operadora')->where('empresa_operadora_id', $operadoraId);

        return $query->first();
    }

    private function chavePreenchida(mixed $valor): bool
    {
        return is_string($valor) && trim($valor) !== '';
    }
}
