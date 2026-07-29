<?php

namespace Tests\Unit;

use Tests\TestCase;

class ConversorLaravelDatasTest extends TestCase
{
    private function converterCsv(array $linhas, string $delimitador = ';'): array
    {
        $script = base_path('scripts/conversor_laravel.py');
        $this->assertFileExists($script);

        $entrada = sys_get_temp_dir() . '/integrar_datas_' . uniqid() . '.csv';
        $saida = sys_get_temp_dir() . '/integrar_datas_out_' . uniqid() . '.csv';

        file_put_contents($entrada, implode("\n", $linhas));

        $comando = sprintf(
            'python3 %s %s %s %s 2>/dev/null',
            escapeshellarg($script),
            escapeshellarg($entrada),
            escapeshellarg($saida),
            escapeshellarg($delimitador)
        );

        $json = shell_exec($comando);
        $this->assertNotEmpty($json, 'Conversor Python não retornou JSON');

        $dados = json_decode((string) $json, true);
        $this->assertTrue($dados['sucesso'] ?? false, $dados['mensagem'] ?? 'falha');
        $this->assertFileExists($saida);

        $linhasSaida = array_map(
            fn ($linha) => str_getcsv($linha, $delimitador),
            file($saida, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );

        @unlink($entrada);
        @unlink($saida);

        return ['meta' => $dados, 'linhas' => $linhasSaida];
    }

    public function test_detecta_dd_mm_quando_existe_dia_maior_que_12(): void
    {
        $resultado = $this->converterCsv([
            'Data;Historico',
            '09/05/2025;maio dia 9',
            '11/05/2025;maio dia 11',
            '16/05/2025;maio dia 16',
            '04/06/2025;junho dia 4',
            '06/06/2025;junho dia 6',
        ]);

        $this->assertSame('dd/mm', $resultado['meta']['resumo']['formatos_data']['Data'] ?? null);
        $this->assertSame('09/05/2025', $resultado['linhas'][1][0]);
        $this->assertSame('11/05/2025', $resultado['linhas'][2][0]);
        $this->assertSame('16/05/2025', $resultado['linhas'][3][0]);
        $this->assertSame('04/06/2025', $resultado['linhas'][4][0]);
        $this->assertSame('06/06/2025', $resultado['linhas'][5][0]);
    }

    public function test_detecta_mm_dd_quando_existe_dia_na_segunda_parte(): void
    {
        $resultado = $this->converterCsv([
            'Data;Historico',
            '05/09/2025;may 9 us',
            '05/11/2025;may 11 us',
            '05/16/2025;may 16 us',
            '06/04/2025;june 4 us',
        ]);

        $this->assertSame('mm/dd', $resultado['meta']['resumo']['formatos_data']['Data'] ?? null);
        // Saída sempre normalizada em DD/MM brasileiro.
        $this->assertSame('09/05/2025', $resultado['linhas'][1][0]);
        $this->assertSame('11/05/2025', $resultado['linhas'][2][0]);
        $this->assertSame('16/05/2025', $resultado['linhas'][3][0]);
        $this->assertSame('04/06/2025', $resultado['linhas'][4][0]);
    }

    public function test_ambiguo_assume_dd_mm_brasileiro(): void
    {
        $resultado = $this->converterCsv([
            'Data;Historico',
            '01/02/2025;ambiguo',
            '03/04/2025;ambiguo',
            '05/06/2025;ambiguo',
        ]);

        $this->assertSame('dd/mm', $resultado['meta']['resumo']['formatos_data']['Data'] ?? null);
        $this->assertSame('01/02/2025', $resultado['linhas'][1][0]);
        $this->assertSame('03/04/2025', $resultado['linhas'][2][0]);
        $this->assertSame('05/06/2025', $resultado['linhas'][3][0]);
    }
}
