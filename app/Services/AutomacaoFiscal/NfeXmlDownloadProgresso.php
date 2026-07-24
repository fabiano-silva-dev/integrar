<?php

namespace App\Services\AutomacaoFiscal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Progresso de download/consulta avulsa (cache + storage temporário).
 */
class NfeXmlDownloadProgresso
{
    public static function chave(string $token): string
    {
        return 'nfe-xml-download:'.$token;
    }

    /**
     * @param  array<string, mixed>  $parametros
     * @return array<string, mixed>
     */
    public static function iniciar(
        string $token,
        int $documentoId,
        ?int $operadoraId,
        ?string $chave = null,
        ?string $tipo = null,
        array $parametros = []
    ): array {
        $payload = [
            'status' => 'running',
            'documento_id' => $documentoId,
            'empresa_operadora_id' => $operadoraId,
            'chave' => $chave,
            'tipo' => $tipo,
            'parametros' => $parametros,
            'logs' => [
                self::linha('info', 'JOB_STARTED', 'Consulta avulsa enfileirada…'),
            ],
            'error' => null,
            'storage_path' => null,
            'nome_arquivo' => null,
            'fonte' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'duracao_ms' => null,
        ];

        Cache::put(self::chave($token), $payload, now()->addHours(2));

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function obter(string $token): ?array
    {
        $data = Cache::get(self::chave($token));

        return is_array($data) ? $data : null;
    }

    public static function adicionarLog(string $token, string $level, string $eventType, string $message): void
    {
        $data = self::obter($token);
        if ($data === null) {
            return;
        }

        $data['logs'][] = self::linha($level, $eventType, $message);
        if (count($data['logs']) > 250) {
            $data['logs'] = array_slice($data['logs'], -250);
        }

        Cache::put(self::chave($token), $data, now()->addHours(2));
    }

    /**
     * Consome evento NDJSON do runner Node.
     *
     * @param  array<string, mixed>  $event
     */
    public static function consumirEventoRunner(string $token, array $event): void
    {
        if (($event['type'] ?? null) !== 'event') {
            return;
        }

        $eventType = (string) ($event['eventType'] ?? 'EVENT');
        if ($eventType === 'TRACE_SAVED') {
            return;
        }

        self::adicionarLog(
            $token,
            (string) ($event['level'] ?? 'info'),
            $eventType,
            (string) ($event['message'] ?? $eventType)
        );
    }

    public static function marcarSucesso(string $token, string $storagePath, string $nomeArquivo, ?string $fonte = null): void
    {
        $data = self::obter($token);
        if ($data === null) {
            return;
        }

        $finished = now();
        $data['status'] = 'succeeded';
        $data['storage_path'] = $storagePath;
        $data['nome_arquivo'] = $nomeArquivo;
        $data['fonte'] = $fonte;
        $data['error'] = null;
        $data['finished_at'] = $finished->toIso8601String();
        $data['duracao_ms'] = self::calcularDuracaoMs($data['started_at'] ?? null, $finished);
        $labelFonte = ExecucaoProgressoPresenter::labelFonteDownload($fonte);
        $data['logs'][] = self::linha(
            'info',
            'RUN_FINISHED',
            'XML disponível para download.'.($labelFonte ? ' Origem: '.$labelFonte : '')
        );

        Cache::put(self::chave($token), $data, now()->addHours(2));
    }

    public static function marcarFalha(string $token, string $mensagem): void
    {
        $data = self::obter($token);
        if ($data === null) {
            return;
        }

        $finished = now();
        $data['status'] = 'failed';
        $data['error'] = $mensagem;
        $data['finished_at'] = $finished->toIso8601String();
        $data['duracao_ms'] = self::calcularDuracaoMs($data['started_at'] ?? null, $finished);
        $data['logs'][] = self::linha('error', 'RUN_FAILED', $mensagem);

        Cache::put(self::chave($token), $data, now()->addHours(2));
    }

    public static function caminhoRelativoXml(string $token): string
    {
        return 'temp/nfe-xml/'.$token.'.xml';
    }

    public static function gravarXml(string $token, string $xml): string
    {
        $relative = self::caminhoRelativoXml($token);
        Storage::disk('local')->put($relative, $xml);

        return $relative;
    }

    private static function calcularDuracaoMs(?string $startedAt, \DateTimeInterface $finished): ?int
    {
        if ($startedAt === null || $startedAt === '') {
            return null;
        }

        try {
            $start = \Carbon\Carbon::parse($startedAt);

            return (int) max(0, $start->diffInMilliseconds($finished));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{at: string, level: string, eventType: string, message: string}
     */
    private static function linha(string $level, string $eventType, string $message): array
    {
        return [
            'at' => now()->format('H:i:s'),
            'level' => $level,
            'eventType' => $eventType,
            'message' => mb_substr($message, 0, 500),
        ];
    }
}
