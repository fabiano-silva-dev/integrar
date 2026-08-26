<?php

namespace Tests\Unit;

use App\Models\Empresa;
use App\Services\Documentos\NomePastaDriveEmpresa;
use Tests\TestCase;

class NomePastaDriveEmpresaTest extends TestCase
{
    public function test_monta_codigo_e_razao_sem_ltda(): void
    {
        $empresa = new Empresa([
            'codigo_sistema' => '668',
            'razao_social' => 'CARLOS MATRIZ RJ LTDA',
        ]);

        $nome = (new NomePastaDriveEmpresa)->sugerir($empresa);

        $this->assertSame('668 - CARLOS MATRIZ RJ', $nome);
    }

    public function test_remove_cia_ltda(): void
    {
        $empresa = new Empresa([
            'codigo_sistema' => '120',
            'razao_social' => 'JAIME J. BINOTTO & CIA. LTDA.',
        ]);

        $nome = (new NomePastaDriveEmpresa)->sugerir($empresa);

        $this->assertSame('120 - JAIME J. BINOTTO', $nome);
    }

    public function test_usa_nome_salvo_nas_configuracoes(): void
    {
        $empresa = new Empresa([
            'codigo_sistema' => '668',
            'razao_social' => 'CARLOS MATRIZ RJ LTDA',
            'pasta_drive_nome' => '668 - CARLOS MATRIZ RJ',
        ]);

        $this->assertSame('668 - CARLOS MATRIZ RJ', (new NomePastaDriveEmpresa)->sugerir($empresa));
    }
}
