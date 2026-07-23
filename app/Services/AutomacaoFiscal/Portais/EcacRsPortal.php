<?php

namespace App\Services\AutomacaoFiscal\Portais;

use App\Models\AutomacaoExecucao;
use App\Models\EmpresaIntegracao;
use App\Services\AutomacaoFiscal\AutomacaoArtefatoService;
use App\Services\AutomacaoFiscal\Contratos\PortalAutomacao;
use App\Services\AutomacaoFiscal\Contratos\ResultadoAutomacao;
use App\Services\AutomacaoFiscal\Contratos\ResultadoValidacao;
use App\Services\AutomacaoFiscal\ImportadorExtratoNfeService;
use App\Services\AutomacaoFiscal\Logs\AutomacaoLogService;
use App\Services\AutomacaoFiscal\Runners\NodeRunnerBridge;
use App\Models\AutomacaoArtefato;

class EcacRsPortal implements PortalAutomacao
{
    public function __construct(
        private readonly NodeRunnerBridge $runner,
        private readonly AutomacaoLogService $logs,
        private readonly AutomacaoArtefatoService $artefatos
    ) {
    }

    public function codigo(): string
    {
        return 'ecac_rs';
    }

    public function validarConfiguracao(EmpresaIntegracao $integracao): ResultadoValidacao
    {
        if (!$integracao->ativo) {
            return ResultadoValidacao::falha('Integração e-CAC RS inativa.');
        }

        if (!$integracao->certificado_digital_id) {
            return ResultadoValidacao::falha('Selecione um certificado digital A1 para o e-CAC RS.');
        }

        return ResultadoValidacao::sucesso();
    }

    public function executar(AutomacaoExecucao $execucao): ResultadoAutomacao
    {
        $execucao->loadMissing(['empresaIntegracao.certificadoDigital', 'portalRecurso', 'empresa']);

        $recurso = $execucao->portalRecurso?->codigo;
        $operation = match ($recurso) {
            'validar_acesso' => 'validate-access',
            'nfe_emitidas', 'nfce_emitidas' => 'extract-nfe-nfce',
            default => 'validate-access',
        };

        $fake = (bool) config('automacao_fiscal.fake_mode', true);
        $mode = $fake ? 'fake' : 'certificate';
        $certificado = $execucao->empresaIntegracao?->certificadoDigital;
        $params = $this->montarParams($execucao, $operation, $recurso);

        try {
            $saida = $this->runner->executar(
                $execucao,
                'ecac-rs',
                $operation,
                $params,
                $mode,
                $certificado,
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
        $mensagemTecnica = data_get($saida, 'result.resultData.technicalMessage')
            ?? data_get($saida, 'result.technicalMessage');

        if (is_string($mensagemTecnica) && $mensagemTecnica !== ''
            && ($mensagem === null || str_contains(mb_strtolower((string) $mensagem), 'inesperado'))) {
            $mensagem = $mensagemTecnica;
        }

        // Eventos/artefatos já foram gravados ao vivo — evita duplicar.
        if ($statusRunner === 'succeeded') {
            $import = $this->importarExtratoSeHouver($execucao, $saida);

            return new ResultadoAutomacao(
                status: 'sucesso',
                mensagemUsuario: $import['mensagem']
                    ?? $mensagem
                    ?? 'Consulta e-CAC RS concluída.',
                quantidadeEncontrada: $import['encontrada']
                    ?? (int) ($saida['result']['resultData']['quantidade'] ?? 0),
                quantidadeImportada: $import['importada']
                    ?? (int) ($saida['result']['resultData']['quantidade'] ?? 0),
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
            mensagemUsuario: $mensagem ?: 'Falha na consulta ao e-CAC RS.',
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
        $loginPapel = $this->resolverLoginPapel($execucao);

        if ($operation !== 'extract-nfe-nfce') {
            return [
                'loginPapel' => $loginPapel,
            ];
        }

        $empresa = $execucao->empresa;
        $p = (array) ($execucao->parametros ?? []);

        $modelo = $p['modelo']
            ?? ($recurso === 'nfce_emitidas' ? 'nfce' : 'nfe');

        $ie = $this->somenteDigitos($p['ie'] ?? $empresa?->inscricao_estadual);
        $cnpj = $this->somenteDigitos($p['cnpj'] ?? $empresa?->cnpj);

        return [
            'loginPapel' => $loginPapel,
            'ie' => $ie !== '' ? $ie : null,
            'cnpj' => $cnpj !== '' ? $cnpj : null,
            'modelo' => $modelo,
            'periodoInicial' => $p['periodo_inicial']
                ?? optional($execucao->periodo_inicio)->format('Y-m-d'),
            'periodoFinal' => $p['periodo_final']
                ?? optional($execucao->periodo_fim)->format('Y-m-d'),
            'operacao' => $p['operacao'] ?? 'saida-consulente',
            'situacaoNormal' => array_key_exists('situacao_normal', $p)
                ? (bool) $p['situacao_normal']
                : true,
            'situacaoCancelada' => (bool) ($p['situacao_cancelada'] ?? false),
            'totalizadoPorMes' => (bool) ($p['totalizado_por_mes'] ?? false),
            'excluirVendaForaEstabelecimento' => (bool) ($p['excluir_venda_fora_estabelecimento'] ?? false),
        ];
    }

    /**
     * Papel na tela "Escolha através de qual opção do seu e-CNPJ...".
     * Certificado do escritório → Empresa Contábil; da empresa cliente → Responsável Legal.
     *
     * @return 'empresa-contabil'|'responsavel-legal'
     */
    private function resolverLoginPapel(AutomacaoExecucao $execucao): string
    {
        $p = (array) ($execucao->parametros ?? []);
        $override = $p['login_papel']
            ?? data_get($execucao->empresaIntegracao?->configuracoes, 'login_papel');

        if (is_string($override) && in_array($override, ['empresa-contabil', 'responsavel-legal'], true)) {
            return $override;
        }

        $cert = $execucao->empresaIntegracao?->certificadoDigital;
        if ($cert) {
            return $cert->loginPapelEcac();
        }

        return 'responsavel-legal';
    }

    private function somenteDigitos(mixed $valor): string
    {
        return preg_replace('/\D+/', '', (string) ($valor ?? '')) ?? '';
    }

    /**
     * Após extract bem-sucedido, importa o .txt do extrato para Análises fiscais.
     *
     * @param  array{status: string, result: array<string, mixed>}  $saida
     * @return array{mensagem?: string, encontrada?: int, importada?: int, ignorada?: int, coleta_id?: int}
     */
    private function importarExtratoSeHouver(AutomacaoExecucao $execucao, array $saida): array
    {
        $recurso = $execucao->portalRecurso?->codigo;
        if (!in_array($recurso, ['nfe_emitidas', 'nfce_emitidas'], true)) {
            return [];
        }

        $execucao->loadMissing(['empresa', 'artefatos']);

        $download = $execucao->artefatos
            ->filter(function (AutomacaoArtefato $a) {
                $nome = strtolower((string) $a->nome_original);

                return $a->tipo === 'download'
                    || str_ends_with($nome, '.txt')
                    || str_contains($nome, 'extrato');
            })
            ->sortByDesc('id')
            ->first();

        if (!$download) {
            $dir = storage_path('app/automacao-fiscal-runner/'.$execucao->uuid.'/artifacts/'.$execucao->uuid);
            $this->artefatos->persistirDiretorioRunner($execucao, $dir);
            $execucao->unsetRelation('artefatos');
            $download = $execucao->artefatos()
                ->where(function ($q) {
                    $q->where('tipo', 'download')
                        ->orWhere('nome_original', 'like', '%.txt')
                        ->orWhere('nome_original', 'like', '%Extrato%');
                })
                ->latest('id')
                ->first();
        }

        if (!$download) {
            $this->logs->registrar(
                $execucao,
                'warning',
                'Extrato concluído no portal, mas o arquivo .txt não foi persistido no IntegraExpert.',
                'DOWNLOAD_MISSING',
            );

            return [
                'mensagem' => 'Consulta concluída, mas o arquivo do extrato não foi gravado. Execute novamente.',
            ];
        }

        $caminho = $this->artefatos->caminhoAbsoluto($download);
        if (!$caminho || !is_readable($caminho)) {
            $this->logs->registrar(
                $execucao,
                'warning',
                'Arquivo do extrato não legível no storage.',
                'DOWNLOAD_UNREADABLE',
                ['artefato_id' => $download->id]
            );

            return [
                'mensagem' => 'Consulta concluída, mas não foi possível ler o extrato baixado.',
            ];
        }

        try {
            $resultado = app(ImportadorExtratoNfeService::class)->importarArquivo(
                $execucao->empresa,
                $caminho,
                $download->nome_original ?: 'ExtratoNFe.txt',
                $execucao->id
            );
        } catch (\Throwable $e) {
            $this->logs->registrar(
                $execucao,
                'error',
                'Falha ao importar extrato: '.$e->getMessage(),
                'IMPORT_FAILED',
            );

            return [
                'mensagem' => 'Extrato baixado, mas a importação para Análises fiscais falhou: '.$e->getMessage(),
            ];
        }

        $resumo = $resultado['resumo'] ?? [];
        $imp = is_array($resumo['importacao'] ?? null) ? $resumo['importacao'] : [];
        $novos = (int) ($imp['novos'] ?? 0);
        $atualizados = (int) ($imp['atualizados'] ?? 0);
        $ignorados = (int) ($imp['ignorados'] ?? 0);
        $totalDocs = (int) ($resultado['coleta']->quantidade_documentos ?? ($novos + $atualizados + $ignorados));

        $this->logs->registrar(
            $execucao,
            'info',
            "Extrato importado: {$novos} novos, {$atualizados} atualizados, {$ignorados} ignorados.",
            'IMPORT_FINISHED',
            ['coleta_id' => $resultado['coleta']->id ?? null, 'resumo' => $resumo]
        );

        return [
            'mensagem' => "Extrato importado: {$novos} novos, {$atualizados} atualizados.",
            'encontrada' => $totalDocs,
            'importada' => $novos + $atualizados,
            'ignorada' => $ignorados,
            'coleta_id' => $resultado['coleta']->id ?? null,
        ];
    }
}
