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
            'empresa_cnpj' => '123',
        ]);

        $this->assertSame(TipoDocumentoRecebido::ComprovantesPagamento, $r['tipo']);
        $this->assertSame(2026, $r['ano']);
        $this->assertSame('2026-08-20', $r['data']);
        $this->assertSame('PIX - Loja - 20.08.2026.jpg', $r['nome']);
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
}
