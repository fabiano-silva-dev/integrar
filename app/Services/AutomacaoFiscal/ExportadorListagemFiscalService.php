<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\DocumentoFiscal;
use App\Models\Empresa;
use App\Models\PortalIntegracao;
use App\Services\OperadoraContext;
use App\Services\OperadoraStorage;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta exclusivamente os documentos já importados e armazenados na base.
 * Não dispara consultas, acessos ao portal nem rotinas de automação fiscal.
 */
class ExportadorListagemFiscalService
{
    public function baixar(
        int $empresaId,
        int $portalId,
        string $competencia,
        ?string $tipoListagem,
        ?string $dataInicio,
        ?string $dataFim,
        string $formato
    ): StreamedResponse {
        abort_unless(in_array($formato, ['xls', 'pdf'], true), 404);

        $operadoraId = OperadoraContext::requireId();
        // A validação explícita complementa o Global Scope, inclusive no super_admin.
        $empresa = Empresa::query()
            ->where('empresa_operadora_id', $operadoraId)
            ->findOrFail($empresaId);
        $portal = PortalIntegracao::query()->findOrFail($portalId);
        $ehNfse = $portal->codigo === 'nfse_nacional';
        $tipoListagem = $ehNfse
            ? (AnaliseFiscalService::normalizarTipoListagem($tipoListagem) ?? 'emitidas')
            : null;

        $query = app(AnaliseFiscalService::class)
            ->queryDocumentos($empresaId, $portalId, $competencia, $tipoListagem, $dataInicio, $dataFim)
            ->where('documentos_fiscais.empresa_operadora_id', $operadoraId);
        $quantidade = (clone $query)->count();

        abort_if($quantidade === 0, 422, 'Não há documentos no período informado para exportar.');
        abort_if($formato === 'xls' && $quantidade > 65530, 422,
            'O formato XLS comporta até 65.530 documentos. Reduza o período do filtro.');
        abort_if($formato === 'pdf' && $quantidade > 5000, 422,
            'Para preservar a geração do PDF, filtre um período com até 5.000 documentos.');

        $documentos = $query
            ->orderByDesc('data_emissao')
            ->orderByDesc('numero')
            ->get();

        $periodo = ($dataInicio ?: 'Início da competência').' a '.($dataFim ?: 'Fim da competência');
        $cabecalhos = $this->cabecalhos($ehNfse);
        $total = $documentos->sum(fn (DocumentoFiscal $doc) => (float) $doc->valor_total);
        $nome = sprintf('notas-%d-%s-%s.%s', $empresaId, $competencia, now()->format('Ymd-His'), $formato);

        return response()->streamDownload(function () use (
            $formato, $operadoraId, $empresa, $portal, $competencia, $tipoListagem,
            $dataInicio, $dataFim, $periodo, $ehNfse, $documentos, $cabecalhos, $total
        ) {
            if ($formato === 'xls') {
                $this->gerarXls(
                    $documentos,
                    $cabecalhos,
                    $empresa->nome,
                    $portal->nome,
                    $competencia,
                    $periodo,
                    $ehNfse,
                    $total
                );

                return;
            }

            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('tempDir', OperadoraStorage::tempDirectory($operadoraId));

            $pdf = new Dompdf($options);
            $pdf->setPaper('a3', 'landscape');
            $pdf->loadHtml(view('exports.listagem-documentos-fiscais', [
                'empresa' => $empresa,
                'portal' => $portal,
                'competencia' => $competencia,
                'tipoListagem' => $tipoListagem,
                'dataInicio' => $dataInicio,
                'dataFim' => $dataFim,
                'documentos' => $documentos,
                'cabecalhos' => $cabecalhos,
                'ehNfse' => $ehNfse,
                'total' => $total,
            ])->render(), 'UTF-8');
            $pdf->render();
            echo $pdf->output();
        }, $nome, [
            'Content-Type' => $formato === 'xls'
                ? 'application/vnd.ms-excel'
                : 'application/pdf',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> */
    private function cabecalhos(bool $ehNfse): array
    {
        if ($ehNfse) {
            return ['Geração', 'Número', 'Prestador', 'Tomador', 'Serviço (R$)', 'Situação', 'Chave'];
        }

        return [
            'Emissão', 'Número', 'Série', 'Modelo', 'Emitente', 'Destinatário',
            'Total (R$)', 'ICMS (R$)', 'E/S', 'Situação', 'Chave',
        ];
    }

    /** @return list<string|float> */
    public function linha(DocumentoFiscal $doc, bool $ehNfse): array
    {
        $emitente = (string) ($doc->nome_emitente ?: $doc->cnpj_emitente ?: '');
        $destinatario = (string) ($doc->nome_destinatario ?: $doc->cnpj_destinatario ?: '');
        $data = $doc->data_emissao?->format('d/m/Y') ?? '';
        $chave = (string) ($doc->chave_acesso ?? '');

        if ($ehNfse) {
            return [
                $data, (string) ($doc->numero ?? ''), $emitente, $destinatario,
                (float) $doc->valor_total,
                (string) (ExtratoNfseParser::labelSituacao($doc->situacao) ?? $doc->situacao ?? ''),
                $chave,
            ];
        }

        return [
            $data,
            (string) ($doc->numero ?? ''),
            (string) ($doc->serie ?? ''),
            (string) (ExtratoNfeEcacRsParser::labelModelo($doc->modelo) ?? $doc->modelo ?? ''),
            $emitente,
            $destinatario,
            (float) $doc->valor_total,
            (float) $doc->valor_icms,
            (string) (ExtratoNfeEcacRsParser::labelEntradaSaida($doc->entrada_saida) ?? $doc->entrada_saida ?? ''),
            (string) (ExtratoNfeEcacRsParser::labelSituacao($doc->situacao) ?? $doc->situacao ?? ''),
            $chave,
        ];
    }

    /** @param Collection<int, DocumentoFiscal> $documentos
     *  @param list<string> $cabecalhos
     */
    private function gerarXls(
        Collection $documentos,
        array $cabecalhos,
        string $empresaNome,
        string $portalNome,
        string $competencia,
        string $periodo,
        bool $ehNfse,
        float $total
    ): void {
        $spreadsheet = new Spreadsheet();
        try {
            $spreadsheet->getProperties()->setCreator('IntegraExpert')->setTitle('Listagem de notas fiscais');
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Documentos');
            $ultimaColuna = Coordinate::stringFromColumnIndex(count($cabecalhos));

            $sheet->mergeCells("A1:{$ultimaColuna}1");
            $sheet->setCellValue('A1', 'Listagem de notas fiscais - IntegraExpert');
            $sheet->mergeCells("A2:{$ultimaColuna}2");
            $sheet->setCellValueExplicit('A2', "Empresa: {$empresaNome} | Portal: {$portalNome} | Competência: {$competencia}", DataType::TYPE_STRING);
            $sheet->mergeCells("A3:{$ultimaColuna}3");
            $sheet->setCellValueExplicit('A3', sprintf(
                'Filtro de emissão/geração: %s | Documentos: %d | Total: R$ %s',
                $periodo,
                $documentos->count(),
                number_format($total, 2, ',', '.')
            ), DataType::TYPE_STRING);

            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
            $sheet->getStyle("A5:{$ultimaColuna}5")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A5:{$ultimaColuna}5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
            $sheet->getStyle("A5:{$ultimaColuna}5")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension(5)->setRowHeight(25);
            $sheet->freezePane('A6');

            foreach ($cabecalhos as $index => $titulo) {
                $coluna = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValueExplicit("{$coluna}5", $titulo, DataType::TYPE_STRING);
                $sheet->getColumnDimension($coluna)->setWidth(in_array($titulo, ['Emitente', 'Destinatário', 'Prestador', 'Tomador'], true) ? 38 : ($titulo === 'Chave' ? 55 : 18));
            }

            $linhaExcel = 6;
            foreach ($documentos as $documento) {
                foreach ($this->linha($documento, $ehNfse) as $index => $valor) {
                    $coluna = Coordinate::stringFromColumnIndex($index + 1);
                    $celula = $coluna.$linhaExcel;
                    if (is_float($valor)) {
                        $sheet->setCellValue($celula, $valor);
                        $sheet->getStyle($celula)->getNumberFormat()->setFormatCode('#,##0.00');
                    } else {
                        // Número, CNPJ, chave e textos nunca devem virar fórmula ou notação científica.
                        $sheet->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
                    }
                }
                $linhaExcel++;
            }

            $sheet->setAutoFilter("A5:{$ultimaColuna}".($linhaExcel - 1));
            (new Xls($spreadsheet))->save('php://output');
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
