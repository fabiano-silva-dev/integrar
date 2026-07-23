<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificadoDigital extends Model
{
    use BelongsToOperadora;

    protected $table = 'certificados_digitais';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'nome',
        'tipo',
        'arquivo_path',
        'senha_criptografada',
        'fingerprint',
        'serial',
        'titular',
        'documento_titular',
        'emissor',
        'valido_de',
        'valido_ate',
        'ativo',
        'validado_em',
        'status_validacao',
    ];

    protected $hidden = [
        'senha_criptografada',
    ];

    protected $casts = [
        'senha_criptografada' => 'encrypted',
        'valido_de' => 'datetime',
        'valido_ate' => 'datetime',
        'validado_em' => 'datetime',
        'ativo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function empresaIntegracoes(): HasMany
    {
        return $this->hasMany(EmpresaIntegracao::class, 'certificado_digital_id');
    }

    /** Certificado do escritório/contador (sem vínculo a empresa cliente). */
    public function ehDoEscritorio(): bool
    {
        return $this->empresa_id === null;
    }

    /**
     * Papel na tela pós-login do e-CAC RS (escolha e-CNPJ).
     * Escritório → CNPJ Empresa Contábil; cliente → CPF do Responsável Legal.
     *
     * @return 'empresa-contabil'|'responsavel-legal'
     */
    public function loginPapelEcac(): string
    {
        return $this->ehDoEscritorio() ? 'empresa-contabil' : 'responsavel-legal';
    }
}
