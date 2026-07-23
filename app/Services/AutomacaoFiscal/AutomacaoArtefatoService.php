<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\AutomacaoArtefato;
use App\Models\AutomacaoConfiguracao;
use App\Models\AutomacaoExecucao;
use App\Services\AutomacaoFiscal\Logs\AutomacaoLogService;
use App\Services\OperadoraStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AutomacaoArtefatoService
{
    public function __construct(private readonly AutomacaoLogService $logs)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @return list<AutomacaoArtefato>
     */
    public function persistirDoRunner(AutomacaoExecucao $execucao, array $artifacts): array
    {
        $salvos = [];

        foreach ($artifacts as $artifact) {
            $tipo = (string) ($artifact['artifactType'] ?? $artifact['type'] ?? 'download');
            $nome = basename((string) ($artifact['filename'] ?? ($tipo . '-' . Str::random(6))));
            $mime = (string) ($artifact['mimeType'] ?? $artifact['mime_type'] ?? 'application/octet-stream');
            $sha = isset($artifact['sha256']) ? (string) $artifact['sha256'] : null;

            $conteudo = null;
            if (!empty($artifact['contentBase64'])) {
                $conteudo = base64_decode((string) $artifact['contentBase64'], true);
            } elseif (!empty($artifact['absolutePath']) && is_readable((string) $artifact['absolutePath'])) {
                $conteudo = File::get((string) $artifact['absolutePath']);
            }

            if ($conteudo === null || $conteudo === false || $conteudo === '') {
                continue;
            }

            $sha = $sha ?: hash('sha256', $conteudo);
            $jaExiste = AutomacaoArtefato::query()
                ->where('automacao_execucao_id', $execucao->id)
                ->where('hash_sha256', $sha)
                ->exists();
            if ($jaExiste) {
                continue;
            }

            $salvos[] = $this->gravar($execucao, $tipo, $nome, $conteudo, $mime, $sha, [
                'origem' => 'runner',
            ]);
        }

        return $salvos;
    }

    public function gravar(
        AutomacaoExecucao $execucao,
        string $tipo,
        string $nomeOriginal,
        string $conteudo,
        string $mimeType,
        ?string $sha256 = null,
        array $metadados = []
    ): AutomacaoArtefato {
        $nomeSeguro = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($nomeOriginal)) ?: ($tipo . '.bin');
        $subdir = 'automacao-fiscal/artefatos/' . $execucao->uuid;
        $relative = OperadoraStorage::put($subdir, $nomeSeguro, $conteudo, $execucao->empresa_operadora_id);

        $dias = 30;
        try {
            $dias = (int) (AutomacaoConfiguracao::withoutGlobalScope('operadora')
                ->where('empresa_operadora_id', $execucao->empresa_operadora_id)
                ->value('retencao_artefatos_dias') ?? 30);
        } catch (\Throwable) {
            $dias = 30;
        }

        $artefato = AutomacaoArtefato::create([
            'empresa_operadora_id' => $execucao->empresa_operadora_id,
            'automacao_execucao_id' => $execucao->id,
            'tipo' => $tipo,
            'nome_original' => $nomeSeguro,
            'storage_path' => $relative,
            'mime_type' => $mimeType,
            'tamanho' => strlen($conteudo),
            'hash_sha256' => $sha256 ?: hash('sha256', $conteudo),
            'metadados' => $metadados,
            'retencao_ate' => now()->addDays((int) $dias),
        ]);

        if ($tipo === 'screenshot' || str_starts_with($mimeType, 'image/')) {
            $this->logs->registrar(
                $execucao,
                'info',
                'Screenshot salvo: ' . $nomeSeguro,
                'SCREENSHOT_SAVED',
                ['artefato_id' => $artefato->id, 'filename' => $nomeSeguro]
            );
        }

        return $artefato;
    }

    public function caminhoAbsoluto(AutomacaoArtefato $artefato): ?string
    {
        if (!$artefato->storage_path || !Storage::exists($artefato->storage_path)) {
            return null;
        }

        return Storage::path($artefato->storage_path);
    }

    public function ehImagem(AutomacaoArtefato $artefato): bool
    {
        return $artefato->tipo === 'screenshot'
            || str_starts_with((string) $artefato->mime_type, 'image/');
    }

    /**
     * Persiste arquivos remanescentes do diretório de artefatos do runner (sem depender do NDJSON).
     *
     * @return list<AutomacaoArtefato>
     */
    public function persistirDiretorioRunner(AutomacaoExecucao $execucao, string $dir): array
    {
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $artifacts = [];
        foreach (File::files($dir) as $file) {
            $nome = $file->getFilename();
            $ext = strtolower($file->getExtension());
            $tipo = match (true) {
                in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) => 'screenshot',
                $ext === 'zip' => 'trace',
                $ext === 'json' => 'diagnostic-log',
                default => 'download',
            };
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'zip' => 'application/zip',
                'json' => 'application/json',
                'txt', 'csv' => 'text/plain',
                default => 'application/octet-stream',
            };
            $artifacts[] = [
                'artifactType' => $tipo,
                'filename' => $nome,
                'absolutePath' => $file->getPathname(),
                'mimeType' => $mime,
                'sha256' => hash_file('sha256', $file->getPathname()) ?: null,
            ];
        }

        return $this->persistirDoRunner($execucao, $artifacts);
    }

    /**
     * PNG placeholder legível para o modo simulado (sem runner Node).
     */
    public function gerarPngSimulado(string $titulo = 'Simulação'): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true
            ) ?: '';
        }

        $largura = 640;
        $altura = 360;
        $img = imagecreatetruecolor($largura, $altura);
        $fundo = imagecolorallocate($img, 15, 23, 42);
        $texto = imagecolorallocate($img, 226, 232, 240);
        $accent = imagecolorallocate($img, 56, 189, 248);
        imagefilledrectangle($img, 0, 0, $largura, $altura, $fundo);
        imagefilledrectangle($img, 0, 0, $largura, 6, $accent);

        $linhas = [
            'Automação Fiscal — modo simulado',
            $titulo,
            now()->format('d/m/Y H:i:s'),
        ];
        $y = 140;
        foreach ($linhas as $linha) {
            imagestring($img, 5, 40, $y, $linha, $texto);
            $y += 28;
        }

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
