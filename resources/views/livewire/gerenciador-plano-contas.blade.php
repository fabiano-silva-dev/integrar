<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md">
        <div class="p-6 border-b border-gray-200">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Plano de Contas</h2>
                    <p class="text-gray-600 mt-1">Consulte, cadastre e importe contas contábeis da empresa selecionada</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button wire:click="baixarModelo" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                        Baixar modelo
                    </button>
                    <a href="{{ route('plano-contas.importar') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">
                        Importar
                    </a>
                    <button wire:click="toggleHistorico" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                        {{ $mostrarHistorico ? 'Ocultar histórico' : 'Histórico' }}
                    </button>
                    <button wire:click="abrirModal()" @disabled(!$empresaAtual) class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        Nova conta
                    </button>
                </div>
            </div>

            @if (session()->has('message'))
                <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    {{ session('message') }}
                </div>
            @endif

            @if($empresaAtual)
                <div class="mt-4 border border-gray-200 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-800">
                    <span class="font-semibold">{{ $empresaAtual->codigo_sistema ?? '—' }}</span>
                    <span class="text-gray-500 mx-1">-</span>
                    <span class="text-gray-700">{{ $empresaAtual->cnpj }}</span>
                    <span class="text-gray-500 mx-1">-</span>
                    <span class="text-gray-900">{{ $empresaAtual->nome }}</span>
                </div>
            @else
                <div class="mt-4 border border-red-300 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                    Nenhuma empresa selecionada. Escolha uma empresa no seletor do cabeçalho.
                </div>
            @endif
        </div>

        @if($mostrarHistorico)
            <div class="p-6 border-b border-gray-200 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Últimas importações</h3>
                @forelse($historico as $item)
                    <div class="text-sm text-gray-700 py-2 border-b border-gray-200 last:border-0">
                        <span class="font-medium">{{ $item->created_at->format('d/m/Y H:i') }}</span>
                        — {{ $item->arquivo_original }}
                        — {{ $item->contas_novas }} novas, {{ $item->contas_atualizadas }} atualizadas
                        @if($item->contas_inativadas > 0)
                            , {{ $item->contas_inativadas }} inativadas
                        @endif
                        @if($item->linhas_erro > 0)
                            <span class="text-red-600">({{ $item->linhas_erro }} erros)</span>
                        @endif
                        <span class="text-gray-500">[{{ $item->estrategia }}]</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Nenhuma importação registrada para esta empresa.</p>
                @endforelse
            </div>
        @endif

        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Busca</label>
                    <input type="text" wire:model.live.debounce.300ms="filtroBusca" placeholder="Código ou nome..." class="w-full rounded-md border-gray-300 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Classificação</label>
                    <select wire:model.live="filtroClassificacao" class="w-full rounded-md border-gray-300 shadow-sm" @disabled(!$empresaAtual)>
                        <option value="">Todas</option>
                        @foreach($classificacoesSinteticas as $sintetica)
                            <option value="{{ $sintetica->classificacao }}">
                                {{ str_repeat('· ', max(0, ($sintetica->nivel ?? 1) - 1)) }}{{ $sintetica->classificacao }} — {{ $sintetica->descricao }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                    <select wire:model.live="filtroTipo" class="w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Todos</option>
                        <option value="analitica">Analítica</option>
                        <option value="sintetica">Sintética</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select wire:model.live="filtroAtivo" class="w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Todos</option>
                        <option value="1">Ativo</option>
                        <option value="0">Inativo</option>
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <button wire:click="limparFiltros" class="text-sm text-blue-600 hover:text-blue-800">Limpar filtros</button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 border-b border-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Código</th>
                        <th class="px-2 py-2 text-center text-xs font-semibold text-gray-700 uppercase tracking-wide w-10">T</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Classificação</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Nome</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide w-16">Grau</th>
                        <th class="px-3 py-2 text-center text-xs font-semibold text-gray-700 uppercase tracking-wide w-24">Status</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase tracking-wide w-20">Ações</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($contas as $conta)
                        @php
                            $grau = $conta->nivel ?? 1;
                            $sintetica = $conta->tipo === 'sintetica';
                        @endphp
                        <tr class="hover:bg-gray-50 {{ $sintetica ? 'bg-gray-50/50' : '' }}">
                            <td class="px-3 py-2 text-sm font-mono text-gray-900 whitespace-nowrap">{{ $conta->codigo }}</td>
                            <td class="px-2 py-2 text-sm text-center text-gray-800 font-medium">{{ $sintetica ? 'S' : '' }}</td>
                            <td class="px-3 py-2 text-sm font-mono text-gray-700 whitespace-nowrap">{{ $conta->classificacao ?: '—' }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900" style="padding-left: {{ max(0, ($grau - 1) * 0.75) + 0.75 }}rem">
                                <span class="{{ $sintetica ? 'font-semibold uppercase' : '' }}">{{ $conta->descricao }}</span>
                            </td>
                            <td class="px-3 py-2 text-sm text-gray-700 text-right tabular-nums">{{ $grau }}</td>
                            <td class="px-3 py-2 text-center">
                                <button wire:click="toggleAtivo({{ $conta->id }})" class="text-sm">
                                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $conta->ativo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                        {{ $conta->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </button>
                            </td>
                            <td class="px-3 py-2 text-sm text-right">
                                <button wire:click="abrirModal({{ $conta->id }})" class="text-indigo-600 hover:text-indigo-900">Editar</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                Nenhuma conta encontrada.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t border-gray-200">
            {{ $contas->links() }}
        </div>
    </div>

    @if($modalAberto)
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-semibold mb-4">{{ $editandoId ? 'Editar conta' : 'Nova conta' }}</h3>

                <form wire:submit.prevent="salvar" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
                            <input type="text" wire:model="codigo" class="w-full rounded-md border-gray-300 shadow-sm font-mono">
                            @error('codigo') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Classificação</label>
                            <input type="text" wire:model="classificacao" class="w-full rounded-md border-gray-300 shadow-sm font-mono" placeholder="Ex: 1.1.1.01.001">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
                            <input type="text" wire:model="descricao" class="w-full rounded-md border-gray-300 shadow-sm">
                            @error('descricao') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Grau</label>
                            <input type="number" wire:model="nivel" min="1" class="w-full rounded-md border-gray-300 shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">T (sintética)</label>
                            <select wire:model="tipo" class="w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Analítica</option>
                                <option value="sintetica">S — Sintética</option>
                                <option value="analitica">Analítica</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Código reduzido</label>
                            <input type="text" wire:model="codigo_reduzido" class="w-full rounded-md border-gray-300 shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Natureza</label>
                            <select wire:model="natureza" class="w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">—</option>
                                <option value="devedora">Devedora</option>
                                <option value="credora">Credora</option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Código pai</label>
                            <input type="text" wire:model="codigo_pai" class="w-full rounded-md border-gray-300 shadow-sm font-mono" placeholder="Código da conta-pai no Domínio">
                        </div>
                    </div>

                    <div class="flex gap-6">
                        <label class="flex items-center">
                            <input type="checkbox" wire:model="aceita_lancamento" class="rounded border-gray-300">
                            <span class="ml-2 text-sm text-gray-700">Aceita lançamento</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" wire:model="ativo" class="rounded border-gray-300">
                            <span class="ml-2 text-sm text-gray-700">Ativo</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="fecharModal" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Cancelar</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
