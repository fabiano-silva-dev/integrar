<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOperadora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoFiscal extends Model
{
    use BelongsToOperadora;

    protected $table = 'documentos_fiscais';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'portal_integracao_id',
        'portal_recurso_id',
        'automacao_execucao_id',
        'automacao_artefato_id',
        'tipo_documento',
        'chave_acesso',
        'identificador_externo',
        'numero',
        'serie',
        'modelo',
        'data_emissao',
        'data_entrada_saida',
        'competencia',
        'cnpj_emitente',
        'nome_emitente',
        'ie_emitente',
        'uf_emitente',
        'cnpj_destinatario',
        'nome_destinatario',
        'ie_destinatario',
        'uf_destinatario',
        'valor_total',
        'valor_bc_icms',
        'valor_icms',
        'valor_bc_icms_st',
        'valor_icms_st',
        'cfop',
        'situacao',
        'entrada_saida',
        'cancelado_em',
        'dados_complementares',
        'hash_registro',
        'origem',
        'xml_storage_path',
        'xml_baixado_em',
        'xml_fonte',
        'xml_erro',
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_entrada_saida' => 'date',
        'cancelado_em' => 'datetime',
        'xml_baixado_em' => 'datetime',
        'valor_total' => 'decimal:2',
        'valor_bc_icms' => 'decimal:2',
        'valor_icms' => 'decimal:2',
        'valor_bc_icms_st' => 'decimal:2',
        'valor_icms_st' => 'decimal:2',
        'dados_complementares' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function execucao(): BelongsTo
    {
        return $this->belongsTo(AutomacaoExecucao::class, 'automacao_execucao_id');
    }

    public function temXmlPersistido(): bool
    {
        $path = trim((string) $this->xml_storage_path);

        return $path !== '' && \Illuminate\Support\Facades\Storage::exists($path);
    }
}
