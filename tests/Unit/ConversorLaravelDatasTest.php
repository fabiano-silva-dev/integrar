<?php

namespace Tests\Unit;

use Tests\TestCase;

class ConversorLaravelDatasTest extends TestCase
{
    public function test_converte_datas_texto_com_dayfirst_brasileiro(): void
    {
        $script = base_path('scripts/conversor_laravel.py');
        $this->assertFileExists($script);

        $entrada = sys_get_temp_dir() . '/integrar_datas_br_' . uniqid() . '.csv';
        $saida = sys_get_temp_dir() . '/integrar_datas_br_out_' . uniqid() . '.csv';

        file_put_contents($entrada, implode("\n", [
            'Data;Historico',
            '09/05/2025;maio dia 9',
            '11/05/2025;maio dia 11',
            '16/05/2025;maio dia 16',
            '04/06/2025;junho dia 4',
            '06/06/2025;junho dia 6',
        ]));

        $comando = sprintf(
            'python3 %s %s %s %s 2>/dev/null',
            escapeshellarg($script),
            escapeshellarg($entrada),
            escapeshellarg($saida),
            escapeshellarg(';')
        );

        $json = shell_exec($comando);
        $this->assertNotEmpty($json, 'Conversor Python não retornou JSON');

        $dados = json_decode((string) $json, true);
        $this->assertTrue($dados['sucesso'] ?? false, $dados['mensagem'] ?? 'falha');
        $this->assertFileExists($saida);

        $linhas = array_map(
            fn ($linha) => str_getcsv($linha, ';'),
            file($saida, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
        $this->assertSame(['Data', 'Historico'], $linhas[0]);
        $this->assertSame('09/05/2025', $linhas[1][0]);
        $this->assertSame('11/05/2025', $linhas[2][0]);
        $this->assertSame('16/05/2025', $linhas[3][0]);
        $this->assertSame('04/06/2025', $linhas[4][0]);
        $this->assertSame('06/06/2025', $linhas[5][0]);

        @unlink($entrada);
        @unlink($saida);
    }
}
