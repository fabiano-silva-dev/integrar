<div class="p-6">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Drive contábil</h1>
                    <p class="text-sm text-gray-600 mt-1">Selecione a empresa e abra ou baixe os arquivos do Google Drive.</p>
                </div>
                @if ($podeConfigurar)
                    <a href="{{ route('documentos.whatsapp') }}"
                       class="text-sm font-semibold text-indigo-600 hover:text-indigo-800">
                        Configurações
                    </a>
                @endif
            </div>

            <div class="p-6">
                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('message') }}</div>
                @endif
                @if ($erro)
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ $erro }}</div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para ver o Drive contábil.
                    </div>
                @else
                    <div class="flex flex-wrap gap-3 mb-5">
                        <select wire:model.live="empresaId" class="border-gray-300 rounded-xl min-w-[240px] flex-1">
                            <option value="">Todas as empresas</option>
                            @foreach ($empresas as $empresa)
                                <option value="{{ $empresa->id }}">{{ $empresa->nome_fantasia ?: $empresa->nome }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model.live.debounce.400ms="busca"
                               placeholder="{{ $nivel === 'arquivos' ? 'Buscar arquivo' : ($nivel === 'empresas' ? 'Buscar empresa' : 'Filtrar') }}"
                               class="border-gray-300 rounded-xl flex-1 min-w-[180px]">
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                        <nav class="flex flex-wrap items-center -ml-1 min-w-0">
                            @foreach ($breadcrumb as $i => $item)
                                @if ($i > 0)
                                    <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                    </svg>
                                @endif
                                <div class="relative" @if ($loop->last && $breadcrumbIrmaos !== []) x-data="{ aberto: false }" @click.outside="aberto = false" @endif>
                                    @if ($loop->last && $breadcrumbIrmaos !== [])
                                        <button type="button" @click="aberto = !aberto"
                                                class="inline-flex items-center gap-1.5 max-w-xs h-10 px-3 rounded-lg text-base font-semibold text-gray-900 bg-gray-100 hover:bg-gray-200">
                                            @if ($i === 0)
                                                <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/>
                                                </svg>
                                            @endif
                                            <span class="truncate">{{ $item['label'] }}</span>
                                            <svg class="w-4 h-4 text-gray-500 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                        <div x-show="aberto" x-cloak x-transition
                                             class="absolute left-0 mt-1 z-20 w-56 rounded-xl border border-gray-200 bg-white shadow-xl py-1">
                                            @foreach ($breadcrumbIrmaos as $irmao)
                                                <button type="button"
                                                        @click="aberto = false"
                                                        @if ($irmao['acao'] === 'empresa')
                                                            wire:click="abrirEmpresa({{ (int) $irmao['id'] }})"
                                                        @elseif ($irmao['acao'] === 'ano')
                                                            wire:click="abrirAno({{ (int) $irmao['id'] }})"
                                                        @else
                                                            wire:click="abrirTipo('{{ $irmao['id'] }}')"
                                                        @endif
                                                        class="w-full text-left px-3 py-2 text-sm truncate {{ $irmao['atual'] ? 'bg-gray-100 font-semibold text-gray-900' : 'text-gray-700 hover:bg-gray-50' }}">
                                                    {{ $irmao['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @else
                                        <button type="button" wire:click="irPara('{{ $item['nivel'] }}')"
                                                class="inline-flex items-center gap-1.5 max-w-xs h-10 px-3 rounded-lg text-base font-medium truncate
                                                    {{ $loop->last ? 'text-gray-900 font-semibold bg-gray-100' : 'text-gray-700 hover:bg-gray-100' }}">
                                            @if ($i === 0)
                                                <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/>
                                                </svg>
                                            @endif
                                            <span class="truncate">{{ $item['label'] }}</span>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </nav>

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($pastaDriveUrl)
                                <a href="{{ $pastaDriveUrl }}" target="_blank" rel="noopener"
                                   class="h-10 px-4 inline-flex items-center rounded-xl border border-gray-200 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    Abrir no Drive
                                </a>
                            @endif
                            @if ($empresaAtual)
                                <button type="button" wire:click="baixarPastaAtual" wire:loading.attr="disabled"
                                        class="h-10 px-4 inline-flex items-center rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold">
                                    <span wire:loading.remove wire:target="baixarPastaAtual">Baixar pasta</span>
                                    <span wire:loading wire:target="baixarPastaAtual">Preparando...</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    @if (count($selecionados) > 0)
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-indigo-50 border border-indigo-100 px-4 py-3">
                            <p class="text-sm font-semibold text-indigo-900">{{ count($selecionados) }} selecionado(s)</p>
                            <div class="flex gap-2">
                                <button type="button" wire:click="abrirMoverSelecionados"
                                        class="h-10 px-4 rounded-xl border border-indigo-200 bg-white text-indigo-800 text-sm font-semibold hover:bg-indigo-50">
                                    Mover
                                </button>
                                <button type="button" wire:click="pedirExcluirSelecionados"
                                        class="h-10 px-4 rounded-xl border border-red-200 bg-white text-red-700 text-sm font-semibold hover:bg-red-50">
                                    Excluir
                                </button>
                                <button type="button" wire:click="baixarSelecionados" wire:loading.attr="disabled"
                                        class="h-10 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold">
                                    Baixar
                                </button>
                                <button type="button" wire:click="$set('selecionados', [])" class="h-10 px-3 text-sm text-indigo-700">
                                    Limpar
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="w-12 px-3 py-3">
                                        @if ($chavesVisiveis !== [])
                                            <input type="checkbox"
                                                   wire:click="selecionarTodos"
                                                   @checked($todosSelecionados)
                                                   class="rounded border-gray-300 text-indigo-600">
                                        @endif
                                    </th>
                                    <th class="text-left px-3 py-3 font-medium">Nome</th>
                                    <th class="text-left px-3 py-3 font-medium hidden md:table-cell">Detalhe</th>
                                    <th class="text-right px-3 py-3 font-medium hidden sm:table-cell">Tamanho</th>
                                    <th class="w-56 px-3 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($itens as $item)
                                    <tr class="border-t border-gray-100 hover:bg-gray-50"
                                        wire:key="{{ $item['chave'] }}">
                                        <td class="px-3 py-3" wire:click.stop>
                                            <input type="checkbox"
                                                   wire:click="toggleSelecao('{{ $item['chave'] }}')"
                                                   @checked(in_array($item['chave'], $selecionados, true))
                                                   class="rounded border-gray-300 text-indigo-600">
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="flex items-center gap-3 min-w-0">
                                                <span class="flex h-10 w-10 items-center justify-center rounded-xl {{ $item['tipo'] === 'arquivo' ? 'bg-gray-100 text-gray-500' : 'bg-amber-50 text-amber-500' }}">
                                                    @if ($item['tipo'] === 'arquivo')
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                                                    @else
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
                                                    @endif
                                                </span>
                                                @if ($item['abrir'] === 'arquivo' && $item['url_drive'])
                                                    <a href="{{ $item['url_drive'] }}" target="_blank" rel="noopener"
                                                       class="font-semibold text-gray-900 truncate hover:text-indigo-700">
                                                        {{ $item['nome'] }}
                                                    </a>
                                                @elseif ($item['abrir'] === 'empresa')
                                                    <button type="button" wire:click="abrirEmpresa({{ (int) $item['id'] }})"
                                                            class="font-semibold text-gray-900 truncate text-left hover:text-indigo-700">
                                                        {{ $item['nome'] }}
                                                    </button>
                                                @elseif ($item['abrir'] === 'ano')
                                                    <button type="button" wire:click="abrirAno({{ (int) $item['id'] }})"
                                                            class="font-semibold text-gray-900 truncate text-left hover:text-indigo-700">
                                                        {{ $item['nome'] }}
                                                    </button>
                                                @elseif ($item['abrir'] === 'tipo')
                                                    <button type="button" wire:click="abrirTipo('{{ $item['id'] }}')"
                                                            class="font-semibold text-gray-900 truncate text-left hover:text-indigo-700">
                                                        {{ $item['nome'] }}
                                                    </button>
                                                @else
                                                    <span class="font-semibold text-gray-900 truncate">{{ $item['nome'] }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-3 py-3 text-gray-500 hidden md:table-cell">{{ $item['subtitulo'] }}</td>
                                        <td class="px-3 py-3 text-gray-500 text-right hidden sm:table-cell">
                                            {{ $item['tipo'] === 'arquivo' ? ($item['tamanho'] ?? '—') : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-right whitespace-nowrap">
                                            @if ($item['url_drive'])
                                                <a href="{{ $item['url_drive'] }}" target="_blank" rel="noopener"
                                                   class="text-indigo-600 font-semibold mr-3">Abrir</a>
                                            @endif
                                            @if ($item['tipo'] === 'arquivo')
                                                <button type="button" wire:click="abrirMoverItem('{{ $item['chave'] }}')"
                                                        class="text-gray-700 font-semibold mr-3">
                                                    Mover
                                                </button>
                                                <button type="button" wire:click="pedirExcluirItem('{{ $item['chave'] }}')"
                                                        class="text-red-600 font-semibold mr-3">
                                                    Excluir
                                                </button>
                                            @endif
                                            <button type="button" wire:click="baixarItem('{{ $item['chave'] }}')"
                                                    class="text-gray-700 font-semibold">
                                                Baixar
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-12 text-center text-gray-500">
                                            @if ($nivel === 'empresas')
                                                Nenhuma empresa com pasta no Drive.
                                                @if ($podeConfigurar)
                                                    <div class="mt-2">
                                                        <a href="{{ route('documentos.drive') }}" class="text-indigo-600 font-semibold">Configurar Google Drive</a>
                                                    </div>
                                                @endif
                                            @elseif ($nivel === 'anos')
                                                As pastas desta empresa ainda não foram criadas no Drive.
                                                @if ($podeConfigurar)
                                                    <div class="mt-2">
                                                        <a href="{{ route('documentos.drive') }}" class="text-indigo-600 font-semibold">Configurar Google Drive</a>
                                                    </div>
                                                @endif
                                            @elseif ($nivel === 'arquivos')
                                                Nenhum arquivo nesta pasta.
                                            @else
                                                Nenhuma pasta para exibir.
                                            @endif
                                        </td>
                                    </tr>
                                @endempty
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($modalMoverAberto)
        <div class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50">
            <div class="fixed inset-0 bg-gray-500 opacity-75" wire:click="fecharMover"></div>
            <div class="relative mb-6 bg-white rounded-lg overflow-hidden shadow-xl sm:w-full sm:max-w-2xl sm:mx-auto">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Mover para</h2>
                    <p class="text-sm text-gray-600 mt-1">Escolha a pasta de destino no Drive.</p>
                </div>
                <nav class="flex flex-wrap items-center gap-1 px-6 py-3 border-b border-gray-100 bg-gray-50">
                    @foreach ($moverBreadcrumb as $item)
                        @if (! $loop->first)
                            <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        @endif
                        @if ($loop->last)
                            <span class="text-sm font-semibold text-gray-900 truncate">{{ $item['label'] }}</span>
                        @else
                            <button type="button" wire:click="moverIrPara('{{ $item['nivel'] }}')"
                                    class="text-sm font-medium text-indigo-700 hover:text-indigo-900 truncate">
                                {{ $item['label'] }}
                            </button>
                        @endif
                    @endforeach
                </nav>
                <div class="overflow-y-auto divide-y divide-gray-100" style="max-height: 32rem;">
                    @forelse ($moverItens as $pasta)
                        <div class="flex items-center gap-3 px-6 py-3" wire:key="mover-{{ $pasta['chave'] }}">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-500 shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
                            </span>
                            @if (($pasta['abrir'] ?? '') === 'empresa')
                                <button type="button" wire:click="moverAbrirEmpresa({{ (int) $pasta['id'] }})"
                                        class="min-w-0 text-left font-semibold text-gray-900 hover:text-indigo-700 truncate">
                                    {{ $pasta['nome'] }}
                                </button>
                            @elseif (($pasta['abrir'] ?? '') === 'ano')
                                <button type="button" wire:click="moverAbrirAno({{ (int) $pasta['id'] }})"
                                        class="min-w-0 text-left font-semibold text-gray-900 hover:text-indigo-700 truncate">
                                    {{ $pasta['nome'] }}
                                </button>
                            @elseif (($pasta['abrir'] ?? '') === 'tipo')
                                <button type="button" wire:click="moverAbrirTipo('{{ $pasta['id'] }}')"
                                        class="min-w-0 text-left font-semibold text-gray-900 hover:text-indigo-700 truncate">
                                    {{ $pasta['nome'] }}
                                </button>
                            @else
                                <span class="min-w-0 font-semibold text-gray-900 truncate">{{ $pasta['nome'] }}</span>
                            @endif
                        </div>
                    @empty
                        <p class="px-6 py-8 text-sm text-gray-500 text-center">Nenhuma pasta para exibir.</p>
                    @endforelse
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="fecharMover"
                            class="h-12 px-4 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmarMover" wire:loading.attr="disabled"
                            @disabled(! $moverPodeConfirmar || $moverDestinoIgual)
                            class="h-12 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold disabled:opacity-50">
                        <span wire:loading.remove wire:target="confirmarMover">Mover para esta pasta</span>
                        <span wire:loading wire:target="confirmarMover">Movendo...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($confirmandoExclusao)
        <div class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50">
            <div class="fixed inset-0 bg-gray-500 opacity-75" wire:click="cancelarExclusao"></div>
            <div class="relative mb-6 bg-white rounded-lg overflow-hidden shadow-xl sm:w-full sm:max-w-md sm:mx-auto">
                <div class="px-6 py-5">
                    <h2 class="text-lg font-semibold text-gray-900">Excluir arquivo</h2>
                    <p class="text-sm text-gray-600 mt-2">O arquivo sai do Drive e desta lista.</p>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2">
                    <button type="button" wire:click="cancelarExclusao"
                            class="h-12 px-4 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmarExclusao" wire:loading.attr="disabled"
                            class="h-12 px-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold">
                        <span wire:loading.remove wire:target="confirmarExclusao">Excluir</span>
                        <span wire:loading wire:target="confirmarExclusao">Excluindo...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
