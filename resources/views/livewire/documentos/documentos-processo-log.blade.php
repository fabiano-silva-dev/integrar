<div class="p-6">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Configurações — Documentos</h1>
                <p class="text-sm text-gray-600 mt-1">Acompanhe o caminho de cada arquivo, do WhatsApp até o Drive.</p>
            </div>
            <div class="p-6">
                <x-documentos-nav ativo="log" />

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para ver o registro.
                    </div>
                @else
                    @if ($debugAtivo)
                        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded mb-4 text-sm">
                            Registro ativo. Novas mensagens e envios ao Drive aparecem nesta lista.
                        </div>
                    @else
                        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded mb-4 text-sm">
                            Registro desligado. Defina DOCUMENTOS_DEBUG=true no .env para gravar o fluxo.
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-3 mb-4">
                        <input type="text" wire:model.live.debounce.400ms="busca" placeholder="Buscar mensagem, grupo ou arquivo"
                               class="border-gray-300 rounded-md flex-1 min-w-[180px]">
                        <select wire:model.live="filtroEtapa" class="border-gray-300 rounded-md">
                            <option value="">Todas as etapas</option>
                            @foreach ($etapas as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="filtroNivel" class="border-gray-300 rounded-md">
                            <option value="">Todos os níveis</option>
                            @foreach ($niveis as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="limpar" wire:confirm="Apagar o registro deste escritório?"
                                class="h-10 px-4 rounded-xl border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-semibold">
                            Limpar registro
                        </button>
                    </div>

                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left px-3 py-2">Data</th>
                                    <th class="text-left px-3 py-2">Nível</th>
                                    <th class="text-left px-3 py-2">Etapa</th>
                                    <th class="text-left px-3 py-2">Mensagem</th>
                                    <th class="text-left px-3 py-2">Grupo / arquivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($logs as $log)
                                    <tr class="border-t align-top" wire:key="log-{{ $log->id }}">
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-500">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                                        <td class="px-3 py-2">
                                            <span @class([
                                                'inline-flex px-2 py-0.5 rounded text-xs font-medium',
                                                'bg-red-100 text-red-800' => $log->nivel === 'erro',
                                                'bg-amber-100 text-amber-800' => $log->nivel === 'aviso',
                                                'bg-gray-100 text-gray-700' => $log->nivel === 'info',
                                            ])>{{ $log->rotuloNivel() }}</span>
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $log->rotuloEtapa() }}</td>
                                        <td class="px-3 py-2">
                                            <div>{{ $log->mensagem }}</div>
                                            @php
                                                $ctx = is_array($log->contexto) ? $log->contexto : [];
                                                $promptIa = $ctx['prompt'] ?? null;
                                                $respostaIa = $ctx['resposta_ia'] ?? $ctx['resposta'] ?? null;
                                                $restoCtx = $ctx;
                                                unset($restoCtx['prompt'], $restoCtx['resposta_ia'], $restoCtx['resposta']);
                                            @endphp
                                            @if ($promptIa || $respostaIa || $restoCtx !== [])
                                                <details class="mt-1">
                                                    <summary class="text-xs text-indigo-600 cursor-pointer">Detalhes</summary>
                                                    @if (is_string($promptIa) && $promptIa !== '')
                                                        <div class="mt-2">
                                                            <div class="text-xs font-semibold text-gray-700">Prompt enviado à IA</div>
                                                            <pre class="mt-1 text-xs bg-gray-50 p-2 rounded overflow-x-auto whitespace-pre-wrap text-gray-600">{{ $promptIa }}</pre>
                                                        </div>
                                                    @endif
                                                    @if (is_string($respostaIa) && $respostaIa !== '')
                                                        <div class="mt-2">
                                                            <div class="text-xs font-semibold text-gray-700">Retorno da IA</div>
                                                            <pre class="mt-1 text-xs bg-gray-50 p-2 rounded overflow-x-auto whitespace-pre-wrap text-gray-600">{{ $respostaIa }}</pre>
                                                        </div>
                                                    @endif
                                                    @if ($restoCtx !== [])
                                                        <div class="mt-2">
                                                            <div class="text-xs font-semibold text-gray-700">Outros dados</div>
                                                            <pre class="mt-1 text-xs bg-gray-50 p-2 rounded overflow-x-auto text-gray-600">{{ json_encode($restoCtx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                                        </div>
                                                    @endif
                                                </details>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <div>{{ $log->grupoNome() }}</div>
                                            <div class="text-xs text-gray-400">{{ $log->nomeArquivo() }}</div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-3 py-8 text-center text-gray-500">Nenhum registro ainda.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($logs instanceof \Illuminate\Pagination\LengthAwarePaginator && $logs->hasPages())
                        <div class="mt-4">{{ $logs->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
