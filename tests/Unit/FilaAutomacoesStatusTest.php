<?php

namespace Tests\Unit;

use App\Services\AutomacaoFiscal\FilaAutomacoesStatus;
use Tests\TestCase;

class FilaAutomacoesStatusTest extends TestCase
{
    public function test_nao_avisa_fora_do_ambiente_local(): void
    {
        $this->app['env'] = 'testing';
        config(['automacao_fiscal.fake_mode' => false, 'queue.default' => 'database']);

        $this->assertNull(app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(false));
    }

    public function test_nao_avisa_em_modo_simulado(): void
    {
        $this->app['env'] = 'local';
        config(['automacao_fiscal.fake_mode' => true, 'queue.default' => 'database']);

        $this->assertNull(app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(false));
    }

    public function test_nao_avisa_com_fila_sync(): void
    {
        $this->app['env'] = 'local';
        config(['automacao_fiscal.fake_mode' => false, 'queue.default' => 'sync']);

        $this->assertNull(app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(false));
    }

    public function test_nao_avisa_quando_worker_esta_ativo(): void
    {
        $this->app['env'] = 'local';
        config(['automacao_fiscal.fake_mode' => false, 'queue.default' => 'database']);

        $this->assertNull(app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(true));
    }

    public function test_avisa_no_local_quando_worker_esta_parado(): void
    {
        $this->app['env'] = 'local';
        config(['automacao_fiscal.fake_mode' => false, 'queue.default' => 'database']);

        $aviso = app(FilaAutomacoesStatus::class)->avisoDesenvolvimento(false);

        $this->assertIsArray($aviso);
        $this->assertSame('Fila de automações parada', $aviso['titulo']);
        $this->assertStringContainsString('queue:work', $aviso['comando']);
        $this->assertStringContainsString('automacoes', $aviso['comando']);
        $this->assertStringContainsString('Na fila', app(FilaAutomacoesStatus::class)->mensagemBloqueioDesenvolvimento(false));
    }

    public function test_reconhece_cmdline_do_worker_automacoes(): void
    {
        $status = app(FilaAutomacoesStatus::class);

        $this->assertTrue($status->cmdlineEhWorkerAutomacoes(
            'php artisan queue:work database --queue=automacoes,default --timeout=900'
        ));
        $this->assertFalse($status->cmdlineEhWorkerAutomacoes(
            'php artisan queue:work database --queue=default'
        ));
        $this->assertFalse($status->cmdlineEhWorkerAutomacoes(
            'php -r foreach (glob("/proc/[0-9]*") as $d) { queue:work automacoes }'
        ));
    }

    public function test_cmdline_reconhece_fila_documentos_no_mesmo_worker(): void
    {
        $status = app(FilaAutomacoesStatus::class);
        $cmd = 'php artisan queue:work database --queue=automacoes,documentos,default --timeout=900';

        $this->assertTrue($status->cmdlineCobreFila($cmd, 'automacoes'));
        $this->assertTrue($status->cmdlineCobreFila($cmd, 'documentos'));
        $this->assertFalse($status->cmdlineCobreFila(
            'php artisan queue:work database --queue=automacoes,default',
            'documentos'
        ));
        $this->assertFalse($status->cmdlineCobreFila(
            'php artisan queue:work --sleep=3 --tries=3',
            'documentos'
        ));
    }

    public function test_cabecalho_nao_avisa_fora_do_local(): void
    {
        $this->app['env'] = 'testing';
        config(['queue.default' => 'database']);

        $this->assertNull(app(FilaAutomacoesStatus::class)->avisoCabecalhoDesenvolvimento(false, false));
    }

    public function test_cabecalho_avisa_no_local_mesmo_com_modo_simulado(): void
    {
        $this->app['env'] = 'local';
        config(['automacao_fiscal.fake_mode' => true, 'queue.default' => 'database']);

        $aviso = app(FilaAutomacoesStatus::class)->avisoCabecalhoDesenvolvimento(false, false);

        $this->assertIsArray($aviso);
        $this->assertSame('Fila parada no desenvolvimento', $aviso['titulo']);
        $this->assertStringContainsString('documentos do WhatsApp', $aviso['texto']);
        $this->assertStringContainsString('automações', $aviso['texto']);
        $this->assertStringContainsString('documentos', $aviso['comando']);
    }

    public function test_cabecalho_avisa_se_falta_so_a_fila_documentos(): void
    {
        $this->app['env'] = 'local';
        config(['queue.default' => 'database']);

        $aviso = app(FilaAutomacoesStatus::class)->avisoCabecalhoDesenvolvimento(false, true);

        $this->assertIsArray($aviso);
        $this->assertStringContainsString('documentos do WhatsApp', $aviso['texto']);
        $this->assertStringNotContainsString('automações', $aviso['texto']);
    }

    public function test_cabecalho_nao_avisa_quando_as_duas_filas_estao_no_ar(): void
    {
        $this->app['env'] = 'local';
        config(['queue.default' => 'database']);

        $this->assertNull(app(FilaAutomacoesStatus::class)->avisoCabecalhoDesenvolvimento(true, true));
    }
}
