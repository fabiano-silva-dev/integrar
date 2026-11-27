<?php

namespace App\Services\Nfse\Dps;

use DanfseNacional\DanfseGenerator;
use Dompdf\Dompdf;
use Dompdf\Options;
use DOMDocument;
use DOMXPath;
use RuntimeException;

class DanfseHomologacao
{
    public function gerar(string $xml): string
    {
        // Nunca renderizar uma nota real com a rotina de homologação.
        $doc = new DOMDocument;
        if ($xml === '' || strlen($xml) > 8388608 || stripos($xml, '<!DOCTYPE') !== false
            || ! @$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype) {
            throw new RuntimeException('XML inválido.');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('n', GeradorDps::NS);
        $amb = $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:tpAmb');
        if ($amb->length !== 1 || $amb->item(0)->nodeValue !== '2') {
            throw new RuntimeException('Somente NFS-e de homologação.');
        }

        // Reutiliza o parser e template da biblioteca instalada, sem alterar o DANFSe de produção.
        $gerador = new DanfseGenerator;
        $html = $gerador->generateHtml($gerador->parseXml($xml));
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('helvetica', 'bold');
        if (! $font || $canvas->get_page_count() < 1) {
            throw new RuntimeException('Não foi possível identificar as páginas do DANFSe de teste.');
        }
        // Sobreposição explícita em todas as páginas, independente do conteúdo do template.
        $canvas->page_text(24, 8, 'HOMOLOGACAO - SEM VALOR FISCAL', $font, 11, [0.75, 0, 0]);
        $canvas->page_text(24, $canvas->get_height() - 20, 'HOMOLOGACAO - SEM VALOR FISCAL', $font, 11, [0.75, 0, 0]);
        $pdf = $dompdf->output();
        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Falha ao gerar DANFSe de teste.');
        }

        return $pdf;
    }
}
