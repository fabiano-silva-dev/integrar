<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
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
    ) {}

    public function arquivar(DocumentoRecebido $documento, bool $forcar = false): DocumentoRecebido
    {
        $documento = DocumentoRecebido::withoutGlobalScope('operadora')->findOrFail($documento->id);

        if (! $forcar && in_array($documento->status, [
            StatusDocumentoRecebido::EnviadoDrive,
            StatusDocumentoRecebido::Ignorado,
        ], true)) {
            return $documento;
        }

        if ($documento->storage_path === null || ! Storage::exists($documento->storage_path)) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Erro,
                'erro_mensagem' => 'Arquivo local não encontrado.',
            ]);

            return $documento->fresh() ?? $documento;
        }

        $conteudo = Storage::get($documento->storage_path) ?? '';
        $caminho = $this->caminhoAbsoluto($documento->storage_path);
        $fallback = $this->dataFallback($documento);
        $mime = (string) $documento->mime;
        $ehPdf = str_contains(strtolower($mime), 'pdf') || str_starts_with($conteudo, '%PDF');

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
                }
            }
        }

        $nomeUpload = $documento->nome_original;
        $tipoUsuario = $forcar ? null : $documento->tipo_documento;

        if (! $classificacao['conclusivo'] && $tipoUsuario === null) {
            $ia = $this->analisadorIa->analisar(
                $documento->empresa_operadora_id,
                $conteudo,
                $mime !== '' ? $mime : ($ehPdf ? 'application/pdf' : 'application/octet-stream'),
                $documento->nome_original,
                $textoPdf !== '' ? $textoPdf : null,
            );

            if ($ia !== null) {
                $mapeado = $this->mapeadorIa->mapear($ia['saida'], $fallback);
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

        $documento = $documento->fresh() ?? $documento;
        $grupo = $documento->grupo()->withoutGlobalScope('operadora')->first();

        if ($documento->empresa_id === null) {
            $candidatas = $this->resolverEmpresa->candidatasDoGrupo($grupo);
            $resolvida = $this->resolverEmpresa->resolver($documento, $conteudo, $candidatas);

            if ($resolvida !== null) {
                $documento->update(['empresa_id' => $resolvida->id]);
                $documento = $documento->fresh() ?? $documento;
            }
        }

        if ($documento->empresa_id === null) {
            $grupoTemEmpresas = $grupo !== null && $grupo->idsEmpresas() !== [];

            $documento->update([
                'status' => StatusDocumentoRecebido::Pendente,
                'erro_mensagem' => $grupoTemEmpresas
                    ? 'Indique a empresa deste documento.'
                    : 'Vincule o grupo a uma empresa para arquivar.',
            ]);

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

            return $documento->fresh() ?? $documento;
        }

        $empresa = $documento->empresa()->withoutGlobalScope('operadora')->first();

        if ($empresa === null) {
            $documento->update([
                'status' => StatusDocumentoRecebido::Erro,
                'erro_mensagem' => 'Empresa não encontrada.',
            ]);

            return $documento->fresh() ?? $documento;
        }

        try {
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

            return $documento->fresh() ?? $documento;
        }

        $documento->update([
            'status' => StatusDocumentoRecebido::EnviadoDrive,
            'drive_file_id' => $enviado['id'],
            'drive_web_link' => $enviado['link'],
            'drive_path' => $enviado['path'],
            'erro_mensagem' => null,
        ]);

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
