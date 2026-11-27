<?php

namespace App\Services\Nfse;

use App\Models\Nfse\DiagnosticoAcesso;
use App\Models\Nfse\ValidacaoPortal;

/** Somente metadados técnicos enumerados. Nunca aceita texto livre do portal ou de exceções. */
class LogTesteAcessoNfse
{
    public const ETAPAS = ['fila', 'validar_configuracao', 'preparar_credencial', 'iniciar_runner', 'entrada_runner', 'iniciar_navegador',
        'abrir_portal', 'autenticar', 'confirmar_identidade', 'selecionar_representacao', 'concluido', 'validar_retorno',
        'consultar_sefin', 'decodificar_resposta', 'validar_identidade_xml', 'revalidar_configuracao', 'worker_interrompido'];

    public const CODIGOS = ['AGUARDANDO_WORKER', 'CONFIG_INVALIDA', 'CONFIG_ALTERADA', 'CREDENCIAL_INDISPONIVEL', 'RUNNER_AUSENTE',
        'RUNNER_FALHOU', 'RUNNER_TIMEOUT', 'RUNNER_RESPOSTA_INVALIDA', 'ERRO_TECNICO', 'WORKER_INTERROMPIDO',
        'HTTP_SEFIN', 'CURL_SEFIN', 'RESPOSTA_INVALIDA', 'XML_DIVERGENTE', 'CONSULTA_CONFIRMADA'];

    public static function filtrar(array $dados): array
    {
        $r = [];
        if (in_array($dados['etapa'] ?? null, self::ETAPAS, true)) {
            $r['etapa'] = $dados['etapa'];
        }
        if (in_array($dados['codigo'] ?? null, [...self::CODIGOS, ...PortalWebNfseRunner::CODIGOS], true)) {
            $r['codigo'] = $dados['codigo'];
        }
        foreach (['duracao_ms' => [0, 600000], 'http' => [100, 599], 'curl' => [1, 999], 'exit_code' => [0, 255]] as $campo => [$min, $max]) {
            if (is_int($dados[$campo] ?? null) && $dados[$campo] >= $min && $dados[$campo] <= $max) {
                $r[$campo] = $dados[$campo];
            }
        }
        foreach (['identidade_confirmada', 'representacao_confirmada'] as $campo) {
            if (is_bool($dados[$campo] ?? null)) {
                $r[$campo] = $dados[$campo];
            }
        }
        return $r;
    }

    public static function orientacao(array $dados): ?string
    {
        $dados = self::filtrar($dados);
        return match ($dados['codigo'] ?? '') {
            'AGUARDANDO_WORKER' => 'O teste foi solicitado e aguarda o serviço de filas.',
            'RUNNER_AUSENTE' => 'O componente de automação não foi compilado ou instalado no servidor.',
            'RUNNER_TIMEOUT' => 'A automação excedeu o tempo limite.',
            'RUNNER_FALHOU', 'RUNNER_RESPOSTA_INVALIDA' => 'Verifique a instalação e a execução do componente de automação.',
            'CREDENCIAL_INDISPONIVEL' => 'Não foi possível preparar a credencial armazenada. Confira o cadastro.',
            'CONFIG_INVALIDA', 'CONFIG_ALTERADA' => 'Confira a configuração e as permissões antes de repetir.',
            'WORKER_INTERROMPIDO' => 'O processamento não terminou. Confira o serviço de filas.',
            'CURL_SEFIN' => 'Falha de comunicação com a SEFIN. O código cURL ajuda a identificar a causa.',
            'HTTP_SEFIN' => match ($dados['http'] ?? null) {
                401, 403 => 'A consulta não foi autorizada. Confira certificado e permissões; isso não comprova falta de procuração.',
                404 => 'O documento não foi encontrado no ambiente consultado.',
                default => 'A SEFIN retornou um status HTTP diferente do esperado.',
            },
            'RESPOSTA_INVALIDA' => 'A resposta da SEFIN não trouxe um XML válido para conferência.',
            'UNAVAILABLE' => 'A automação não conseguiu concluir esta etapa. Encaminhe o relatório ao suporte.',
            default => null,
        };
    }

    public static function json(array $dados): string
    {
        return json_encode(self::filtrar($dados), JSON_THROW_ON_ERROR);
    }

    public static function duracao(int $inicio): int
    {
        return (int) round((hrtime(true) - $inicio) / 1000000);
    }

    public static function relatorio(ValidacaoPortal|DiagnosticoAcesso $r): array
    {
        $portal = $r instanceof ValidacaoPortal;
        $dados = self::filtrar($r->diagnostico_tecnico ?? []);
        return [
            'tipo' => $portal ? 'teste_emissor_web' : 'consulta_api_nacional',
            'teste_id' => $r->id,
            'empresa_id' => $r->empresa_id,
            'escritorio_id' => $r->empresa_operadora_id,
            'usuario_id' => $r->usuario_id,
            'configuracao_id' => $r->acesso_id,
            'modalidade' => $portal ? $r->modo : null,
            'ambiente' => $portal ? 'emissor_web' : $r->ambiente,
            'certificado_id' => $portal ? null : $r->certificado_id,
            'solicitado_em' => $r->created_at?->toIso8601String(),
            'concluido_em' => $portal ? $r->concluida_em?->toIso8601String() : ($r->status !== 'iniciada' ? $r->updated_at?->toIso8601String() : null),
            'status' => $r->status,
            'codigo_resultado' => $portal && in_array($r->codigo, PortalWebNfseRunner::CODIGOS, true) ? $r->codigo : null,
            'mensagem' => $r->mensagem(),
            'diagnostico' => $dados ?: null,
            'orientacao' => self::orientacao($dados),
            'observacao' => $dados ? 'Relatório técnico sem credenciais, documentos, XML, HTML, cookies ou chaves de acesso.' : 'Teste anterior à coleta de detalhes técnicos ou sem dados registrados.',
        ];
    }
}
