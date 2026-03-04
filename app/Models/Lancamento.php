<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lancamento extends Model
{
    protected $fillable = [
        'data',
        'historico',
        'historico_original',
        'conta_debito',
        'conta_credito',
        'valor',
        'terceiro',
        'usuario',
        'codigo_filial_matriz',
        'nome_empresa',
        'numero_nota',
        'importacao_id',
        'terceiro_id',
        'conta_debito_original',
        'conta_credito_original',
        'conferido',
        'empresa_id',
        'arquivo_origem',
        'linha_arquivo',
        'processado',
        'detalhes_operacao_para_amarracao',
    ];

    protected $casts = [
        'data' => 'date',
        'valor' => 'decimal:2',
        'conferido' => 'boolean',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(Importacao::class);
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Terceiro::class, 'terceiro_id');
    }

    public function getNomeTerceiroAttribute()
    {
        if ($this->terceiro_id) {
            $terceiro = \App\Models\Terceiro::find($this->terceiro_id);
            return $terceiro ? $terceiro->nome : null;
        }
        return null;
    }

    public function alteracoes(): HasMany
    {
        return $this->hasMany(AlteracaoLog::class);
    }

    public function amarracao(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Amarracao::class, 'amarracao_id');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Acessa as tags através da amarração ou detalhes processados
     */
    public function getDetalhesOperacaoAttribute()
    {
        if ($this->amarracao) {
            return $this->amarracao->detalhes_operacao;
        }
        
        // Se não há amarração, retornar os detalhes processados
        if (!empty($this->detalhes_operacao_para_amarracao)) {
            return $this->detalhes_operacao_para_amarracao;
        }
        
        return null;
    }

    /**
     * Retorna as tags como array
     */
    public function getTagsAttribute()
    {
        if ($this->detalhes_operacao) {
            return array_map('trim', explode(',', $this->detalhes_operacao));
        }
        return [];
    }
}
