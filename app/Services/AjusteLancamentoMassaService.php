<?php

namespace App\Services;

use App\Models\AjusteLancamentoItem;
use App\Models\AjusteLancamentoLote;
use App\Models\AlteracaoLog;
use App\Models\Lancamento;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AjusteLancamentoMassaService
{
    /**
     * Restaura os valores anteriores do lote.
     * Campos alterados depois do ajuste (valor atual != valor_novo) são pulados.
     *
     * @return array{revertidos:int, pulados:int, lancamentos:int}
     */
    public function reverter(AjusteLancamentoLote $lote): array
    {
        if (!$lote->estaAplicado()) {
            throw new RuntimeException('Este ajuste já foi revertido.');
        }

        $usuario = Auth::user();
        $usuarioNome = $usuario?->name ?? 'Sistema';
        $revertidos = 0;
        $pulados = 0;
        $lancamentosAfetados = [];

        DB::transaction(function () use ($lote, $usuario, $usuarioNome, &$revertidos, &$pulados, &$lancamentosAfetados) {
            $lote->itens()
                ->orderBy('id')
                ->chunkById(200, function ($itens) use (&$revertidos, &$pulados, &$lancamentosAfetados, $usuarioNome) {
                    $lancamentos = Lancamento::query()
                        ->whereIn('id', $itens->pluck('lancamento_id')->unique()->all())
                        ->get()
                        ->keyBy('id');

                    foreach ($itens as $item) {
                        /** @var AjusteLancamentoItem $item */
                        $lancamento = $lancamentos->get($item->lancamento_id);
                        if (!$lancamento) {
                            $pulados++;
                            continue;
                        }

                        $campo = $item->campo_alterado;
                        if (!in_array($campo, $lancamento->getFillable(), true)) {
                            $pulados++;
                            continue;
                        }

                        $atual = $this->normalizarComparacao($lancamento->{$campo});
                        $esperado = $this->normalizarComparacao($item->valor_novo);

                        if ($atual !== $esperado) {
                            $pulados++;
                            continue;
                        }

                        $valorAnterior = $lancamento->{$campo};
                        $lancamento->{$campo} = $item->valor_anterior;
                        $lancamento->save();

                        AlteracaoLog::create([
                            'lancamento_id' => $lancamento->id,
                            'campo_alterado' => $campo,
                            'valor_anterior' => $valorAnterior,
                            'valor_novo' => $item->valor_anterior,
                            'tipo_alteracao' => $item->tipo_alteracao === 'conta' ? 'conta' : 'outro',
                            'data_alteracao' => now(),
                        ]);

                        $revertidos++;
                        $lancamentosAfetados[$lancamento->id] = true;
                    }
                });

            $lote->update([
                'status' => AjusteLancamentoLote::STATUS_REVERTIDO,
                'revertido_em' => now(),
                'revertido_por_user_id' => $usuario?->id,
                'revertido_por_nome' => $usuarioNome,
            ]);
        });

        return [
            'revertidos' => $revertidos,
            'pulados' => $pulados,
            'lancamentos' => count($lancamentosAfetados),
        ];
    }

    private function normalizarComparacao(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        return (string) $valor;
    }
}
