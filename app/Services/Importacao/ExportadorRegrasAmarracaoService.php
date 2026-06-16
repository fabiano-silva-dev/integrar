<?php

namespace App\Services\Importacao;

use App\Models\RegraAmarracaoDescricao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportadorRegrasAmarracaoService
{
    public const COLUNAS = [
        'layout',
        'palavra_chave',
        'parte_digitavel',
        'tipo_busca',
        'conta_contrapartida',
        'centro_custo',
        'prioridade',
        'descricao',
        'ativo',
    ];

    public const ESTRATEGIAS_COPIA = [
        'adicionar_atualizar' => 'Adicionar e atualizar duplicadas',
        'somente_adicionar' => 'Somente adicionar novas',
        'substituir_layout' => 'Substituir todas do layout',
    ];

    public function queryRegras(int $empresaId, ?string $layout = null): Builder
    {
        return RegraAmarracaoDescricao::query()
            ->where('empresa_id', $empresaId)
            ->when($layout !== null && $layout !== '', function (Builder $q) use ($layout) {
                $q->where(function (Builder $q2) use ($layout) {
                    $q2->where('layout_avancado', $layout)->orWhereNull('layout_avancado');
                });
            })
            ->orderBy('palavra_chave')
            ->orderBy('parte_digitavel');
    }

    public function exportarCsv(int $empresaId, ?string $layout = null): string
    {
        $regras = $this->queryRegras($empresaId, $layout)->get();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::COLUNAS, ';');

        foreach ($regras as $regra) {
            fputcsv($handle, $this->regraParaLinha($regra), ';');
        }

        rewind($handle);
        $conteudo = stream_get_contents($handle);
        fclose($handle);

        return $conteudo ?: '';
    }

    public function exportarXlsx(int $empresaId, ?string $layout = null): string
    {
        $regras = $this->queryRegras($empresaId, $layout)->get();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach (self::COLUNAS as $colIndex => $coluna) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 1) . '1', $coluna);
        }

        $row = 2;
        foreach ($regras as $regra) {
            $linha = $this->regraParaLinha($regra);
            foreach ($linha as $colIndex => $valor) {
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($colIndex + 1) . $row,
                    $valor
                );
            }
            $row++;
        }

        $temp = tempnam(sys_get_temp_dir(), 'regras_amarracao_');
        $path = $temp . '.xlsx';
        rename($temp, $path);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    public function conteudoModeloCsv(): string
    {
        $linhas = [
            implode(';', self::COLUNAS),
            'ofx;PAGAMENTO PIX;CLIENTE ABC;starts_with;21001;;10;Recebimento de cliente;1',
            'ofx;TARIFA;;contains;43005;;5;Tarifa bancária;1',
        ];

        return implode("\n", $linhas) . "\n";
    }

    /**
     * @return array{total_origem: int, novas: int, atualizadas: int, ignoradas: int, removidas: int}
     */
    public function analisarCopia(int $empresaOrigemId, int $empresaDestinoId, string $layout, string $estrategia): array
    {
        $origem = $this->queryRegras($empresaOrigemId, $layout)->get();
        $destino = $this->queryRegras($empresaDestinoId, $layout)->get()->keyBy(fn ($r) => $this->chaveRegra($r));

        $novas = 0;
        $atualizadas = 0;
        $ignoradas = 0;

        foreach ($origem as $regra) {
            $chave = $this->chaveRegra($regra);
            $existe = $destino->has($chave);

            if ($existe) {
                if ($estrategia === 'somente_adicionar') {
                    $ignoradas++;
                } else {
                    $atualizadas++;
                }
            } else {
                $novas++;
            }
        }

        $removidas = 0;
        if ($estrategia === 'substituir_layout') {
            $removidas = $destino->count();
        }

        return [
            'total_origem' => $origem->count(),
            'novas' => $novas,
            'atualizadas' => $atualizadas,
            'ignoradas' => $ignoradas,
            'removidas' => $removidas,
        ];
    }

    /**
     * @return array{copiadas: int, atualizadas: int, ignoradas: int, removidas: int}
     */
    public function copiar(int $empresaOrigemId, int $empresaDestinoId, string $layout, string $estrategia): array
    {
        $origem = $this->queryRegras($empresaOrigemId, $layout)->get();
        $destinoMap = $this->queryRegras($empresaDestinoId, $layout)
            ->get()
            ->keyBy(fn ($r) => $this->chaveRegra($r));

        $copiadas = 0;
        $atualizadas = 0;
        $ignoradas = 0;
        $removidas = 0;

        DB::transaction(function () use (
            $origem,
            $destinoMap,
            $empresaDestinoId,
            $layout,
            $estrategia,
            &$copiadas,
            &$atualizadas,
            &$ignoradas,
            &$removidas
        ) {
            if ($estrategia === 'substituir_layout') {
                $removidas = RegraAmarracaoDescricao::where('empresa_id', $empresaDestinoId)
                    ->where(function ($q) use ($layout) {
                        $q->where('layout_avancado', $layout)->orWhereNull('layout_avancado');
                    })
                    ->delete();
                $destinoMap = collect();
            }

            foreach ($origem as $regra) {
                $chave = $this->chaveRegra($regra);
                $payload = $this->payloadCopia($regra, $empresaDestinoId, $layout);
                $existente = $destinoMap->get($chave);

                if ($existente) {
                    if ($estrategia === 'somente_adicionar') {
                        $ignoradas++;
                        continue;
                    }

                    $existente->update($payload);
                    $atualizadas++;
                    continue;
                }

                RegraAmarracaoDescricao::create($payload);
                $copiadas++;
            }
        });

        return [
            'copiadas' => $copiadas,
            'atualizadas' => $atualizadas,
            'ignoradas' => $ignoradas,
            'removidas' => $removidas,
        ];
    }

    /**
     * @return list<string|int>
     */
    private function regraParaLinha(RegraAmarracaoDescricao $regra): array
    {
        return [
            $regra->layout_avancado ?? '',
            $regra->palavra_chave ?? '',
            $regra->parte_digitavel ?? '',
            $regra->tipo_busca ?? 'starts_with',
            $regra->conta_contrapartida ?? '',
            $regra->centro_custo ?? '',
            $regra->prioridade ?? 0,
            $regra->descricao ?? '',
            $regra->ativo ? '1' : '0',
        ];
    }

    private function chaveRegra(RegraAmarracaoDescricao $regra): string
    {
        return implode('|', [
            $regra->layout_avancado ?? '',
            mb_strtolower(trim($regra->palavra_chave ?? '')),
            mb_strtolower(trim($regra->parte_digitavel ?? '')),
            $regra->tipo_busca ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadCopia(RegraAmarracaoDescricao $regra, int $empresaDestinoId, string $layout): array
    {
        return [
            'empresa_id' => $empresaDestinoId,
            'layout_avancado' => $regra->layout_avancado ?? $layout,
            'palavra_chave' => $regra->palavra_chave,
            'parte_digitavel' => $regra->parte_digitavel,
            'tipo_busca' => $regra->tipo_busca ?? 'starts_with',
            'conta_debito' => $regra->conta_debito,
            'conta_credito' => $regra->conta_credito,
            'conta_contrapartida' => $regra->conta_contrapartida,
            'centro_custo' => $regra->centro_custo,
            'prioridade' => $regra->prioridade ?? 0,
            'descricao' => $regra->descricao,
            'ativo' => $regra->ativo,
        ];
    }
}
