<?php

namespace App\Models\Documentos;

use App\Enums\Documentos\StatusDocumentoRecebido;
use App\Enums\Documentos\TipoDocumentoRecebido;
use App\Models\Concerns\BelongsToOperadora;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoRecebido extends Model
{
    use BelongsToOperadora;

    protected $table = 'documentos_recebidos';

    protected $fillable = [
        'empresa_operadora_id',
        'empresa_id',
        'conexao_whatsapp_id',
        'grupo_whatsapp_id',
        'mensagem_whatsapp_id',
        'nome_original',
        'mime',
        'hash_sha256',
        'tipo_documento',
        'ano',
        'status',
        'storage_path',
        'tamanho_bytes',
        'drive_file_id',
        'drive_web_link',
        'drive_path',
        'data_documento',
        'metadados',
        'erro_mensagem',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusDocumentoRecebido::class,
            'tipo_documento' => TipoDocumentoRecebido::class,
            'ano' => 'integer',
            'tamanho_bytes' => 'integer',
            'data_documento' => 'date',
            'metadados' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoWhatsapp::class, 'grupo_whatsapp_id');
    }

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(ConexaoWhatsapp::class, 'conexao_whatsapp_id');
    }

    public static function formatarTamanho(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            $kb = $bytes / 1024;

            if (abs($kb - round($kb)) < 0.05) {
                return ((int) round($kb)).' KB';
            }

            return number_format($kb, 1, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    }

    public function urlDrive(): ?string
    {
        $link = trim((string) ($this->drive_web_link ?? ''));

        if ($link !== '') {
            return $link;
        }

        if (is_string($this->drive_file_id) && $this->drive_file_id !== '') {
            return EmpresaPastaDrive::urlArquivo($this->drive_file_id);
        }

        return null;
    }
}
