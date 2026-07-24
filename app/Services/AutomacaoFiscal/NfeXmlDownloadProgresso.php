<?php

namespace App\Services\AutomacaoFiscal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Progresso do download avulso de XML NF-e (modal na listagem).
 */
class NfeXmlDownloadProgresso
{
    public static function chave(string $token): string
    {
        return 'nfe-xml-download:'.$token;
    }

    /**
     * @return array{
     *     status: string,
     *     documento_id: int,
     *     empresa_operadora_id: int|null,
     *     chave: string|null,
     *     logs: list<array{at: string, level: string, eventType: string, message: string}>,
     *     error: string|null,
     *     storage_path: string|null,
     *     nome_arquivo: string|null
     * }
     */
    public static function iniciar(string $token, int $documentoId, ?int $operadoraId, ?string $chave = null): array
    {
        $payload = [
            'status' => 'running',
            'documento_id' => $documentoId,
            'empresa_operadora_id' => $operadoraId,
            'chave' => $chave,
            'logs' => [
                self::linha('info', 'JOB_STARTED', 'Iniciando download do XML no portal da NF-e…'),
            ],
            'error' => null,
            'storage_path' => null,
            'nome_arquivo' => null,
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

    public static function marcarSucesso(string $token, string $storagePath, string $nomeArquivo): void
    {
        $data = self::obter($token);
        if ($data === null) {
            return;
        }

        $data['status'] = 'succeeded';
        $data['storage_path'] = $storagePath;
        $data['nome_arquivo'] = $nomeArquivo;
        $data['error'] = null;
        $data['logs'][] = self::linha('info', 'RUN_FINISHED', 'XML disponível para download.');

        Cache::put(self::chave($token), $data, now()->addHours(2));
    }

    public static function marcarFalha(string $token, string $mensagem): void
    {
        $data = self::obter($token);
        if ($data === null) {
            return;
        }

        $data['status'] = 'failed';
        $data['error'] = $mensagem;
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
