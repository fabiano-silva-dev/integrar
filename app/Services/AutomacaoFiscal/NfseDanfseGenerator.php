<?php

namespace App\Services\AutomacaoFiscal;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Gera o DANFSe (PDF) a partir do XML nacional. Prefere o CLI Node; cai no PDF local.
 */
class NfseDanfseGenerator
{
    public const CONSULTA_PUBLICA = 'https://www.nfse.gov.br/ConsultaPublica';

    /**
     * @return array<string, string>
     */
    public function extrairCampos(string $xml): array
    {
        $tag = static function (string $name) use ($xml): string {
            if (preg_match('/<'.$name.'\b[^>]*>([\s\S]*?)<\/'.$name.'>/i', $xml, $m)) {
                return html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }

            return '';
        };

        $chave = AnaliseFiscalService::normalizarChaveAcesso($tag('cChaveAcesso') ?: $tag('chNFSe')) ?? '';
        if ($chave === '' && preg_match('/\b(\d{50})\b/', $xml, $m)) {
            $chave = $m[1];
        }

        $prestadorCnpj = $this->primeiro($tag('CNPJ'), $this->cnpjEmBloco($xml, 'prest'));
        $tomadorCnpj = $this->cnpjEmBloco($xml, 'toma') ?: $this->cnpjEmBloco($xml, 'tomaNaoIdentif');

        return [
            'chave' => $chave,
            'numero' => $tag('nNFSe') ?: $tag('nDPS'),
            'competencia' => $tag('dCompet') ?: $tag('dhEmi'),
            'prestador_nome' => $this->nomeEmBloco($xml, 'prest') ?: $tag('xNome'),
            'prestador_cnpj' => $prestadorCnpj,
            'tomador_nome' => $this->nomeEmBloco($xml, 'toma'),
            'tomador_cnpj' => $tomadorCnpj,
            'municipio' => $tag('xLocEmi') ?: $tag('xLocPrestacao'),
            'descricao' => $tag('xDescServ') ?: $tag('xInfComp'),
            'valor' => $tag('vServ') ?: $tag('vLiq'),
            'consulta_url' => self::CONSULTA_PUBLICA.($chave !== '' ? '?chaveAcesso='.$chave : ''),
        ];
    }

    public function gerarPdf(string $xml): string
    {
        if (! str_contains($xml, '<') || (! str_contains($xml, 'NFSe') && ! str_contains($xml, 'nfse'))) {
            throw new RuntimeException('O arquivo não é um XML de NFS-e.');
        }

        $dist = base_path('scripts/automacao-fiscal/runner/dist/danfse/gerarDanfseCli.js');
        $src = base_path('scripts/automacao-fiscal/runner/src/danfse/gerarDanfseCli.ts');
        if (is_file($dist) || is_file($src)) {
            try {
                return $this->gerarViaNode($xml);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->gerarViaPdfLocal($xml);
    }

    public function gerarViaPdfLocal(string $xml): string
    {
        $c = $this->extrairCampos($xml);
        $linhas = [
            ['DANFSe - Documento Auxiliar da NFS-e', 'Sistema Nacional da NFS-e'],
            ['Chave de acesso', $c['chave'] !== '' ? $c['chave'] : '—'],
            ['Numero', $c['numero'] !== '' ? $c['numero'] : '—'],
            ['Competencia / emissao', $c['competencia'] !== '' ? $c['competencia'] : '—'],
            ['Valor do servico', $c['valor'] !== '' ? $c['valor'] : '—'],
            ['Prestador', trim($c['prestador_nome'].'  '.$c['prestador_cnpj']) ?: '—'],
            ['Tomador', trim($c['tomador_nome'].'  '.$c['tomador_cnpj']) ?: '—'],
            ['Municipio', $c['municipio'] !== '' ? $c['municipio'] : '—'],
            ['Discriminacao do servico', $c['descricao'] !== '' ? mb_substr($c['descricao'], 0, 180) : '—'],
            ['Consulta publica', $c['consulta_url']],
            ['Observacao', 'PDF gerado localmente a partir do XML autorizado (NT 008/2026).'],
        ];

        $y = 760;
        $conteudo = "0.4 w\n36 790 523 24 re S\n";
        foreach ($linhas as [$titulo, $valor]) {
            $conteudo .= 'BT /F1 8 Tf 40 '.($y + 12).' Td ('.$this->pdfString($titulo).') Tj ET'."\n";
            $conteudo .= 'BT /F2 10 Tf 40 '.$y.' Td ('.$this->pdfString($valor).') Tj ET'."\n";
            $y -= 32;
        }

        $stream = $conteudo;
        $len = strlen($stream);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
            "<< /Length {$len} >>\nstream\n{$stream}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        $body = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($body);
            $body .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($body);
        $body .= 'xref'."\n".'0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $body .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $body .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        if (! str_starts_with($body, '%PDF')) {
            throw new RuntimeException('Não foi possível gerar o DANFSe.');
        }

        return $body;
    }

    private function gerarViaNode(string $xml): string
    {
        $runnerDir = base_path('scripts/automacao-fiscal/runner');
        $dist = $runnerDir.'/dist/danfse/gerarDanfseCli.js';
        $src = $runnerDir.'/src/danfse/gerarDanfseCli.ts';
        $in = tempnam(sys_get_temp_dir(), 'nfse-xml-');
        $out = tempnam(sys_get_temp_dir(), 'nfse-pdf-');
        if ($in === false || $out === false) {
            throw new RuntimeException('Não foi possível criar arquivos temporários para o DANFSe.');
        }
        $xmlPath = $in.'.xml';
        $pdfPath = $out.'.pdf';
        rename($in, $xmlPath);
        rename($out, $pdfPath);
        file_put_contents($xmlPath, $xml);

        try {
            if (is_file($dist)) {
                $cmd = ['node', $dist, '--input', $xmlPath, '--output', $pdfPath];
            } elseif (is_file($src)) {
                $cmd = ['node', '--experimental-strip-types', $src, '--input', $xmlPath, '--output', $pdfPath];
            } else {
                throw new RuntimeException('CLI de DANFSe não encontrado no runner.');
            }

            $process = new Process($cmd, $runnerDir, null, null, 30);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($pdfPath) || filesize($pdfPath) < 8) {
                throw new RuntimeException(
                    'CLI DANFSe falhou: '.trim($process->getErrorOutput().' '.$process->getOutput())
                );
            }

            $pdf = file_get_contents($pdfPath);
            if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException('CLI DANFSe não gerou um PDF válido.');
            }

            return $pdf;
        } finally {
            @unlink($xmlPath);
            @unlink($pdfPath);
        }
    }

    private function pdfString(string $texto): string
    {
        $latin = $this->latin1($texto);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $latin);
    }

    private function latin1(string $texto): string
    {
        $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);

        return is_string($conv) ? $conv : utf8_decode($texto);
    }

    private function primeiro(string ...$valores): string
    {
        foreach ($valores as $v) {
            if (trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    private function cnpjEmBloco(string $xml, string $bloco): string
    {
        if (! preg_match('/<'.$bloco.'\b[\s\S]*?<\/'.$bloco.'>/i', $xml, $m)) {
            return '';
        }
        if (preg_match('/<(CNPJ|CPF)\b[^>]*>([\s\S]*?)<\/\1>/i', $m[0], $c)) {
            return preg_replace('/\D+/', '', $c[2]) ?? '';
        }

        return '';
    }

    private function nomeEmBloco(string $xml, string $bloco): string
    {
        if (! preg_match('/<'.$bloco.'\b[\s\S]*?<\/'.$bloco.'>/i', $xml, $m)) {
            return '';
        }
        if (preg_match('/<xNome\b[^>]*>([\s\S]*?)<\/xNome>/i', $m[0], $c)) {
            return html_entity_decode(trim($c[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return '';
    }
}
