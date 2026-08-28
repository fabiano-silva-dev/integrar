<?php

namespace App\Services\AutomacaoFiscal\Portais;

use App\Models\AutomacaoArtefato;
use App\Models\AutomacaoExecucao;
use App\Models\Empresa;
use App\Models\EmpresaIntegracao;
use App\Models\PortalRecurso;
use App\Services\AutomacaoFiscal\AutomacaoArtefatoService;
use App\Services\AutomacaoFiscal\Contratos\PortalAutomacao;
use App\Services\AutomacaoFiscal\Contratos\ResultadoAutomacao;
use App\Services\AutomacaoFiscal\Contratos\ResultadoValidacao;
use App\Services\AutomacaoFiscal\ImportadorExtratoNfseService;
use App\Services\AutomacaoFiscal\Logs\AutomacaoLogService;
use App\Services\AutomacaoFiscal\Runners\NodeRunnerBridge;

class NfseNacionalPortal implements PortalAutomacao
{
    public function __construct(
        private readonly NodeRunnerBridge $runner,
        private readonly AutomacaoLogService $logs,
        private readonly AutomacaoArtefatoService $artefatos
    ) {
    }

    public function codigo(): string
    {
        return 'nfse_nacional';
    }

    public function validarConfiguracao(EmpresaIntegracao $integracao): ResultadoValidacao
    {
        if (!$integracao->ativo) {
            return ResultadoValidacao::falha('Integração NFS-e Nacional inativa.');
        }

        if (!$integracao->certificado_digital_id) {
            return ResultadoValidacao::falha('Selecione um certificado digital A1 para o Portal Nacional.');
        }

        return ResultadoValidacao::sucesso();
    }

    public function executar(AutomacaoExecucao $execucao): ResultadoAutomacao
    {
        $execucao->loadMissing(['empresaIntegracao.certificadoDigital', 'portalRecurso', 'empresa']);

        $recurso = $execucao->portalRecurso?->codigo;
        $operation = match ($recurso) {
            'validar_acesso' => 'validate-access',
            'nfse_emitidas', 'nfse_recebidas' => 'extract-nfse',
            default => 'validate-access',
        };

        $fake = (bool) config('automacao_fiscal.fake_mode', true);
        $mode = $fake ? 'fake' : 'certificate';
        $params = $this->montarParams($execucao, $operation, $recurso);

        try {
            $saida = $this->runner->executar(
                $execucao,
                'nfse-emissor',
                $operation,
                $params,
                $mode,
                $execucao->empresaIntegracao?->certificadoDigital,
                onEvent: function (array $event) use ($execucao): void {
                    $etapa = (string) ($event['eventType'] ?? 'evento');
                    $mensagem = (string) ($event['message'] ?? $etapa);
                    $this->logs->registrar(
                        $execucao,
                        (string) ($event['level'] ?? 'info'),
                        $mensagem,
                        $etapa,
                        is_array($event['metadata'] ?? null) ? $event['metadata'] : null
                    );
                    $execucao->update([
                        'etapa_atual' => $etapa,
                        'mensagem_usuario' => $mensagem,
                    ]);
                },
                onArtifact: function (array $artifact) use ($execucao): void {
                    $this->artefatos->persistirDoRunner($execucao, [$artifact]);
                }
            );
        } catch (\Throwable $e) {
            return ResultadoAutomacao::falha($e->getMessage());
        }

        $statusRunner = $saida['status'] ?? 'failed';
        $mensagem = $saida['result']['errorMessage']
            ?? $saida['result']['resultData']['message']
            ?? null;

        if ($statusRunner === 'succeeded') {
            $import = $this->importarExtratoSeHouver($execucao, $saida);
            $quantidade = $import['encontrada']
                ?? (int) ($saida['result']['resultData']['quantidade'] ?? 0);

            return new ResultadoAutomacao(
                status: 'sucesso',
                mensagemUsuario: $import['mensagem']
                    ?? $mensagem
                    ?? ($operation === 'extract-nfse'
                        ? ($quantidade > 0
                            ? "{$quantidade} NFS-e encontrada(s) na listagem."
                            : 'Nenhuma NFS-e no período informado.')
                        : 'Acesso ao Portal Nacional validado.'),
                quantidadeEncontrada: $quantidade,
                quantidadeImportada: $import['importada'] ?? 0,
                quantidadeIgnorada: $import['ignorada'] ?? 0,
                logs: [],
                metadados: ['runner' => $saida['result'], 'params' => $params, 'importacao' => $import]
            );
        }

        if ($statusRunner === 'needs_intervention') {
            return new ResultadoAutomacao(
                status: 'falha',
                mensagemUsuario: $mensagem ?: 'O portal exige intervenção manual (papel, certificado ou captcha).',
                quantidadeErros: 1,
                logs: [],
                metadados: ['runner' => $saida['result'], 'params' => $params]
            );
        }

        return new ResultadoAutomacao(
            status: 'falha',
            mensagemUsuario: $mensagem ?: 'Falha na consulta ao Portal Nacional da NFS-e.',
            quantidadeErros: 1,
            logs: [],
            metadados: ['runner' => $saida['result'], 'params' => $params]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function montarParams(AutomacaoExecucao $execucao, string $operation, ?string $recurso): array
    {
        if ($operation !== 'extract-nfse') {
            return [];
        }

        $p = (array) ($execucao->parametros ?? []);

        $tipo = match ($recurso) {
            'nfse_recebidas' => 'recebidas',
            default => 'emitidas',
        };

        return [
            'tipo' => $p['tipo'] ?? $tipo,
            'periodoInicial' => $p['periodo_inicial']
                ?? $p['periodo_inicio']
                ?? optional($execucao->periodo_inicio)->format('Y-m-d'),
            'periodoFinal' => $p['periodo_final']
                ?? $p['periodo_fim']
                ?? optional($execucao->periodo_fim)->format('Y-m-d'),
            'busca' => trim((string) ($p['busca'] ?? '')),
        ];
    }

    /**
     * @param  array{status: string, result: array<string, mixed>}  $saida
     * @return array{mensagem?: string, encontrada?: int, importada?: int, ignorada?: int, coleta_id?: int}
     */
    private function importarExtratoSeHouver(AutomacaoExecucao $execucao, array $saida): array
    {
        try {
            // Resolve pelo ID (não depende da relação em memória do job/worker).
            $recurso = PortalRecurso::query()
                ->whereKey($execucao->portal_recurso_id)
                ->value('codigo');

            if (! in_array($recurso, ['nfse_emitidas', 'nfse_recebidas'], true)) {
                $this->logs->registrar(
                    $execucao,
                    'warning',
                    'Importação NFS-e ignorada: recurso não é listagem de notas ('.($recurso ?: 'null').').',
                    'IMPORT_SKIPPED',
                    ['portal_recurso_id' => $execucao->portal_recurso_id, 'recurso' => $recurso]
                );

                return [];
            }

            $empresa = Empresa::withoutGlobalScope('operadora')->find($execucao->empresa_id);
            if (! $empresa) {
                $this->logs->registrar(
                    $execucao,
                    'error',
                    'Importação NFS-e falhou: empresa da execução não encontrada.',
                    'IMPORT_FAILED',
                    ['empresa_id' => $execucao->empresa_id]
                );

                return [
                    'mensagem' => 'Extrato gerado, mas a empresa da execução não foi encontrada para importar.',
                ];
            }

            $dir = storage_path('app/automacao-fiscal-runner/'.$execucao->uuid.'/artifacts/'.$execucao->uuid);
            if (is_dir($dir)) {
                $this->artefatos->persistirDiretorioRunner($execucao, $dir);
            }

            $download = AutomacaoArtefato::withoutGlobalScope('operadora')
                ->where('automacao_execucao_id', $execucao->id)
                ->where(function ($q) {
                    $q->where('nome_original', 'extratonfse.txt')
                        ->orWhere('nome_original', 'like', '%extratonfse.txt')
                        ->orWhere('nome_original', 'like', '%extrato%.txt');
                })
                ->latest('id')
                ->first();

            if (! $download) {
                $this->logs->registrar(
                    $execucao,
                    'warning',
                    'Listagem concluída, mas o extratonfse.txt não foi persistido.',
                    'DOWNLOAD_MISSING',
                );

                return [
                    'mensagem' => 'Consulta concluída, mas o extratonfse.txt não foi gravado. Execute novamente.',
                ];
            }

            $caminho = $this->artefatos->caminhoAbsoluto($download);
            if (! $caminho || ! is_readable($caminho)) {
                $this->logs->registrar(
                    $execucao,
                    'warning',
                    'Arquivo extratonfse.txt não legível no storage.',
                    'DOWNLOAD_UNREADABLE',
                    ['artefato_id' => $download->id]
                );

                return [
                    'mensagem' => 'Consulta concluída, mas não foi possível ler o extratonfse.txt.',
                ];
            }

            $resultado = app(ImportadorExtratoNfseService::class)->importarArquivo(
                $empresa,
                $caminho,
                $download->nome_original ?: 'extratonfse.txt',
                $execucao->id,
                $recurso
            );

            $resumo = $resultado['resumo'] ?? [];
            $imp = is_array($resumo['importacao'] ?? null) ? $resumo['importacao'] : [];
            $novos = (int) ($imp['novos'] ?? 0);
            $atualizados = (int) ($imp['atualizados'] ?? 0);
            $ignorados = (int) ($imp['ignorados'] ?? 0);
            $totalDocs = (int) ($resultado['coleta']->quantidade_documentos ?? ($novos + $atualizados + $ignorados));

            $this->logs->registrar(
                $execucao,
                'info',
                "Extrato NFS-e importado: {$novos} novos, {$atualizados} atualizados, {$ignorados} ignorados.",
                'IMPORT_FINISHED',
                ['coleta_id' => $resultado['coleta']->id ?? null, 'resumo' => $resumo]
            );

            return [
                'mensagem' => "Extrato NFS-e importado: {$totalDocs} documento(s) ({$novos} novos, {$atualizados} atualizados).",
                'encontrada' => $totalDocs,
                'importada' => $novos + $atualizados,
                'ignorada' => $ignorados,
                'coleta_id' => $resultado['coleta']->id ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logs->registrar(
                $execucao,
                'error',
                'Falha ao importar extrato NFS-e: '.$e->getMessage(),
                'IMPORT_FAILED',
            );

            return [
                'mensagem' => 'Extrato gerado, mas a importação para Notas fiscais falhou: '.$e->getMessage(),
            ];
        }
    }
}
