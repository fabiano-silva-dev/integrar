<?php

namespace App\Models\Documentos;

use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Concerns\BelongsToOperadora;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaPastaDrive extends Model
{
    use BelongsToOperadora;

    public const TIPO_RAIZ = 'raiz';

    public const ANO_RAIZ = 0;

    protected $table = 'empresa_pastas_drive';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'tipo',
        'ano',
        'google_folder_id',
        'google_folder_nome',
        'google_web_link',
    ];

    protected function casts(): array
    {
        return [
            'ano' => 'integer',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function ehRaiz(): bool
    {
        return $this->tipo === self::TIPO_RAIZ;
    }

    public function ehAno(): bool
    {
        return str_starts_with((string) $this->tipo, 'ano-');
    }

    public function urlDrive(): string
    {
        $link = trim((string) ($this->google_web_link ?? ''));

        return $link !== '' ? $link : self::urlPasta((string) $this->google_folder_id);
    }

    public static function urlPasta(string $folderId): string
    {
        return 'https://drive.google.com/drive/folders/'.$folderId;
    }

    public static function urlArquivo(string $fileId): string
    {
        return 'https://drive.google.com/file/d/'.$fileId.'/view';
    }

    public static function tipoAno(int $ano): string
    {
        return 'ano-'.$ano;
    }

    public static function raizDaEmpresa(int $empresaId): ?self
    {
        return static::query()
            ->where('empresa_id', $empresaId)
            ->where('tipo', self::TIPO_RAIZ)
            ->where('ano', self::ANO_RAIZ)
            ->first();
    }

    public static function pastaTipo(int $empresaId, TipoDocumentoRecebido $tipo, int $ano): ?self
    {
        return static::query()
            ->where('empresa_id', $empresaId)
            ->where('tipo', $tipo->value)
            ->where('ano', $ano)
            ->first();
    }
}
