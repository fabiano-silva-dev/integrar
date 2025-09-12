<div class="max-w-7xl mx-auto p-6">
    <!-- Mensagens de Feedback -->
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
        <!-- Cabeçalho -->
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-2xl font-bold text-gray-800">Lista de Importações</h2>
            <p class="text-gray-600 mt-1">Gerencie as importações realizadas</p>
        </div>



        <!-- Filtros -->
        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
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
                    Limpar Filtros
                </button>
            </div>
        </div>

        <!-- Tabela -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Arquivo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Linhas/Lançamentos</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuário</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($importacoes as $importacao)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $importacao->id }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="max-w-xs truncate" title="{{ $importacao->nome_arquivo }}">
                                    {{ $importacao->nome_arquivo }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                    {{ $importacao->status === 'concluida' ? 'bg-green-100 text-green-800' : 
                                       ($importacao->status === 'processando' ? 'bg-yellow-100 text-yellow-800' : 
                                       ($importacao->status === 'erro' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')) }}">
                                    {{ ucfirst($importacao->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="flex flex-col">
                                    <span class="font-medium text-blue-600">
                                        {{ $importacao->lancamentos_count ?? 0 }} lançamentos
                                    </span>
                                    <span class="text-xs text-gray-500">
                                        {{ $importacao->total_registros ?? 0 }} linhas processadas
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                    {{ $importacao->tipo === 'personalizado' ? 'bg-purple-100 text-purple-800' : 
                                       ($importacao->tipo === 'avancado' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800') }}">
                                    {{ ucfirst($importacao->tipo ?? 'padrão') }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $importacao->created_at->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="flex flex-col">
                                    <span class="font-medium">
                                        {{ $importacao->usuario ?? ($importacao->user ? $importacao->user->name : 'Sistema') }}
                                    </span>
                                    @if($importacao->user)
                                        <span class="text-xs text-gray-500">{{ $importacao->user->email }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2">
                                    <button 
                                        wire:click="abrirImportacao({{ $importacao->id }})"
                                        class="text-blue-600 hover:text-blue-900"
                                    >
                                        Abrir
                                    </button>
                                    <button 
                                        wire:click="excluirImportacao({{ $importacao->id }})"
                                        wire:confirm="Tem certeza que deseja excluir esta importação? Todos os {{ $importacao->lancamentos_count ?? 0 }} lançamentos serão removidos permanentemente."
                                        class="text-red-600 hover:text-red-900"
                                    >
                                        Excluir
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                                Nenhuma importação encontrada.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $importacoes->links() }}
        </div>
    </div>
</div>
