<?php

namespace App\Services\AutomacaoFiscal\Logs;

class LogSanitizer
{
    private const SENSITIVE_KEY_PATTERN = '/password|senha|secret|token|cookie|authorization|passwd|pfx|p12|private[_-]?key|certificado/i';

    /**
     * @param  array<string, mixed>|null  $contexto
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $contexto): ?array
    {
        if ($contexto === null) {
            return null;
        }

        return self::sanitizeValue($contexto);
    }

    public static function sanitizeMessage(string $mensagem): string
    {
        $mensagem = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [REDACTED]', $mensagem) ?? $mensagem;
        $mensagem = preg_replace('/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s', '[REDACTED_PEM]', $mensagem) ?? $mensagem;

        return $mensagem;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                    $sanitized[$key] = '[REDACTED]';
                    continue;
                }

                $sanitized[$key] = self::sanitizeValue($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return self::sanitizeMessage($value);
        }

        return $value;
    }
}
