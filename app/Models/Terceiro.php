<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use App\Rules\CnpjValido;
use App\Rules\CpfValido;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Terceiro extends Model
{
    use HasFactory, BelongsToOperadora;

    protected $fillable = [
        'nome',
        'cnpj_cpf',
        'tipo',
        'observacoes',
        'ativo',
        'empresa_id',
        'empresa_operadora_id',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public static function resolverDocumentoDaLinha(callable $get, ?string $nomeEmpresa, ?string $historico): ?string
    {
        foreach (['CNPJ/CPF', 'CNPJ da Empresa', 'CPF/CNPJ'] as $coluna) {
            $valorColuna = trim((string) ($get($coluna) ?? ''));
            if ($valorColuna !== '') {
                $normalizado = self::normalizarDocumento($valorColuna);
                if ($normalizado !== null) {
                    return $normalizado;
                }
            }
        }

        foreach ([$nomeEmpresa, $historico] as $texto) {
            $extraido = self::extrairDocumentoDeTexto($texto);
            if ($extraido !== null) {
                return $extraido;
            }
        }

        return null;
    }

    public static function sincronizarNaImportacao(string $nome, int $empresaId, ?string $cnpjCpf = null): self
    {
        $nome = trim($nome);
        $atributos = [
            'tipo' => 'empresa',
            'ativo' => true,
        ];

        if ($cnpjCpf !== null) {
            $atributos['cnpj_cpf'] = $cnpjCpf;
        }

        $terceiro = self::firstOrCreate(
            [
                'nome' => $nome,
                'empresa_id' => $empresaId,
            ],
            $atributos
        );

        if ($cnpjCpf !== null && blank($terceiro->cnpj_cpf)) {
            $terceiro->update(['cnpj_cpf' => $cnpjCpf]);
            $terceiro->refresh();
        }

        return $terceiro;
    }

    public static function normalizarDocumento(?string $documento): ?string
    {
        if ($documento === null || trim($documento) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $documento);
        if (strlen($digits) === 11 && CpfValido::isValid($digits)) {
            return CpfValido::format($digits);
        }
        if (strlen($digits) === 14 && CnpjValido::isValid($digits)) {
            return CnpjValido::format($digits);
        }

        return null;
    }

    public static function extrairDocumentoDeTexto(?string $texto): ?string
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }

        $texto = trim($texto);

        if (preg_match('/\d{2}\.\d{3}\.\d{3}[\/ ]\d{4}-\d{2}/', $texto, $match)) {
            $normalizado = self::normalizarDocumento($match[0]);
            if ($normalizado !== null) {
                return $normalizado;
            }
        }

        if (preg_match('/(?:\d{3}|\*{3})\.\d{3}\.\d{3}-\d{2,3}/', $texto, $match)) {
            $normalizado = self::normalizarDocumento($match[0]);
            if ($normalizado !== null) {
                return $normalizado;
            }
        }

        if (preg_match_all('/\b(\d{14})\b/', $texto, $matches)) {
            foreach ($matches[1] as $candidato) {
                $normalizado = self::normalizarDocumento($candidato);
                if ($normalizado !== null) {
                    return $normalizado;
                }
            }
        }

        if (preg_match_all('/\b(\d{11})\b/', $texto, $matches)) {
            foreach ($matches[1] as $candidato) {
                $normalizado = self::normalizarDocumento($candidato);
                if ($normalizado !== null) {
                    return $normalizado;
                }
            }
        }

        return null;
    }
}
