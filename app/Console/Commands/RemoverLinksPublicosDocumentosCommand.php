<?php

namespace App\Console\Commands;

use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\EmpresasOperadora;
use App\Services\Documentos\AcessoLinkDrive;
use App\Services\Documentos\DocumentoProcessoLogService;
use App\Services\Documentos\GoogleDriveService;
use Illuminate\Console\Command;

class RemoverLinksPublicosDocumentosCommand extends Command
{
    protected $signature = 'documentos:remover-links-publicos
                            {--operadora= : ID do escritório (omita para todos)}
                            {--dry-run : Lista as permissões públicas sem removê-las}
                            {--remover-domain : Também remove permissões de domínio Google Workspace}';

    protected $description = 'Remove permissões anyone (link público) de arquivos e pastas do Google Drive do módulo Documentos';

    public function handle(
        GoogleDriveService $driveService,
        AcessoLinkDrive $acesso,
        DocumentoProcessoLogService $logs,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $removerDomain = (bool) $this->option('remover-domain');
        $operadoraOpt = $this->option('operadora');

        $operadoras = EmpresasOperadora::query()
            ->when(is_numeric($operadoraOpt), fn ($q) => $q->where('id', (int) $operadoraOpt))
            ->orderBy('id')
            ->get();

        if ($operadoras->isEmpty()) {
            $this->warn('Nenhum escritório encontrado.');

            return self::SUCCESS;
        }

        $totalRemovidas = 0;
        $totalArquivos = 0;
        $totalErros = 0;

        foreach ($operadoras as $operadora) {
            $conta = ContaGoogle::withoutGlobalScope('operadora')
                ->where('empresa_operadora_id', $operadora->id)
                ->first();

            if ($conta === null || ! $conta->conectada()) {
                $this->line("Escritório {$operadora->id}: conta Google ausente ou desconectada — pulado.");

                continue;
            }

            try {
                $drive = $driveService->apiDaConta($conta);
            } catch (\Throwable $exception) {
                $this->error("Escritório {$operadora->id}: ".$exception->getMessage());
                $totalErros++;

                continue;
            }

            $ids = $this->fileIdsDoEscritorio((int) $operadora->id);
            $this->info("Escritório {$operadora->id}: ".count($ids).' item(ns) no Drive'.($dryRun ? ' (simulação)' : '').'.');

            foreach ($ids as $fileId) {
                $totalArquivos++;
                $resultado = $acesso->removerPublicas($drive, $fileId, $dryRun, $removerDomain);

                if ($resultado['erro'] !== null && ! $resultado['pulado']) {
                    $totalErros++;
                    $this->warn("  {$fileId}: {$resultado['erro']}");

                    continue;
                }

                if ($resultado['pulado'] && $resultado['erro'] !== null) {
                    $this->line("  {$fileId}: pulado ({$resultado['erro']})");

                    continue;
                }

                if ($resultado['removidas'] > 0) {
                    $totalRemovidas += $resultado['removidas'];
                    $acao = $dryRun ? 'seria removida' : 'removida';
                    $this->line("  {$fileId}: {$resultado['removidas']} permissão(ões) pública(s) {$acao}.");

                    $logs->registrar(
                        'info',
                        'drive',
                        $dryRun
                            ? 'Permissão pública antiga seria removida.'
                            : 'Permissão pública antiga removida.',
                        [
                            'empresa_operadora_id' => $operadora->id,
                            'drive_file_id' => $fileId,
                            'removidas' => $resultado['removidas'],
                            'dry_run' => $dryRun,
                        ],
                        operadoraId: (int) $operadora->id,
                        forcar: true,
                    );
                }
            }
        }

        $this->newLine();
        $this->info("Itens verificados: {$totalArquivos}. Permissões ".($dryRun ? 'a remover' : 'removidas').": {$totalRemovidas}. Erros: {$totalErros}.");

        return $totalErros > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function fileIdsDoEscritorio(int $operadoraId): array
    {
        $ids = [];

        $pastas = EmpresaPastaDrive::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $operadoraId)
            ->pluck('google_folder_id');

        foreach ($pastas as $folderId) {
            $id = trim((string) $folderId);
            if ($id !== '' && $id !== 'root') {
                $ids[$id] = $id;
            }
        }

        DocumentoRecebido::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $operadoraId)
            ->whereNotNull('drive_file_id')
            ->where('drive_file_id', '!=', '')
            ->select(['drive_file_id', 'metadados'])
            ->orderBy('id')
            ->chunk(200, function ($documentos) use (&$ids) {
                foreach ($documentos as $documento) {
                    $id = trim((string) $documento->drive_file_id);
                    if ($id !== '') {
                        $ids[$id] = $id;
                    }

                    $copias = $documento->metadados['copias_drive'] ?? [];
                    if (! is_array($copias)) {
                        continue;
                    }

                    foreach ($copias as $copia) {
                        if (! is_array($copia)) {
                            continue;
                        }
                        $copiaId = trim((string) ($copia['drive_file_id'] ?? ''));
                        if ($copiaId !== '') {
                            $ids[$copiaId] = $copiaId;
                        }
                    }
                }
            });

        return array_values($ids);
    }
}
