<div class="p-6">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Configurações — Documentos</h1>
                <p class="text-sm text-gray-600 mt-1">Vincule uma ou mais empresas a cada grupo e ative o monitoramento. Cada empresa recebe a própria pasta no Drive.</p>
            </div>
            <div class="p-6">
                <x-documentos-nav ativo="grupos" />

                @if ($erro)
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ $erro }}</div>
                @endif
                @if ($sucesso)
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ $sucesso }}</div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para gerenciar os grupos.
                    </div>
                @else
                    <div class="flex flex-wrap gap-3 mb-4">
                        <input type="text" wire:model.live.debounce.400ms="busca" placeholder="Buscar grupo"
                               class="border-gray-300 rounded-md flex-1 min-w-[200px]">
                        <button type="button" wire:click="sincronizar"
                                class="h-11 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
                            Sincronizar grupos
                        </button>
                    </div>

                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left px-3 py-2">Grupo</th>
                                    <th class="text-left px-3 py-2">Empresas</th>
                                    <th class="text-left px-3 py-2">Monitorar</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($grupos as $grupo)
                                    @php
                                        $idsVinculadas = $grupo->idsEmpresas();
                                    @endphp
                                    <tr class="border-t" wire:key="grupo-{{ $grupo->id }}">
                                        <td class="px-3 py-2">
                                            <div class="font-medium text-gray-900">{{ $grupo->nome ?: 'Sem nome' }}</div>
                                            <div class="text-xs text-gray-400">{{ $grupo->jid }}</div>
                                        </td>
                                        <td class="px-3 py-2 min-w-[240px]">
                                            <div class="flex flex-wrap gap-1 mb-2">
                                                @forelse ($grupo->empresas as $vinculada)
                                                    <span class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-800 text-xs font-medium px-2 py-1 rounded-full">
                                                        {{ $vinculada->nome_fantasia ?: $vinculada->nome ?: $vinculada->razao_social }}
                                                        <button type="button"
                                                                wire:click="removerEmpresa({{ $grupo->id }}, {{ $vinculada->id }})"
                                                                class="text-indigo-500 hover:text-indigo-800 leading-none"
                                                                aria-label="Remover {{ $vinculada->nome }}">×</button>
                                                    </span>
                                                @empty
                                                    @if ($grupo->empresa)
                                                        <span class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-800 text-xs font-medium px-2 py-1 rounded-full">
                                                            {{ $grupo->empresa->nome_fantasia ?: $grupo->empresa->nome }}
                                                            <button type="button"
                                                                    wire:click="removerEmpresa({{ $grupo->id }}, {{ $grupo->empresa->id }})"
                                                                    class="text-indigo-500 hover:text-indigo-800 leading-none">×</button>
                                                        </span>
                                                    @endif
                                                @endforelse
                                            </div>
                                            <select wire:change="adicionarEmpresa({{ $grupo->id }}, $event.target.value)"
                                                    wire:key="add-empresa-{{ $grupo->id }}-{{ implode('-', $idsVinculadas) }}"
                                                    class="border-gray-300 rounded-md text-sm w-full">
                                                <option value="">Adicionar empresa</option>
                                                @foreach ($empresas as $empresa)
                                                    @if (! in_array((int) $empresa->id, $idsVinculadas, true))
                                                        <option value="{{ $empresa->id }}">
                                                            {{ $empresa->nome_fantasia ?: $empresa->nome ?: $empresa->razao_social }}
                                                        </option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-3 py-2">
                                            <button type="button"
                                                    wire:click="alternarMonitorar({{ $grupo->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="alternarMonitorar({{ $grupo->id }})"
                                                    role="switch"
                                                    aria-checked="{{ $grupo->monitorar ? 'true' : 'false' }}"
                                                    aria-label="{{ $grupo->monitorar ? 'Parar de monitorar '.$grupo->nome : 'Monitorar '.$grupo->nome }}"
                                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 {{ $grupo->monitorar ? 'bg-indigo-600' : 'bg-gray-200' }}">
                                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $grupo->monitorar ? 'translate-x-5' : 'translate-x-0' }}"></span>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-3 py-6 text-center text-gray-500">
                                            Nenhum grupo. Conecte o WhatsApp e clique em Sincronizar grupos.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (method_exists($grupos, 'links'))
                        <div class="mt-4">{{ $grupos->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
