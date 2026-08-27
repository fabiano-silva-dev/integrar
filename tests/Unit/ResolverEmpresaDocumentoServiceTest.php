<?php

namespace Tests\Unit;

use App\Models\Documentos\DocumentoRecebido;
use App\Models\Empresa;
use App\Services\Documentos\ResolverEmpresaDocumentoService;
use Tests\TestCase;

class ResolverEmpresaDocumentoServiceTest extends TestCase
{
    public function test_grupo_com_uma_empresa_usa_essa_empresa(): void
    {
        $unica = new Empresa(['nome' => 'Unica', 'cnpj' => '11.222.333/0001-81']);
        $unica->id = 10;

        $escolhida = (new ResolverEmpresaDocumentoService())->resolver(
            new DocumentoRecebido(['metadados' => []]),
            'sem cnpj',
            [$unica],
        );

        $this->assertSame(10, $escolhida?->id);
    }

    public function test_escolhe_pelo_cnpj_do_xml(): void
    {
        $matriz = new Empresa(['nome' => 'Matriz', 'cnpj' => '11.222.333/0001-81']);
        $matriz->id = 1;
        $filial = new Empresa(['nome' => 'Filial', 'cnpj' => '22.333.444/0001-81']);
        $filial->id = 2;

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc>
  <NFe>
    <infNFe>
      <emit><CNPJ>11222333000181</CNPJ></emit>
      <dest><CNPJ>99888777000166</CNPJ></dest>
    </infNFe>
  </NFe>
</nfeProc>
XML;

        $escolhida = (new ResolverEmpresaDocumentoService())->resolver(
            new DocumentoRecebido(['metadados' => []]),
            $xml,
            [$matriz, $filial],
        );

        $this->assertSame(1, $escolhida?->id);
    }

    public function test_sem_cnpj_compativel_fica_indefinido(): void
    {
        $matriz = new Empresa(['nome' => 'Matriz', 'cnpj' => '11.222.333/0001-81']);
        $matriz->id = 1;
        $filial = new Empresa(['nome' => 'Filial', 'cnpj' => '22.333.444/0001-81']);
        $filial->id = 2;

        $escolhida = (new ResolverEmpresaDocumentoService())->resolver(
            new DocumentoRecebido(['metadados' => []]),
            'comprovante sem identificacao',
            [$matriz, $filial],
        );

        $this->assertNull($escolhida);
    }

    public function test_escolhe_pelo_id_indicado_pela_ia(): void
    {
        $matriz = new Empresa(['nome' => 'Matriz', 'cnpj' => '11.222.333/0001-81']);
        $matriz->id = 1;
        $filial = new Empresa(['nome' => 'Filial', 'cnpj' => '22.333.444/0001-81']);
        $filial->id = 2;

        $escolhida = (new ResolverEmpresaDocumentoService())->resolver(
            new DocumentoRecebido(['metadados' => ['empresa_id' => 2]]),
            'sem cnpj',
            [$matriz, $filial],
        );

        $this->assertSame(2, $escolhida?->id);
    }

    public function test_escolhe_pelo_nome_aproximado_quando_cnpj_nao_bate(): void
    {
        $pileco = new Empresa([
            'nome' => 'POSTO PILECO LTDA',
            'razao_social' => 'POSTO PILECO LTDA',
            'cnpj' => '11.222.333/0001-81',
        ]);
        $pileco->id = 3;
        $sandra = new Empresa([
            'nome' => 'SANDRA QUATRIN',
            'razao_social' => 'SANDRA QUATRIN',
            'cnpj' => '22.333.444/0001-81',
        ]);
        $sandra->id = 112;

        $escolhida = (new ResolverEmpresaDocumentoService())->resolver(
            new DocumentoRecebido(['metadados' => [
                'empresa_cnpj' => '89.889.604/0001-44',
                'empresa_razao_social' => 'POSTO PILECCO LTDA',
            ]]),
            'comprovante sem xml',
            [$pileco, $sandra],
        );

        $this->assertSame(3, $escolhida?->id);
    }

    public function test_escolhe_pelo_cnpj_do_terceiro_quando_e_o_do_grupo(): void
    {
        $pileco = new Empresa([
            'nome' => 'POSTO PILECO LTDA',
            'razao_social' => 'POSTO PILECO LTDA',
            'cnpj' => '89.890.073/0001-00',
        ]);
        $pileco->id = 3;
        $sandra = new Empresa([
            'nome' => 'SANDRA QUATRIN',
            'razao_social' => 'SANDRA QUATRIN',
            'cnpj' => '16.679.526/0001-80',
        ]);
        $sandra->id = 112;

        $escolhida = (new ResolverEmpresaDocumentoService())->resolver(
            new DocumentoRecebido(['metadados' => [
                'empresa_cnpj' => '89.889.604/0001-44',
                'terceiro_cnpj' => '89.890.073/0001-00',
            ]]),
            'sem xml',
            [$pileco, $sandra],
        );

        $this->assertSame(3, $escolhida?->id);
    }
}
