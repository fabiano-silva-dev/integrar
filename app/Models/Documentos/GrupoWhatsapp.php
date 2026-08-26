<?php

namespace App\Models\Documentos;

use App\Models\Concerns\BelongsToOperadora;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GrupoWhatsapp extends Model
{
    use BelongsToOperadora;

    protected $table = 'grupos_whatsapp';

    protected $fillable = [
        'empresa_operadora_id',
        'conexao_whatsapp_id',
        'empresa_id',
        'jid',
        'nome',
        'monitorar',
    ];

    protected function casts(): array
    {
        return [
            'monitorar' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (GrupoWhatsapp $grupo) {
            if ($grupo->empresa_id === null) {
                return;
            }

            if ($grupo->wasRecentlyCreated || $grupo->wasChanged('empresa_id')) {
                $grupo->empresas()->syncWithoutDetaching([
                    $grupo->empresa_id => ['empresa_operadora_id' => $grupo->empresa_operadora_id],
                ]);
            }
        });
    }

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(ConexaoWhatsapp::class, 'conexao_whatsapp_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'grupo_whatsapp_empresas', 'grupo_whatsapp_id', 'empresa_id')
            ->withTimestamps();
    }

    /**
     * @param  list<int|string|null>  $empresaIds
     */
    public function sincronizarEmpresas(array $empresaIds): void
    {
        $ids = collect($empresaIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $payload = [];
        foreach ($ids as $id) {
            $payload[$id] = ['empresa_operadora_id' => $this->empresa_operadora_id];
        }

        $this->empresas()->sync($payload);
        $this->unsetRelation('empresas');

        $this->update([
            'empresa_id' => $ids->first(),
            'monitorar' => $ids->isNotEmpty() ? $this->monitorar : false,
        ]);
    }

    /**
     * @return list<int>
     */
    public function idsEmpresas(): array
    {
        $ids = $this->relationLoaded('empresas')
            ? $this->empresas->pluck('id')
            : $this->empresas()->pluck('empresas.id');

        if ($this->empresa_id) {
            $ids->push($this->empresa_id);
        }

        return $ids
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function podeMonitorar(): bool
    {
        return $this->monitorar && $this->idsEmpresas() !== [];
    }
}
