<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Empresa;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;

class ArquivarDocumentoService
{
    public function __construct(
        private readonly ClassificadorDocumentoService $classificador,
        private readonly GoogleDriveService $drive,
        private readonly LlamaParseAdaptador $llamaParse,
        private readonly AnalisadorDocumentoIaService $analisadorIa,
        private readonly MapeadorTipoDocumentoIa $mapeadorIa,
        private readonly ResolverEmpresaDocumentoService $resolverEmpresa,
        private readonly DocumentoProcessoLogService $logs,
    ) {}

    public function arquivar(DocumentoRecebido $documento, bool $forcar = false): DocumentoRecebido
    {
        $documento = DocumentoRecebido::withoutGlobalScope('operadora')->findOrFail($documento->id);

        if (! $forcar && in_array($documento->status, [
            StatusDocumentoRecebido::EnviadoDrive,
            StatusDocumentoRecebido::Ignorado,
            StatusDocumentoRecebido::Excluido,
        ], true)) {
            $this->logs->doDocumento(
                $documento,
                'info',
                'classificar',
                'Arquivo já processado — nada a fazer.',
            );

            return $documento;
        }

        if ($documento->storage_path === null || ! Storage::exists($documento->storage_path)) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Erro,
                'erro_mensagem' => 'Arquivo local não encontrado.',
            ]);
            $this->logs->doDocumento(
                $documento,
                'erro',
                'erro',
                'Arquivo local não encontrado.',
            );

            return $documento->fresh() ?? $documento;
        }

        $conteudo = Storage::get($documento->storage_path) ?? '';
        $caminho = $this->caminhoAbsoluto($documento->storage_path);
        $fallback = $this->dataFallback($documento);
        $mime = (string) $documento->mime;
        $ehPdf = str_contains(strtolower($mime), 'pdf') || str_starts_with($conteudo, '%PDF');
        $grupo = $documento->grupo()->withoutGlobalScope('operadora')->first();
        $candidatas = $this->resolverEmpresa->candidatasDoGrupo($grupo);

        $classificacao = $this->classificador->classificar(
            $documento->nome_original,
            $documento->mime,
            $conteudo,
            $fallback,
            $caminho,
        );

        $textoPdf = '';

        if (! $classificacao['conclusivo'] && $ehPdf) {
            $markdown = $this->llamaParse->extrairMarkdown(
                $documento->empresa_operadora_id,
                $conteudo,
                $documento->nome_original,
            );

            if (is_string($markdown) && trim($markdown) !== '') {
                $textoPdf = $markdown;
                $peloMarkdown = $this->classificador->classificarTextoDocumento($markdown, $fallback);

                if ($peloMarkdown['conclusivo']) {
                    $classificacao = $peloMarkdown;
                    $classificacao['metadados']['origem'] = 'llamaparse';
                    $this->logs->doDocumento(
                        $documento,
                        'info',
                        'llamaparse',
                        'PDF lido e classificado pela extração de texto.',
                    );
                }
            } else {
                $this->logs->doDocumento(
                    $documento,
                    'aviso',
                    'llamaparse',
                    'Não foi possível extrair texto do PDF.',
                );
            }
        }

        $nomeUpload = $documento->nome_original;
        $tipoUsuario = $forcar ? null : $documento->tipo_documento;
        $precisaIaTipo = ! $classificacao['conclusivo'] && $tipoUsuario === null;
        $precisaIaEmpresa = $documento->empresa_id === null && $candidatas->count() > 1;

        if ($precisaIaTipo || $precisaIaEmpresa) {
            $textoParaIa = $textoPdf !== '' ? $textoPdf : null;

            if ($textoParaIa === null && ! $ehPdf && ! str_starts_with(strtolower($mime), 'image/')) {
                $bruto = trim($conteudo);
                if ($bruto !== '' && ! str_contains($bruto, "\0")) {
                    $textoParaIa = mb_substr($bruto, 0, 20000);
                }
            }

            $ia = $this->analisadorIa->analisar(
                $documento->empresa_operadora_id,
                $conteudo,
                $mime !== '' ? $mime : ($ehPdf ? 'application/pdf' : 'application/octet-stream'),
                $documento->nome_original,
                $textoParaIa,
                $candidatas,
            );

            $nomerPasta = new NomePastaDriveEmpresa;
            $contextoIa = [
                'modelo_ia' => $ia['modelo'],
                'origem' => $ia['origem'],
                'prompt' => $ia['prompt'],
                'resposta_ia' => $ia['resposta'],
                'empresas_grupo' => $candidatas->map(fn ($empresa) => [
                    'id' => $empresa->id,
                    'cnpj' => $empresa->cnpj,
                    'razao_social' => $empresa->razao_social,
                    'nome_fantasia' => $empresa->nome_fantasia,
                    'nome' => $empresa->nome,
                    'pasta_drive' => $nomerPasta->sugerir($empresa),
                ])->values()->all(),
            ];

            if ($ia['saida'] !== null) {
                $mapeado = $this->mapeadorIa->mapear($ia['saida'], $fallback);

                if ($precisaIaTipo) {
                    $classificacao['tipo'] = $mapeado['tipo'];
                    $classificacao['ano'] = $mapeado['ano'];
                    $classificacao['data'] = $mapeado['data'];
                    $classificacao['conclusivo'] = true;
                    $classificacao['metadados'] = array_merge($classificacao['metadados'], $mapeado['metadados'], [
                        'origem' => $ia['origem'],
                        'modelo_ia' => $ia['modelo'],
                        'sugestao_nome_arquivo' => $mapeado['nome'],
                    ]);

                    if ($mapeado['nome'] !== null) {
                        $nomeUpload = $this->sanitizarNome($mapeado['nome']);
                    }
                } else {
                    $classificacao['metadados'] = array_merge($classificacao['metadados'], [
                        'empresa_id' => $mapeado['metadados']['empresa_id'] ?? null,
                        'empresa_cnpj' => $mapeado['metadados']['empresa_cnpj'] ?? null,
                        'empresa_razao_social' => $mapeado['metadados']['empresa_razao_social'] ?? null,
                        'modelo_ia' => $ia['modelo'],
                    ]);
                }

                $this->logs->doDocumento(
                    $documento,
                    'info',
                    'ia',
                    $precisaIaTipo
                        ? 'Classificado pela IA ('.$ia['origem'].').'
                        : 'IA consultada para identificar a empresa ('.$ia['origem'].').',
                    array_merge($contextoIa, [
                        'tipo' => ($precisaIaTipo ? $mapeado['tipo'] : $classificacao['tipo'])?->value,
                        'empresa_id_ia' => $mapeado['metadados']['empresa_id'] ?? null,
                        'empresa_cnpj_ia' => $mapeado['metadados']['empresa_cnpj'] ?? null,
                        'empresa_razao_social_ia' => $mapeado['metadados']['empresa_razao_social'] ?? null,
                    ]),
                );
            } else {
                $this->logs->doDocumento(
                    $documento,
                    'aviso',
                    'ia',
                    'IA não classificou o arquivo.',
                    $contextoIa,
                );
            }
        }

        if ($classificacao['tipo'] === null) {
            $classificacao['tipo'] = TipoDocumentoRecebido::Outros;
            $classificacao['metadados']['origem'] = $classificacao['metadados']['origem'] ?? 'outros';
        }

        $tipo = $tipoUsuario ?? $classificacao['tipo'];
        $ano = $documento->ano && ! $forcar ? $documento->ano : $classificacao['ano'];
        $metadados = array_merge($documento->metadados ?? [], $classificacao['metadados']);

        $documento->update([
            'tipo_documento' => $tipo,
            'ano' => $ano,
            'data_documento' => $classificacao['data'],
            'metadados' => $metadados,
            'status' => StatusDocumentoRecebido::Classificado,
            'erro_mensagem' => null,
        ]);

        $this->logs->doDocumento(
            $documento,
            'info',
            'classificar',
            'Arquivo classificado como '.($tipo instanceof TipoDocumentoRecebido ? $tipo->rotulo() : (string) $tipo).'.',
            [
                'ano' => $ano,
                'origem' => $metadados['origem'] ?? null,
            ],
        );

        $documento = $documento->fresh() ?? $documento;

        if ($documento->empresa_id === null) {
            $resolvida = $this->resolverEmpresa->resolver($documento, $conteudo, $candidatas);

            if ($resolvida !== null) {
                $documento->update(['empresa_id' => $resolvida->id]);
                $documento = $documento->fresh() ?? $documento;
                $this->logs->doDocumento(
                    $documento,
                    'info',
                    'classificar',
                    'Empresa identificada: '.($resolvida->razao_social ?: $resolvida->nome_fantasia ?: $resolvida->nome),
                    ['empresa_id' => $resolvida->id, 'cnpj' => $resolvida->cnpj],
                );
            }
        }

        if ($documento->empresa_id === null) {
            $grupoTemEmpresas = $grupo !== null && $grupo->idsEmpresas() !== [];

            if ($candidatas->count() > 1) {
                return $this->arquivarNasPastasDeAtencao(
                    $documento,
                    $candidatas,
                    $tipo instanceof TipoDocumentoRecebido ? $tipo : TipoDocumentoRecebido::from((string) $tipo),
                    (int) $ano,
                    $nomeUpload,
                    $conteudo,
                );
            }

            $documento->update([
                'status' => StatusDocumentoRecebido::Pendente,
                'erro_mensagem' => $grupoTemEmpresas
                    ? 'Indique a empresa deste documento.'
                    : 'Vincule o grupo a uma empresa para arquivar.',
            ]);
            $this->logs->doDocumento(
                $documento,
                'aviso',
                'pendente',
                $grupoTemEmpresas
                    ? 'Indique a empresa deste documento.'
                    : 'Vincule o grupo a uma empresa para arquivar.',
            );

            return $documento->fresh() ?? $documento;
        }

        $conta = ContaGoogle::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $documento->empresa_operadora_id)
            ->first();

        if ($conta === null || ! $conta->conectada()) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Pendente,
                'erro_mensagem' => 'Conecte a conta Google do escritório.',
            ]);
            $this->logs->doDocumento(
                $documento,
                'aviso',
                'pendente',
                'Conecte a conta Google do escritório.',
            );

            return $documento->fresh() ?? $documento;
        }

        $raiz = EmpresaPastaDrive::withoutGlobalScope('operadora')
            ->where('empresa_id', $documento->empresa_id)
            ->where('tipo', EmpresaPastaDrive::TIPO_RAIZ)
            ->first();

        if ($raiz === null) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Pendente,
                'erro_mensagem' => 'Defina a pasta raiz do Drive desta empresa.',
            ]);
            $this->logs->doDocumento(
                $documento,
                'aviso',
                'pendente',
                'Defina a pasta raiz do Drive desta empresa.',
            );

            return $documento->fresh() ?? $documento;
        }

        $empresa = $documento->empresa()->withoutGlobalScope('operadora')->first();

        if ($empresa === null) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Erro,
                'erro_mensagem' => 'Empresa não encontrada.',
            ]);
            $this->logs->doDocumento(
                $documento,
                'erro',
                'erro',
                'Empresa não encontrada.',
            );

            return $documento->fresh() ?? $documento;
        }

        try {
            $this->logs->doDocumento(
                $documento,
                'info',
                'drive',
                'Enviando arquivo ao Google Drive.',
                ['pasta_ano' => $ano],
            );
            $enviado = $this->drive->enviarArquivo(
                $conta,
                $empresa,
                $tipo instanceof TipoDocumentoRecebido ? $tipo : TipoDocumentoRecebido::from((string) $tipo),
                (int) $ano,
                $nomeUpload,
                $conteudo,
                $documento->mime,
            );
        } catch (\Throwable $exception) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Erro,
                'erro_mensagem' => $exception->getMessage(),
            ]);
            $this->logs->doDocumento(
                $documento,
                'erro',
                'erro',
                'Falha no Google Drive: '.$exception->getMessage(),
            );

            return $documento->fresh() ?? $documento;
        }

        $documento->update([
            'status' => StatusDocumentoRecebido::EnviadoDrive,
            'drive_file_id' => $enviado['id'],
            'drive_web_link' => $enviado['link'],
            'drive_path' => $enviado['path'],
            'tamanho_bytes' => $enviado['size'] ?? $documento->tamanho_bytes ?? strlen($conteudo),
            'erro_mensagem' => null,
        ]);
        $this->logs->doDocumento(
            $documento,
            'info',
            'enviado_drive',
            'Arquivo gravado no Drive.',
            [
                'drive_path' => $enviado['path'],
                'drive_link' => $enviado['link'],
            ],
        );

        return $documento->fresh() ?? $documento;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Empresa>  $candidatas
     */
    private function arquivarNasPastasDeAtencao(
        DocumentoRecebido $documento,
        $candidatas,
        TipoDocumentoRecebido $tipoClassificado,
        int $ano,
        string $nomeUpload,
        string $conteudo,
    ): DocumentoRecebido {
        $conta = ContaGoogle::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $documento->empresa_operadora_id)
            ->first();

        if ($conta === null || ! $conta->conectada()) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Pendente,
                'erro_mensagem' => 'Conecte a conta Google do escritório.',
            ]);
            $this->logs->doDocumento(
                $documento,
                'aviso',
                'pendente',
                'Conecte a conta Google do escritório.',
            );

            return $documento->fresh() ?? $documento;
        }

        $copias = [];
        $falhas = [];

        foreach ($candidatas as $empresa) {
            if (! $empresa instanceof Empresa) {
                continue;
            }

            $raiz = EmpresaPastaDrive::withoutGlobalScope('operadora')
                ->where('empresa_id', $empresa->id)
                ->where('tipo', EmpresaPastaDrive::TIPO_RAIZ)
                ->first();

            if ($raiz === null) {
                $falhas[] = ($empresa->razao_social ?: $empresa->nome).': sem pasta raiz no Drive';

                continue;
            }

            try {
                $enviado = $this->drive->enviarArquivo(
                    $conta,
                    $empresa,
                    TipoDocumentoRecebido::AtencaoIdentificarEmpresa,
                    $ano,
                    $nomeUpload,
                    $conteudo,
                    $documento->mime,
                );
                $copias[] = [
                    'empresa_id' => $empresa->id,
                    'empresa_nome' => $empresa->razao_social ?: $empresa->nome_fantasia ?: $empresa->nome,
                    'drive_file_id' => $enviado['id'],
                    'drive_path' => $enviado['path'],
                    'drive_link' => $enviado['link'],
                ];
            } catch (\Throwable $exception) {
                $falhas[] = ($empresa->razao_social ?: $empresa->nome).': '.$exception->getMessage();
            }
        }

        if ($copias === []) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Pendente,
                'erro_mensagem' => $falhas !== []
                    ? implode(' ', $falhas)
                    : 'Defina a pasta raiz do Drive das empresas do grupo.',
            ]);
            $this->logs->doDocumento(
                $documento,
                'aviso',
                'pendente',
                'Não foi possível gravar na pasta Atenção das empresas do grupo.',
                ['falhas' => $falhas],
            );

            return $documento->fresh() ?? $documento;
        }

        $primeira = $copias[0];
        $nomes = implode(', ', array_column($copias, 'empresa_nome'));
        $metadados = array_merge($documento->metadados ?? [], [
            'identificacao_pendente' => true,
            'tipo_classificado' => $tipoClassificado->value,
            'copias_drive' => $copias,
        ]);

        $documento->update([
            'status' => StatusDocumentoRecebido::EnviadoDrive,
            'drive_file_id' => $primeira['drive_file_id'],
            'drive_web_link' => $primeira['drive_link'],
            'drive_path' => $primeira['drive_path'],
            'tamanho_bytes' => $documento->tamanho_bytes ?? strlen($conteudo),
            'metadados' => $metadados,
            'erro_mensagem' => $falhas !== [] ? implode(' ', $falhas) : null,
        ]);
        $this->logs->doDocumento(
            $documento,
            'info',
            'enviado_drive',
            'Arquivo gravado na pasta Atenção - identificar a empresa de: '.$nomes.'.',
            [
                'copias_drive' => $copias,
                'falhas' => $falhas,
            ],
        );

        return $documento->fresh() ?? $documento;
    }

    private function caminhoAbsoluto(string $storagePath): ?string
    {
        try {
            $caminho = Storage::path($storagePath);

            return is_string($caminho) && is_file($caminho) ? $caminho : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizarNome(string $nome): string
    {
        $nome = basename(str_replace(["\0", '/'], '', $nome));
        $nome = preg_replace('/[^\w.\-\(\) áàâãéêíóôõúçÁÀÂÃÉÊÍÓÔÕÚÇ]+/u', '_', $nome) ?? $nome;

        return mb_substr($nome !== '' ? $nome : 'documento', 0, 180);
    }

    private function dataFallback(DocumentoRecebido $documento): DateTimeImmutable
    {
        $timestamp = $documento->metadados['timestamp'] ?? null;

        if (is_numeric($timestamp)) {
            return (new DateTimeImmutable())->setTimestamp((int) $timestamp);
        }

        return new DateTimeImmutable($documento->created_at?->toDateTimeString() ?? 'now');
    }
}
