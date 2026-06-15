<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md">
        <!-- Cabeçalho -->
        <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Gerenciador de Terceiros</h2>
                    <p class="text-gray-600 mt-1">Gerencie empresas, clientes, funcionários e fornecedores da empresa selecionada</p>
                </div>
                <button wire:click="abrirModal()" @disabled(!$empresaAtual) class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    Novo Terceiro
                </button>
            </div>
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
                    Nenhuma empresa selecionada. Escolha uma empresa no seletor do cabeçalho para ver e cadastrar terceiros.
                </div>
            @endif
        </div>

        <!-- Filtros -->
        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nome</label>
                    <input type="text" wire:model.live.debounce.300ms="filtroNome" placeholder="Buscar..." class="w-full rounded-md border-gray-300 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                    <select wire:model.live="filtroTipo" class="w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">Todos</option>
                        <option value="empresa">Empresa</option>
                        <option value="cliente">Cliente</option>
                        <option value="funcionario">Funcionário</option>
                        <option value="fornecedor">Fornecedor</option>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNPJ/CPF</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Observações</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($terceiros as $terceiro)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $terceiro->nome }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $terceiro->cnpj_cpf ?: '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                    {{ $terceiro->tipo === 'empresa' ? 'bg-blue-100 text-blue-800' : 
                                       ($terceiro->tipo === 'cliente' ? 'bg-green-100 text-green-800' : 
                                       ($terceiro->tipo === 'funcionario' ? 'bg-yellow-100 text-yellow-800' : 'bg-purple-100 text-purple-800')) }}">
                                    {{ ucfirst($terceiro->tipo) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button wire:click="toggleAtivo({{ $terceiro->id }})" class="text-sm">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full {{ $terceiro->ativo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                        {{ $terceiro->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </button>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="max-w-xs truncate" title="{{ $terceiro->observacoes }}">
                                    {{ $terceiro->observacoes ?: '-' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2">
                                    <button wire:click="abrirModal({{ $terceiro->id }})" class="text-blue-600 hover:text-blue-900">
                                        Editar
                                    </button>
                                    <button wire:click="excluir({{ $terceiro->id }})" class="text-red-600 hover:text-red-900" onclick="return confirm('Tem certeza que deseja excluir este terceiro?')">
                                        Excluir
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                Nenhum terceiro encontrado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $terceiros->links() }}
        </div>
    </div>

    <!-- Modal de Edição -->
    @if($editandoId !== null || $nome !== '')
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-semibold mb-4">
                    {{ $editandoId ? 'Editar Terceiro' : 'Novo Terceiro' }}
                </h3>
                
                <form wire:submit.prevent="salvar" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
                        <input type="text" wire:model="nome" class="w-full rounded-md border-gray-300 shadow-sm">
                        @error('nome') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">CNPJ/CPF</label>
                        <input type="text" wire:model="cnpj_cpf" placeholder="CPF ou CNPJ, com ou sem máscara" class="w-full rounded-md border-gray-300 shadow-sm">
                        @error('cnpj_cpf') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                        <select wire:model="tipo" class="w-full rounded-md border-gray-300 shadow-sm">
                            <option value="empresa">Empresa</option>
                            <option value="cliente">Cliente</option>
                            <option value="funcionario">Funcionário</option>
                            <option value="fornecedor">Fornecedor</option>
                        </select>
                        @error('tipo') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                        <textarea wire:model="observacoes" rows="3" class="w-full rounded-md border-gray-300 shadow-sm"></textarea>
                        @error('observacoes') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" wire:model="ativo" class="rounded border-gray-300">
                            <span class="ml-2 text-sm text-gray-700">Ativo</span>
                        </label>
                    </div>
                    
                    <div class="flex justify-end space-x-2">
                        <button type="button" wire:click="fecharModal" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                            Cancelar
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
