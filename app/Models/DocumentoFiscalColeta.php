<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoFiscalColeta extends Model
{
    use BelongsToOperadora;

    protected $table = 'documentos_fiscais_coletas';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'automacao_execucao_id',
        'automacao_artefato_id',
        'origem',
        'nome_arquivo',
        'storage_path',
        'hash_arquivo',
        'quantidade_documentos',
        'quantidade_novos',
        'quantidade_atualizados',
        'quantidade_ignorados',
        'periodo_inicio',
        'periodo_fim',
        'resumo',
    ];

    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fim' => 'date',
        'resumo' => 'array',
        'quantidade_documentos' => 'integer',
        'quantidade_novos' => 'integer',
        'quantidade_atualizados' => 'integer',
        'quantidade_ignorados' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function execucao(): BelongsTo
    {
        return $this->belongsTo(AutomacaoExecucao::class, 'automacao_execucao_id');
    }

    public function portalIntegracao(): ?PortalIntegracao
    {
        return $this->execucao?->portalRecurso?->portal
            ?? $this->execucao?->empresaIntegracao?->portal;
    }

    public function nomePortal(): string
    {
        $portal = $this->portalIntegracao();
        if ($portal) {
            return $portal->nome;
        }

        return match ($this->origem) {
            'ecac_rs_extrato_txt' => 'e-CAC RS (Receita Estadual)',
            'nfse_nacional_extrato_txt' => 'Portal Nacional da NFS-e',
            default => $this->origem ?: '—',
        };
    }

    public function periodoLabel(): string
    {
        if (!$this->periodo_inicio && !$this->periodo_fim) {
            return '—';
        }

        $inicio = $this->periodo_inicio?->format('d/m/Y') ?? '—';
        $fim = $this->periodo_fim?->format('d/m/Y') ?? '—';

        return "{$inicio} – {$fim}";
    }
}
