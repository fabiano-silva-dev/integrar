<div class="mx-auto p-6 w-full">
    @if (session()->has('success'))
        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            {{ session('error') }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow-md">
        <div class="p-6 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Histórico de Conversões PDF → OFX</h2>
                <p class="text-gray-600 mt-1">Consulte as conversões realizadas e baixe os arquivos OFX gerados</p>
            </div>
            <a href="{{ route('conversao-pdf-ofx') }}"
               class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                Nova conversão
            </a>
        </div>

        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select wire:model.live="filtroStatus" class="w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Todos</option>
                        <option value="pendente">Pendente</option>
                        <option value="processando">Processando</option>
                        <option value="concluida">Concluída</option>
                        <option value="erro">Erro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Layout</label>
                    <select wire:model.live="filtroLayout" class="w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Todos</option>
                        @foreach($layoutsDisponiveis as $valor => $nome)
                            <option value="{{ $valor }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Data</label>
                    <input type="date" wire:model.live="filtroData" class="w-full rounded-md border-gray-300 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Arquivo</label>
                    <input type="text" wire:model.live.debounce.300ms="filtroArquivo" placeholder="Buscar..." class="w-full rounded-md border-gray-300 shadow-sm">
                </div>
            </div>
            <div class="mt-4">
                <button wire:click="limparFiltros" class="text-sm text-blue-600 hover:text-blue-800">
                    Limpar filtros
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Arquivo PDF</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Layout</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lançamentos</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Período</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Conta</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuário</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($conversoes as $conversao)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $conversao->id }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="max-w-xs truncate" title="{{ $conversao->nome_arquivo_origem }}">
                                    {{ $conversao->nome_arquivo_origem }}
                                </div>
                                @if($conversao->status === 'erro' && $conversao->erro_mensagem)
                                    <div class="text-xs text-red-600 mt-1 max-w-xs truncate" title="{{ $conversao->erro_mensagem }}">
                                        {{ $conversao->erro_mensagem }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $layoutsDisponiveis[$conversao->layout] ?? ucfirst($conversao->layout) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                    {{ $conversao->status === 'concluida' ? 'bg-green-100 text-green-800' :
                                       ($conversao->status === 'processando' ? 'bg-yellow-100 text-yellow-800' :
                                       ($conversao->status === 'erro' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')) }}">
                                    {{ ucfirst($conversao->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $conversao->total_lancamentos }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                @if($conversao->data_inicial && $conversao->data_final)
                                    {{ $conversao->data_inicial->format('d/m/Y') }} – {{ $conversao->data_final->format('d/m/Y') }}
                                @elseif($conversao->data_inicial)
                                    {{ $conversao->data_inicial->format('d/m/Y') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                @php
                                    $metadados = $conversao->metadados ?? [];
                                @endphp
                                @if(!empty($metadados['cooperativa']) || !empty($metadados['conta']))
                                    <div class="flex flex-col">
                                        @if(!empty($metadados['cooperativa']))
                                            <span class="text-xs text-gray-500">Ag: {{ $metadados['cooperativa'] }}</span>
                                        @endif
                                        @if(!empty($metadados['conta']))
                                            <span>{{ $metadados['conta'] }}</span>
                                        @endif
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $conversao->created_at->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                @if($conversao->user)
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{ $conversao->user->name }}</span>
                                        <span class="text-xs text-gray-500">{{ $conversao->user->email }}</span>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                @if($conversao->status === 'concluida' && $conversao->nome_arquivo_ofx)
                                    <button
                                        wire:click="downloadOfx({{ $conversao->id }})"
                                        class="text-indigo-600 hover:text-indigo-900"
                                    >
                                        Baixar OFX
                                    </button>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-6 py-4 text-center text-gray-500">
                                Nenhuma conversão encontrada.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t border-gray-200">
            {{ $conversoes->links() }}
        </div>
    </div>
</div>
