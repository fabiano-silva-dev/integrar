<?php

namespace Tests\Unit;

use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Services\Documentos\MapeadorTipoDocumentoIa;
use Tests\TestCase;

class MapeadorTipoDocumentoIaTest extends TestCase
{
    public function test_mapeia_campos_do_json(): void
    {
        $mapeador = new MapeadorTipoDocumentoIa;
        $r = $mapeador->mapear([
            'tipo_documento' => 'comprovante pix',
            'data_emissao' => '2026-08-20',
            'ano' => '2026',
            'sugestao_nome_arquivo' => 'PIX - Loja - 20.08.2026.jpg',
            'empresa_id' => '3',
            'empresa_cnpj' => '123',
            'empresa_razao_social' => 'POSTO PILECO LTDA',
        ]);

        $this->assertSame(TipoDocumentoRecebido::ComprovantesPagamento, $r['tipo']);
        $this->assertSame(2026, $r['ano']);
        $this->assertSame('2026-08-20', $r['data']);
        $this->assertSame('PIX - Loja - 20.08.2026.jpg', $r['nome']);
        $this->assertSame(3, $r['metadados']['empresa_id']);
        $this->assertSame('POSTO PILECO LTDA', $r['metadados']['empresa_razao_social']);
    }

    public function test_danfe_vai_para_nfe(): void
    {
        $this->assertSame(
            TipoDocumentoRecebido::Nfe,
            (new MapeadorTipoDocumentoIa)->tipoDeTexto('DANFE'),
        );
    }

    public function test_cte_e_mdfe(): void
    {
        $mapeador = new MapeadorTipoDocumentoIa;

        $this->assertSame(TipoDocumentoRecebido::Cte, $mapeador->tipoDeTexto('DACTE'));
        $this->assertSame(TipoDocumentoRecebido::Mdfe, $mapeador->tipoDeTexto('MDF-e'));
    }

    public function test_fatura_e_nf3e_nao_vao_para_nfe(): void
    {
        $mapeador = new MapeadorTipoDocumentoIa;

        $this->assertSame(TipoDocumentoRecebido::Faturas, $mapeador->tipoDeTexto('fatura'));
        $this->assertSame(TipoDocumentoRecebido::Faturas, $mapeador->tipoDeTexto('NF3-e'));
        $this->assertSame(TipoDocumentoRecebido::Faturas, $mapeador->tipoDeTexto('DANF3E'));
        $this->assertSame(TipoDocumentoRecebido::Faturas, $mapeador->tipoDeTexto('conta de luz'));
        $this->assertSame(TipoDocumentoRecebido::Nfe, $mapeador->tipoDeTexto('DANFE'));
    }

    public function test_pasta_atencao_usa_nome_legivel_no_drive(): void
    {
        $tipo = TipoDocumentoRecebido::AtencaoIdentificarEmpresa;

        $this->assertSame('Atenção - identificar a empresa', $tipo->pastaDrive());
        $this->assertSame('Atenção - identificar a empresa', $tipo->rotulo());
    }
}
