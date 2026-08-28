<?php

namespace App\Services\Documentos;

class IdentificadorExtratoPdfService
{
    /**
     * Frases que precisam aparecer juntas (AND) no texto do PDF.
     *
     * @var array<string, list<string>>
     */
    private const LAYOUTS = [
        'banrisul' => ['banrisul', 'movimentos da conta corrente'],
        'sicoob' => ['sicoob', 'saldo anterior'],
        'sicredi' => ['sicredi', 'extrato'],
        'caixa' => ['caixa economica', 'saldo anterior'],
        'santander' => ['santander', 'extrato'],
        'itau' => ['itau', 'saldo em conta corrente'],
        'bradesco' => ['bradesco', 'saldo anterior'],
        'cresol' => ['cresol', 'lancamentos futuros'],
        'banco_brasil' => ['banco do brasil', 'extrato'],
        'nubank' => ['nubank'],
        'grafeno' => ['grafeno'],
        'infinitepay' => ['infinitepay'],
        'dominio_conta_digital' => ['conta digital', 'dominio'],
        'cora' => ['cora scfi'],
    ];

    /**
     * @return array{layout: string, metadados: array<string, mixed>}|null
     */
    public function identificar(string $texto): ?array
    {
        $normalizado = $this->normalizar($texto);

        foreach (self::LAYOUTS as $layout => $frases) {
            $bateu = true;

            foreach ($frases as $frase) {
                if (! str_contains($normalizado, $frase)) {
                    $bateu = false;
                    break;
                }
            }

            if ($bateu) {
                return [
                    'layout' => $layout,
                    'metadados' => [
                        'origem' => 'pdf_extrato',
                        'layout_banco' => $layout,
                    ],
                ];
            }
        }

        if (
            str_contains($normalizado, 'data efetiva')
            && (str_contains($normalizado, 'caixa') || str_contains($normalizado, 'cef'))
        ) {
            return [
                'layout' => 'caixa',
                'metadados' => [
                    'origem' => 'pdf_extrato',
                    'layout_banco' => 'caixa',
                ],
            ];
        }

        return null;
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto);
        $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return is_string($semAcento) ? $semAcento : $texto;
    }
}
