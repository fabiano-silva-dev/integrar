<?php

namespace Tests\Unit;

use App\Services\Nfse\CatalogoIndicadoresNfse;
use App\Services\Nfse\CatalogoTributarioNfse;
use App\Services\Nfse\Dps\AssinadorDps;
use App\Services\Nfse\Dps\GeradorDps;
use App\Services\Nfse\Dps\ValidadorDps;
use App\Services\Nfse\RecursosOficiaisNfse;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Tests\TestCase;

class NfseRecursosOficiaisTest extends TestCase
{
    public function test_carga_normalizada_corresponde_a_todas_as_linhas_da_planilha_oficial(): void
    {
        $pacote = app(RecursosOficiaisNfse::class)->verificar();
        $arquivo = $pacote['catalogo_tributario'];
        $linhas = app(CatalogoTributarioNfse::class)->ler(file_get_contents(resource_path('nfse/'.$arquivo['arquivo'])));
        $this->assertCount(173, $linhas);
        $this->assertCount(73, array_filter($linhas, fn ($l) => $l['ind_nfse'] === 1));
        $planilha = IOFactory::load(resource_path('nfse/'.$arquivo['original']));
        $csts = [];
        foreach (array_slice($planilha->getSheet(1)->toArray(null, false, false, false), 1) as $row) {
            if ($row[0] !== null) {
                $csts[str_pad((string) $row[0], 3, '0', STR_PAD_LEFT)] = (int) $row[2];
            }
        }
        $originais = array_values(array_filter(array_slice($planilha->getSheet(0)->toArray(null, false, false, false), 1), fn ($r) => $r[2] !== null));
        $this->assertCount(count($linhas), $originais);
        foreach ($originais as $indice => $row) {
            $l = $linhas[$indice];
            $this->assertSame(str_pad((string) $row[0], 3, '0', STR_PAD_LEFT), $l['cst']);
            $this->assertSame(str_pad((string) $row[2], 6, '0', STR_PAD_LEFT), $l['cclass_trib']);
            $this->assertSame(trim($row[1]), $l['descricao_cst']);
            $this->assertSame(trim($row[4]), $l['descricao']);
            $this->assertSame(Date::excelToDateTimeObject($row[21])->format('Y-m-d'), $l['inicio']);
            $this->assertSame($row[22] === null ? null : Date::excelToDateTimeObject($row[22])->format('Y-m-d'), $l['fim']);
            $this->assertSame((int) $row[33], $l['ind_nfse']);
            $this->assertSame($csts[$l['cst']], $l['ind_gibscbs']);
            $this->assertSame(number_format((float) $row[10], 2, '.', ''), $l['reducao_ibs']);
            $this->assertSame(number_format((float) $row[11], 2, '.', ''), $l['reducao_cbs']);
        }
        $planilha->disconnectWorksheets();
    }

    public function test_indop_vigente_preserva_os_26_codigos_e_textos_mesclados(): void
    {
        $pacote = app(RecursosOficiaisNfse::class)->verificar();
        $linhas = app(CatalogoIndicadoresNfse::class)->ler(file_get_contents(resource_path('nfse/'.$pacote['indicadores']['arquivo'])));
        $planilha = IOFactory::load(resource_path('nfse/'.$pacote['indicadores']['original']));
        $this->assertCount(26, $linhas);
        foreach ($linhas as $indice => $linha) {
            $this->assertSame($planilha->getSheet(0)->getCell('G'.($indice + 2))->getValue(), $linha['codigo']);
        }
        $this->assertSame('080101', $linhas[16]['codigo']);
        $this->assertSame($linhas[3]['fundamento'], $linhas[6]['fundamento']);
        $this->assertStringContainsString('Serviço prestado fisicamente sobre a pessoa', $linhas[6]['descricao']);
        $planilha->disconnectWorksheets();
    }

    public function test_dps_assinada_passa_no_xsd_oficial_e_ausencia_de_campo_obrigatorio_falha(): void
    {
        $dados = [
            'prestador' => '16679526000180', 'tomador' => '52998224725', 'nome_tomador' => 'Tomador teste',
            'municipio_emissor' => '4308003', 'municipio_prestacao' => '4308003', 'serie' => '1', 'numero' => '17',
            'competencia' => '2026-11-01', 'emissao' => new DateTimeImmutable('2026-11-27T10:00:00-03:00'),
            'opSimpNac' => '3', 'regApTribSN' => '1', 'escopo_confirmado' => true, 'ibscbs_nao_exigido' => true,
            'valor' => '123.45', 'descricao' => 'Serviço teste', 'codigo_tributacao_nacional' => '170101',
            'percentuais_tributos' => ['pTotTribFed' => '5.00', 'pTotTribEst' => '0.00', 'pTotTribMun' => '2.00'],
        ];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Teste local'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privado);
        openssl_x509_export($cert, $publico);
        $doc = app(GeradorDps::class)->gerar($dados);
        $xml = app(AssinadorDps::class)->assinar($doc, $publico, $privado, 'rsa-sha256');
        $validador = app(ValidadorDps::class);
        $validador->validar($xml);
        $this->assertSame(realpath(resource_path('nfse/xsd/restrita/DPS_v1.01.xsd')), $validador->garantirDisponivel());
        $this->expectException(RuntimeException::class);
        $validador->validar(str_replace('<finNFSe>0</finNFSe>', '', $xml));
    }
}
