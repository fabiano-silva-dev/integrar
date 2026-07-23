<?php

namespace App\Services\AutomacaoFiscal\Logs;

use App\Models\AutomacaoExecucao;
use App\Models\AutomacaoExecucaoLog;

class AutomacaoLogService
{
    /**
     * @param  array<string, mixed>|null  $contexto
     */
    public function registrar(
        AutomacaoExecucao $execucao,
        string $nivel,
        string $mensagem,
        ?string $etapa = null,
        ?array $contexto = null
    ): AutomacaoExecucaoLog {
        return AutomacaoExecucaoLog::create([
            'empresa_operadora_id' => $execucao->empresa_operadora_id,
            'automacao_execucao_id' => $execucao->id,
            'nivel' => $nivel,
            'etapa' => $etapa,
            'mensagem' => LogSanitizer::sanitizeMessage($mensagem),
            'contexto_sanitizado' => LogSanitizer::sanitize($contexto),
            'ocorrido_em' => now(),
        ]);
    }
}
