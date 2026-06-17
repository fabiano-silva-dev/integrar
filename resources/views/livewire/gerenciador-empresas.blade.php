<div class="p-6">
    <div class="max-w-7xl mx-auto">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">
                    {{ $modo_edicao ? 'Editar Empresa' : 'Nova Empresa' }}
                </h2>

                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        {{ session('message') }}
                    </div>
                @endif

                @if (session()->has('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        {{ session('error') }}
                    </div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded mb-4">
                        Selecione um escritório no menu superior para cadastrar ou editar empresas.
                    </div>
                @endif

                <form wire:submit.prevent="salvar" class="space-y-4" @if($precisaSelecionarEscritorio) style="opacity:0.5;pointer-events:none" @endif>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="nome" class="block text-sm font-medium text-gray-700">Nome da Empresa</label>
                            <input type="text" id="nome" wire:model="nome" autofocus
                                   class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500 transition duration-150 ease-in-out">
                            @error('nome') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="cnpj" class="block text-sm font-medium text-gray-700">CNPJ</label>
                            <input type="text" id="cnpj" wire:model="cnpj" 
                                   class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500 transition duration-150 ease-in-out"
                                   placeholder="00.000.000/0000-00 ou só números">
                            @error('cnpj') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="codigo_sistema" class="block text-sm font-medium text-gray-700">Código do Sistema Contábil</label>
                            <input type="text" id="codigo_sistema" wire:model="codigo_sistema" 
                                   class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500 transition duration-150 ease-in-out">
                            @error('codigo_sistema') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="codigo_conta_banco" class="block text-sm font-medium text-gray-700">Conta contábil do Banco no sistema da Contabilidade <span class="text-gray-500 font-normal">(opcional)</span></label>
                            <input type="text" id="codigo_conta_banco" wire:model="codigo_conta_banco" 
                                   placeholder="Ex: 254"
                                   class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500 transition duration-150 ease-in-out">
                            @error('codigo_conta_banco') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        @if($modo_edicao)
                            <button type="button" wire:click="cancelarEdicao" 
                                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                Cancelar
                            </button>
                        @endif
                        <button type="submit" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            {{ $modo_edicao ? 'Atualizar' : 'Salvar' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Empresas Cadastradas</h3>
                    <div class="w-64">
                        <input type="text" wire:model.live="busca" placeholder="Buscar empresas..." 
                               class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CNPJ</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cód. sistema contábil</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Conta banco (contabilidade)</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($empresas as $empresa)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $empresa->nome }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $empresa->cnpj }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $empresa->codigo_sistema ?: '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $empresa->codigo_conta_banco ?: '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button wire:click="editar({{ $empresa->id }})" 
                                                class="text-indigo-600 hover:text-indigo-900 mr-3">
                                            Editar
                                        </button>
                                        <button wire:click="confirmarExclusao({{ $empresa->id }})" 
                                                class="text-red-600 hover:text-red-900">
                                            Excluir
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">
                                        Nenhuma empresa encontrada.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $empresas->links() }}
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmação de Exclusão -->
    @if($confirmando_exclusao)
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3 text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Confirmar Exclusão</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Tem certeza que deseja excluir esta empresa? Esta ação não pode ser desfeita.
                    </p>
                    <div class="flex justify-center space-x-3">
                        <button wire:click="cancelarExclusao" 
                                class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                            Cancelar
                        </button>
                        <button wire:click="excluir" 
                                class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                            Excluir
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
document.addEventListener('livewire:load', function () {
    const cnpjInput = document.getElementById('cnpj');
    if (cnpjInput) {
        cnpjInput.addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 14) v = v.slice(0, 14);
            v = v.replace(/(\d{2})(\d)/, '$1.$2');
            v = v.replace(/(\d{3})(\d)/, '$1.$2');
            v = v.replace(/(\d{3})(\d)/, '$1/$2');
            v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
            e.target.value = v;
        });
    }
});
</script>
@endpush
