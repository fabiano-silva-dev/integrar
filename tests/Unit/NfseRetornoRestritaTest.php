<?php

namespace Tests\Unit;

use App\Services\Nfse\Dps\GeradorDps;
use App\Services\Nfse\Dps\RetornoSefinRestrita;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NfseRetornoRestritaTest extends TestCase
{
    private const ID = 'DPS430800321667952600018000001000000000000017';
    private const CHAVE = '43080031216679526000180000000000001726066074736113';

    private function inf(string $amb = '2'): string
    {
        return '<infDPS Id="'.self::ID.'"><tpAmb>'.$amb.'</tpAmb><prest><CNPJ>16679526000180</CNPJ></prest><valores><vServ>100.01</vServ></valores></infDPS>';
    }

    private function dps(string $amb = '2'): string
    {
        return '<DPS xmlns="'.GeradorDps::NS.'">'.$this->inf($amb).'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></DPS>';
    }

    private function resposta(?string $inf = null): array
    {
        $xml = '<NFSe xmlns="'.GeradorDps::NS.'"><infNFSe Id="NFS'.self::CHAVE.'"><cStat>100</cStat><emit><CNPJ>16679526000180</CNPJ></emit><DPS>'.($inf ?? $this->inf()).'</DPS></infNFSe></NFSe>';
        return ['idDps' => self::ID, 'chaveAcesso' => self::CHAVE, 'tipoAmbiente' => 2, 'nfseXmlGZipB64' => base64_encode(gzencode($xml))];
    }

    public function test_confirma_xml_correspondente(): void
    {
        $r = RetornoSefinRestrita::confirmar($this->resposta(), $this->dps(), self::ID);
        $this->assertSame(self::CHAVE, $r['chave']);
        $this->assertStringContainsString('100.01', $r['xml']);
    }

    public function test_rejeita_xml_com_valor_divergente(): void
    {
        $this->expectException(RuntimeException::class);
        RetornoSefinRestrita::confirmar($this->resposta(str_replace('100.01', '900.00', $this->inf())), $this->dps(), self::ID);
    }

    public function test_rejeita_ambiente_de_producao(): void
    {
        $this->expectException(RuntimeException::class);
        RetornoSefinRestrita::validarDps($this->dps('1'));
    }

    public function test_rejeita_substituicao(): void
    {
        $this->expectException(RuntimeException::class);
        RetornoSefinRestrita::validarDps(str_replace('</infDPS>', '<subst/></infDPS>', $this->dps()));
    }

    public function test_rejeita_gzip_invalido(): void
    {
        $r = $this->resposta();
        $r['nfseXmlGZipB64'] = base64_encode('inválido');
        $this->expectException(RuntimeException::class);
        RetornoSefinRestrita::confirmar($r, $this->dps(), self::ID);
    }

    public function test_codigos_nao_incluem_mensagens_ou_html(): void
    {
        $this->assertSame(['E1235'], RetornoSefinRestrita::codigos(['erros' => [['codigo' => 'E1235', 'descricao' => 'dados fiscais'], ['codigo' => '<html>']]]));
    }
}
