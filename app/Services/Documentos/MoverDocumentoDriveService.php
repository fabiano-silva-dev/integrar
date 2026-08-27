<?php

namespace App\Services\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Documentos\ContaGoogle;
use App\Models\Documentos\DocumentoRecebido;
use App\Models\Documentos\EmpresaPastaDrive;
use App\Models\Empresa;
use Illuminate\Support\Facades\Log;

class MoverDocumentoDriveService
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly DocumentoProcessoLogService $logs,
    ) {}

    public function mover(
        DocumentoRecebido $documento,
        int $empresaDestinoId,
        TipoDocumentoRecebido $tipoDestino,
        int $ano,
    ): DocumentoRecebido {
        $documento = DocumentoRecebido::query()->findOrFail($documento->id);

        if ($documento->status !== StatusDocumentoRecebido::EnviadoDrive) {
            throw new \RuntimeException('Este arquivo não está no Drive.');
        }

        $conta = $this->contaDoEscritorio($documento);
        $empresa = Empresa::query()->find($empresaDestinoId);

        if ($empresa === null) {
            throw new \RuntimeException('Empresa de destino não encontrada.');
        }

        $this->drive->garantirEstruturaAno($conta, $empresa, $ano);
        $pasta = EmpresaPastaDrive::pastaTipo($empresa->id, $tipoDestino, $ano);

        if ($pasta === null) {
            throw new \RuntimeException('Pasta de destino no Drive não encontrada.');
        }

        if ($this->identificacaoPendente($documento)) {
            return $this->moverCopiaDeAtencao($documento, $conta, $empresa, $tipoDestino, $ano, $pasta);
        }

        $fileId = trim((string) ($documento->drive_file_id ?? ''));

        if ($fileId === '') {
            throw new \RuntimeException('Arquivo sem identificador no Drive.');
        }

        if (
            (int) $documento->empresa_id === (int) $empresa->id
            && $documento->tipo_documento === $tipoDestino
            && (int) $documento->ano === $ano
        ) {
            return $documento;
        }

        $movido = $this->drive->moverArquivo($conta, $fileId, $pasta->google_folder_id);
        $nome = $movido['name'] !== '' ? $movido['name'] : $documento->nome_original;

        $documento->update([
            'empresa_id' => $empresa->id,
            'tipo_documento' => $tipoDestino,
            'ano' => $ano,
            'drive_file_id' => $movido['id'],
            'drive_web_link' => $movido['link'],
            'drive_path' => $ano.'/'.$tipoDestino->pastaDrive().'/'.$nome,
            'tamanho_bytes' => $movido['size'] ?? $documento->tamanho_bytes,
            'erro_mensagem' => null,
        ]);
        $this->logs->doDocumento(
            $documento,
            'info',
            'mover',
            'Arquivo movido no Drive.',
            [
                'empresa_id' => $empresa->id,
                'tipo_documento' => $tipoDestino->value,
                'ano' => $ano,
                'drive_path' => $documento->drive_path,
            ],
        );

        return $documento->fresh() ?? $documento;
    }

    public function excluir(DocumentoRecebido $documento): DocumentoRecebido
    {
        $documento = DocumentoRecebido::query()->findOrFail($documento->id);

        if ($documento->status === StatusDocumentoRecebido::Excluido) {
            return $documento;
        }

        $conta = $this->contaDoEscritorio($documento);
        $ids = $this->idsDriveDoDocumento($documento);

        foreach ($ids as $fileId) {
            try {
                $this->drive->enviarParaLixeira($conta, $fileId);
            } catch (\Throwable $exception) {
                Log::warning('Google: não foi possível enviar arquivo à lixeira.', [
                    'documento_id' => $documento->id,
                    'file_id' => $fileId,
                    'erro' => $exception->getMessage(),
                ]);
                $this->logs->doDocumento(
                    $documento,
                    'aviso',
                    'excluir',
                    'Não foi possível enviar uma cópia à lixeira do Drive.',
                    ['file_id' => $fileId, 'erro' => $exception->getMessage()],
                );
            }
        }

        $documento->update([
            'status' => StatusDocumentoRecebido::Excluido,
            'erro_mensagem' => null,
        ]);
        $this->logs->doDocumento(
            $documento,
            'info',
            'excluir',
            'Arquivo enviado à lixeira do Drive e removido da lista.',
            ['file_ids' => $ids],
        );

        return $documento->fresh() ?? $documento;
    }

    private function moverCopiaDeAtencao(
        DocumentoRecebido $documento,
        ContaGoogle $conta,
        Empresa $empresa,
        TipoDocumentoRecebido $tipoDestino,
        int $ano,
        EmpresaPastaDrive $pasta,
    ): DocumentoRecebido {
        $copia = $this->copiaDaEmpresa($documento, (int) $empresa->id);

        if ($copia === null) {
            throw new \RuntimeException('Este arquivo não está na pasta Atenção dessa empresa.');
        }

        $fileId = trim((string) ($copia['drive_file_id'] ?? ''));

        if ($fileId === '') {
            throw new \RuntimeException('Cópia do arquivo sem identificador no Drive.');
        }

        $movido = $this->drive->moverArquivo($conta, $fileId, $pasta->google_folder_id);
        $nome = $movido['name'] !== '' ? $movido['name'] : $documento->nome_original;

        foreach ($this->idsDriveDoDocumento($documento) as $idCopia) {
            if ($idCopia === $fileId) {
                continue;
            }

            try {
                $this->drive->enviarParaLixeira($conta, $idCopia);
            } catch (\Throwable $exception) {
                Log::warning('Google: não foi possível enviar cópia da Atenção à lixeira.', [
                    'documento_id' => $documento->id,
                    'file_id' => $idCopia,
                    'erro' => $exception->getMessage(),
                ]);
            }
        }

        $metadados = $documento->metadados ?? [];
        unset($metadados['identificacao_pendente'], $metadados['copias_drive']);

        $documento->update([
            'empresa_id' => $empresa->id,
            'tipo_documento' => $tipoDestino,
            'ano' => $ano,
            'drive_file_id' => $movido['id'],
            'drive_web_link' => $movido['link'],
            'drive_path' => $ano.'/'.$tipoDestino->pastaDrive().'/'.$nome,
            'tamanho_bytes' => $movido['size'] ?? $documento->tamanho_bytes,
            'metadados' => $metadados,
            'erro_mensagem' => null,
        ]);
        $this->logs->doDocumento(
            $documento,
            'info',
            'mover',
            'Arquivo identificado e movido da pasta Atenção.',
            [
                'empresa_id' => $empresa->id,
                'tipo_documento' => $tipoDestino->value,
                'ano' => $ano,
            ],
        );

        return $documento->fresh() ?? $documento;
    }

    private function contaDoEscritorio(DocumentoRecebido $documento): ContaGoogle
    {
        $conta = ContaGoogle::query()->first()
            ?? ContaGoogle::withoutGlobalScope('operadora')
                ->where('empresa_operadora_id', $documento->empresa_operadora_id)
                ->first();

        if ($conta === null || ! $conta->conectada()) {
            throw new \RuntimeException('Conecte a conta Google do escritório.');
        }

        return $conta;
    }

    private function identificacaoPendente(DocumentoRecebido $documento): bool
    {
        return (bool) ($documento->metadados['identificacao_pendente'] ?? false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function copiaDaEmpresa(DocumentoRecebido $documento, int $empresaId): ?array
    {
        $copias = $documento->metadados['copias_drive'] ?? [];

        if (! is_array($copias)) {
            return null;
        }

        foreach ($copias as $copia) {
            if (is_array($copia) && (int) ($copia['empresa_id'] ?? 0) === $empresaId) {
                return $copia;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function idsDriveDoDocumento(DocumentoRecebido $documento): array
    {
        $ids = [];
        $principal = trim((string) ($documento->drive_file_id ?? ''));

        if ($principal !== '') {
            $ids[] = $principal;
        }

        $copias = $documento->metadados['copias_drive'] ?? [];

        if (is_array($copias)) {
            foreach ($copias as $copia) {
                if (! is_array($copia)) {
                    continue;
                }

                $id = trim((string) ($copia['drive_file_id'] ?? ''));

                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
