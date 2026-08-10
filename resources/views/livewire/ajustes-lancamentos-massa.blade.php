<div class="w-full px-2 md:px-6 py-4">
    <div class="bg-white rounded-lg shadow-md">
        @if (session()->has('message'))
            <div class="p-4 bg-green-100 border-b border-green-200">
                <p class="text-sm text-green-800">{{ session('message') }}</p>
            </div>
        @endif

        @if (session()->has('error'))
            <div class="p-4 bg-red-100 border-b border-red-200">
                <p class="text-sm text-red-800">{{ session('error') }}</p>
            </div>
        @endif

        <div class="p-6 border-b border-gray-200">
            <h2 class="text-2xl font-bold text-gray-800">Ajustes de lançamentos em massa</h2>
            <p class="text-gray-600 mt-1">Altere em lote e, se precisar, reverta pelo histórico</p>

            @unless($precisaEscritorio)
                <div class="mt-4 flex gap-2 border-b border-gray-200">
                    <button
                        type="button"
                        wire:click="selecionarAba('ajuste')"
                        class="px-4 py-2 text-sm font-medium border-b-2 -mb-px {{ $aba === 'ajuste' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}"
                    >
                        Novo ajuste
                    </button>
                    <button
                        type="button"
                        wire:click="selecionarAba('historico')"
                        class="px-4 py-2 text-sm font-medium border-b-2 -mb-px {{ $aba === 'historico' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}"
                    >
                        Histórico
                    </button>
                </div>
            @endunless
        </div>

        @if($precisaEscritorio)
            <div class="p-6">
                <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Selecione um escritório no cabeçalho para continuar.
                </div>
            </div>
        @elseif($aba === 'historico')
            <div class="p-6">
                @if($historico->isEmpty())
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                        Nenhum ajuste em massa registrado ainda.
                    </div>
                @else
                    <div class="overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Importação</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Alterações</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Lançamentos</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Usuário</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach($historico as $lote)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-gray-900">#{{ $lote->id }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-700">
                                            {{ $lote->created_at?->format('d/m/Y H:i') }}
                                        </td>
                                        <td class="px-3 py-2 text-gray-700 max-w-xs truncate" title="{{ $lote->importacao?->nome_arquivo }}">
                                            #{{ $lote->importacao_id }}
                                            @if($lote->importacao)
                                                — {{ $lote->importacao->nome_arquivo }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-gray-700">{{ $lote->resumoAlteracoes() }}</td>
                                        <td class="px-3 py-2 text-right text-gray-900">{{ $lote->total_lancamentos }}</td>
                                        <td class="px-3 py-2 text-gray-700">{{ $lote->usuario_nome ?: '—' }}</td>
                                        <td class="px-3 py-2">
                                            @if($lote->estaAplicado())
                                                <span class="inline-flex rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Aplicado</span>
                                            @else
                                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                                    Revertido
                                                    @if($lote->revertido_em)
                                                        em {{ $lote->revertido_em->format('d/m/Y H:i') }}
                                                    @endif
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">
                                            @if($lote->estaAplicado())
                                                <button
                                                    type="button"
                                                    wire:click="prepararReversao({{ $lote->id }})"
                                                    class="text-sm text-amber-700 hover:text-amber-900 font-medium"
                                                >
                                                    Reverter
                                                </button>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        {{ $historico->links() }}
                    </div>
                @endif
            </div>
        @else
            <div class="p-6 space-y-8">
                {{-- 1. Importação --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">1. Importação</h3>
                    <div class="max-w-2xl">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Importação</label>
                        <select wire:model.live="importacaoId" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                            <option value="">Selecione...</option>
                            @foreach($importacoes as $importacao)
                                <option value="{{ $importacao['id'] }}">{{ $importacao['display_text'] }}</option>
                            @endforeach
                        </select>
                        @error('importacaoId')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </section>

                @if($importacaoId)
                    {{-- 2. Filtros --}}
                    <section>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-800">2. Filtros</h3>
                            <button type="button" wire:click="limparFiltros" class="text-sm text-blue-600 hover:text-blue-800">
                                Limpar filtros
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 bg-gray-50 rounded-lg p-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Data</label>
                                <input type="date" wire:model.live="filtroData" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Histórico</label>
                                <input type="text" wire:model.live.debounce.300ms="filtroHistorico" placeholder="Contém..." class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Conta atual</label>
                                <x-busca-plano-conta
                                    valor-model="filtroContaAtual"
                                    :valor="$filtroContaAtual"
                                    :descricao="$descricaoFiltroConta"
                                    :resultados="$sugestoesContaFiltro"
                                    selecionar-method="selecionarContaFiltro"
                                    :pesquisa-habilitada="$empresaTemPlano"
                                    placeholder="Código da conta"
                                />
                                @error('filtroContaAtual')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Valor</label>
                                <input type="text" wire:model.live.debounce.300ms="filtroValor" placeholder="Ex: 150,00" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Terceiro</label>
                                <input type="text" wire:model.live.debounce.300ms="filtroTerceiro" placeholder="Contém..." class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Tipo de lançamento</label>
                                <select wire:model.live="filtroTipo" class="w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    <option value="todos">Todos</option>
                                    <option value="debito">Débito</option>
                                    <option value="credito">Crédito</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    {{-- 3. Novos valores --}}
                    <section>
                        <h3 class="text-sm font-semibold text-gray-800 mb-3">3. Novos valores</h3>
                        <div class="space-y-4 max-w-2xl">
                            <div class="rounded-lg border border-gray-200 p-4">
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" wire:model.live="alterarConta" class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span class="flex-1">
                                        <span class="block text-sm font-medium text-gray-800">Alterar conta</span>
                                        <span class="block text-xs text-gray-500 mt-0.5">
                                            @if($filtroTipo === 'debito')
                                                Atualiza a conta débito dos lançamentos filtrados.
                                            @elseif($filtroTipo === 'credito')
                                                Atualiza a conta crédito dos lançamentos filtrados.
                                            @else
                                                Com tipo "Todos", informe a conta atual no filtro; só o lado correspondente será trocado.
                                            @endif
                                        </span>
                                    </span>
                                </label>
                                @if($alterarConta)
                                    <div class="mt-3 ml-7">
                                        <x-busca-plano-conta
                                            valor-model="novaConta"
                                            :valor="$novaConta"
                                            :descricao="$descricaoNovaConta"
                                            :resultados="$sugestoesConta"
                                            selecionar-method="selecionarConta"
                                            :pesquisa-habilitada="$empresaTemPlano"
                                            placeholder="Nova conta"
                                        />
                                        @error('novaConta')
                                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif
                            </div>

                            <div class="rounded-lg border border-gray-200 p-4">
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" wire:model.live="alterarHistorico" class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span class="flex-1">
                                        <span class="block text-sm font-medium text-gray-800">Alterar histórico</span>
                                    </span>
                                </label>
                                @if($alterarHistorico)
                                    <div class="mt-3 ml-7">
                                        <input type="text" wire:model="novoHistorico" class="w-full rounded-md border-gray-300 shadow-sm text-sm" placeholder="Novo histórico">
                                        @error('novoHistorico')
                                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif
                            </div>

                            <div class="rounded-lg border border-gray-200 p-4">
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" wire:model.live="alterarTerceiro" class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span class="flex-1">
                                        <span class="block text-sm font-medium text-gray-800">Alterar terceiro</span>
                                    </span>
                                </label>
                                @if($alterarTerceiro)
                                    <div class="mt-3 ml-7">
                                        <x-busca-terceiro
                                            :terceiro-id="$novoTerceiroId"
                                            :terceiro-nome="$novoTerceiroNome"
                                            busca-model="buscaTerceiro"
                                            :busca-valor="$buscaTerceiro"
                                            :resultados="$terceirosBusca"
                                            selecionar-method="selecionarTerceiro"
                                            limpar-method="limparTerceiro"
                                        />
                                        @error('novoTerceiroId')
                                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif
                            </div>

                            <div class="pt-2">
                                <button
                                    type="button"
                                    wire:click="prepararConfirmacao"
                                    @disabled(!$temAlteracaoSelecionada || $totalFiltrado === 0)
                                    class="h-12 px-6 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Revisar e aplicar em {{ $totalFiltrado }} {{ $totalFiltrado === 1 ? 'lançamento' : 'lançamentos' }}
                                </button>
                            </div>
                        </div>
                    </section>

                    {{-- 4. Prévia --}}
                    <section>
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                            <h3 class="text-sm font-semibold text-gray-800">
                                4. Lançamentos que serão alterados
                                <span class="ml-2 font-normal text-gray-500">({{ $totalFiltrado }})</span>
                            </h3>
                            <div class="flex items-center gap-2 text-sm text-gray-600">
                                <label for="perPageMassa">Exibir:</label>
                                <select id="perPageMassa" wire:model.live="perPage" class="rounded-md border-gray-300 text-sm">
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="200">200</option>
                                </select>
                            </div>
                        </div>

                        @if($totalFiltrado === 0)
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                                Nenhum lançamento encontrado com os filtros atuais.
                            </div>
                        @else
                            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Histórico</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Débito</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Crédito</th>
                                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Valor</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Terceiro</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        @foreach($lancamentos as $lancamento)
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-3 py-2 whitespace-nowrap text-gray-900">
                                                    {{ $lancamento->data?->format('d/m/Y') }}
                                                </td>
                                                <td class="px-3 py-2 text-gray-700 max-w-xs truncate" title="{{ $lancamento->historico }}">
                                                    {{ $lancamento->historico }}
                                                </td>
                                                <td class="px-3 py-2 font-mono text-gray-800 whitespace-nowrap">
                                                    {{ $lancamento->conta_debito }}
                                                    @if(!empty($mapaNomesContas[$lancamento->conta_debito]))
                                                        <div class="text-xs text-gray-500 font-sans truncate max-w-[10rem]" title="{{ $mapaNomesContas[$lancamento->conta_debito] }}">
                                                            {{ $mapaNomesContas[$lancamento->conta_debito] }}
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 font-mono text-gray-800 whitespace-nowrap">
                                                    {{ $lancamento->conta_credito }}
                                                    @if(!empty($mapaNomesContas[$lancamento->conta_credito]))
                                                        <div class="text-xs text-gray-500 font-sans truncate max-w-[10rem]" title="{{ $mapaNomesContas[$lancamento->conta_credito] }}">
                                                            {{ $mapaNomesContas[$lancamento->conta_credito] }}
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-right whitespace-nowrap text-gray-900">
                                                    {{ number_format((float) $lancamento->valor, 2, ',', '.') }}
                                                </td>
                                                <td class="px-3 py-2 text-gray-700 max-w-[12rem] truncate">
                                                    {{ $lancamento->nome_terceiro ?: $lancamento->nome_empresa }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3">
                                {{ $lancamentos->links() }}
                            </div>
                        @endif
                    </section>
                @endif
            </div>
        @endif
    </div>

    @if($confirmando)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-gray-900">Confirmar alteração em massa</h3>
                </div>
                <div class="px-6 py-4 space-y-3 text-sm text-gray-700">
                    <p>
                        Serão atualizados <strong>{{ $totalFiltrado }}</strong>
                        {{ $totalFiltrado === 1 ? 'lançamento' : 'lançamentos' }} da importação selecionada.
                    </p>
                    <ul class="list-disc pl-5 space-y-1">
                        @if($alterarConta)
                            <li>Conta → <span class="font-mono">{{ $novaConta }}</span></li>
                        @endif
                        @if($alterarHistorico)
                            <li>Histórico → {{ $novoHistorico }}</li>
                        @endif
                        @if($alterarTerceiro)
                            <li>Terceiro → {{ $novoTerceiroNome }}</li>
                        @endif
                    </ul>
                    <p class="text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                        O ajuste ficará no histórico e poderá ser revertido depois.
                    </p>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                    <button type="button" wire:click="cancelarConfirmacao" class="px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        wire:click="aplicarAlteracoes"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="aplicarAlteracoes">Confirmar alteração</span>
                        <span wire:loading wire:target="aplicarAlteracoes">Aplicando...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if($loteRevertendo)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-gray-900">Reverter ajuste #{{ $loteRevertendo->id }}</h3>
                </div>
                <div class="px-6 py-4 space-y-3 text-sm text-gray-700">
                    <p>
                        Serão restaurados os valores anteriores de
                        <strong>{{ $loteRevertendo->total_lancamentos }}</strong>
                        {{ $loteRevertendo->total_lancamentos === 1 ? 'lançamento' : 'lançamentos' }}
                        ({{ $loteRevertendo->total_campos }} campo(s)).
                    </p>
                    <p class="text-gray-600">{{ $loteRevertendo->resumoAlteracoes() }}</p>
                    <p class="text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                        Campos que foram editados depois deste ajuste serão pulados.
                    </p>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                    <button type="button" wire:click="cancelarReversao" class="px-4 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        wire:click="confirmarReversao"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-medium hover:bg-amber-700 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="confirmarReversao">Confirmar regressão</span>
                        <span wire:loading wire:target="confirmarReversao">Revertendo...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
