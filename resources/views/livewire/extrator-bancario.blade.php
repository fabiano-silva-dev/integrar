<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <h2 class="text-2xl font-bold mb-6 text-gray-800">Extrator Bancário</h2>
                
                <!-- Formulário -->
                <div class="bg-gray-50 p-6 rounded-lg mb-6">
                    <form wire:submit.prevent="gerarExtrato">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- Empresa -->
                            <div>
                                <label for="empresaId" class="block text-sm font-medium text-gray-700 mb-2">
                                    Empresa *
                                </label>
                                <select wire:model="empresaId" id="empresaId" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Selecione uma empresa</option>
                                    @foreach($empresas as $empresa)
                                        <option value="{{ $empresa->id }}">{{ $empresa->nome }}</option>
                                    @endforeach
                                </select>
                                @error('empresaId') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <!-- Conta do Banco -->
                            <div>
                                <label for="contaBanco" class="block text-sm font-medium text-gray-700 mb-2">
                                    Conta do Banco * (Código contábil da conta do banco no sistema Domínio)
                                </label>
                                <input type="text" wire:model="contaBanco" id="contaBanco" 
                                       class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Ex: 8">
                                @error('contaBanco') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <!-- Data Inicial -->
                            <div>
                                <label for="dataInicial" class="block text-sm font-medium text-gray-700 mb-2">
                                    Data Inicial *
                                </label>
                                <input type="date" wire:model="dataInicial" id="dataInicial" 
                                       class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                @error('dataInicial') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <!-- Data Final -->
                            <div>
                                <label for="dataFinal" class="block text-sm font-medium text-gray-700 mb-2">
                                    Data Final *
                                </label>
                                <input type="date" wire:model="dataFinal" id="dataFinal" 
                                       class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                @error('dataFinal') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <!-- Saldo Inicial -->
                            <div>
                                <label for="saldoInicial" class="block text-sm font-medium text-gray-700 mb-2">
                                    Saldo Inicial *
                                </label>
                                <input type="number" wire:model="saldoInicial" id="saldoInicial" step="0.01"
                                       class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="0,00">
                                @error('saldoInicial') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <!-- Saldo Final -->
                            <div>
                                <label for="saldoFinal" class="block text-sm font-medium text-gray-700 mb-2">
                                    Saldo Final *
                                </label>
                                <input type="number" wire:model="saldoFinal" id="saldoFinal" step="0.01"
                                       class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="0,00">
                                @error('saldoFinal') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="flex gap-4 mt-6">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                Gerar Extrato
                            </button>
                            <button type="button" wire:click="limpar" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                Limpar
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Resumo -->
                @if(!empty($extrato))
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <h3 class="text-lg font-semibold text-blue-800 mb-3">Resumo do Extrato</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-white p-3 rounded border">
                            <div class="text-sm text-gray-600">Saldo Inicial</div>
                            <div class="text-lg font-semibold text-gray-800">R$ {{ number_format($saldoInicial, 2, ',', '.') }}</div>
                        </div>
                        <div class="bg-white p-3 rounded border">
                            <div class="text-sm text-gray-600">Saldo Final Calculado</div>
                            <div class="text-lg font-semibold text-gray-800">R$ {{ number_format($saldoCalculado, 2, ',', '.') }}</div>
                        </div>
                        <div class="bg-white p-3 rounded border">
                            <div class="text-sm text-gray-600">Saldo Final Informado</div>
                            <div class="text-lg font-semibold text-gray-800">R$ {{ number_format($saldoFinal, 2, ',', '.') }}</div>
                        </div>
                        <div class="bg-white p-3 rounded border">
                            <div class="text-sm text-gray-600">Diferença</div>
                            <div class="text-lg font-semibold {{ $diferenca == 0 ? 'text-green-600' : 'text-red-600' }}">
                                R$ {{ number_format($diferenca, 2, ',', '.') }}
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button wire:click="exportarCsv" 
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Exportar CSV
                        </button>
                    </div>
                </div>
                @endif

                <!-- Extrato -->
                @if(!empty($extrato))
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white border border-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Saldo Inicial</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Histórico</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Conta Contábil</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Débito</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Crédito</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Saldo Final</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Terceiro</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($extrato as $dia)
                                @if(!empty($dia['lancamentos']))
                                    <!-- Dia com movimentação -->
                                    @foreach($dia['lancamentos'] as $index => $lancamento)
                                        <tr class="{{ $index === 0 ? 'bg-blue-50' : 'bg-white' }}">
                                            @if($index === 0)
                                                <td class="px-4 py-3 text-sm text-gray-900 border-b font-medium">{{ $dia['data'] }}</td>
                                                <td class="px-4 py-3 text-sm text-gray-900 border-b font-medium">R$ {{ number_format($dia['saldo_inicial'], 2, ',', '.') }}</td>
                                            @else
                                                <td class="px-4 py-3 text-sm text-gray-900 border-b"></td>
                                                <td class="px-4 py-3 text-sm text-gray-900 border-b"></td>
                                            @endif
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b">{{ $lancamento['historico'] }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b font-mono text-xs">{{ $lancamento['conta_contabil'] ?? '' }}</td>
                                            <td class="px-4 py-3 text-sm border-b">
                                                @if($lancamento['tipo'] === 'Débito')
                                                    @if($editandoLancamento === $dia['data'] . '_' . $index)
                                                        <div class="flex items-center space-x-2">
                                                            <input type="number" wire:model="valorEditado" step="0.01" 
                                                                   class="w-20 px-2 py-1 text-sm border rounded focus:ring-blue-500 focus:border-blue-500">
                                                            <button wire:click="salvarEdicao" class="text-green-600 hover:text-green-800">
                                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                                </svg>
                                                            </button>
                                                            <button wire:click="cancelarEdicao" class="text-red-600 hover:text-red-800">
                                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    @else
                                                        <div class="flex items-center space-x-2">
                                                            <span class="text-green-600">R$ {{ number_format($lancamento['valor'], 2, ',', '.') }}</span>
                                                            <button wire:click="iniciarEdicao('{{ $dia['data'] }}', {{ $index }})" class="text-gray-400 hover:text-blue-600">
                                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path>
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    @endif
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-sm border-b">
                                                @if($lancamento['tipo'] === 'Crédito')
                                                    @if($editandoLancamento === $dia['data'] . '_' . $index)
                                                        <div class="flex items-center space-x-2">
                                                            <input type="number" wire:model="valorEditado" step="0.01" 
                                                                   class="w-20 px-2 py-1 text-sm border rounded focus:ring-blue-500 focus:border-blue-500">
                                                            <button wire:click="salvarEdicao" class="text-green-600 hover:text-green-800">
                                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                                </svg>
                                                            </button>
                                                            <button wire:click="cancelarEdicao" class="text-red-600 hover:text-red-800">
                                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    @else
                                                        <div class="flex items-center space-x-2">
                                                            <span class="text-red-600">R$ {{ number_format($lancamento['valor'], 2, ',', '.') }}</span>
                                                            <button wire:click="iniciarEdicao('{{ $dia['data'] }}', {{ $index }})" class="text-gray-400 hover:text-blue-600">
                                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path>
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    @endif
                                                @endif
                                            </td>
                                            @if($index === count($dia['lancamentos']) - 1)
                                                <td class="px-4 py-3 text-sm text-gray-900 border-b font-medium">R$ {{ number_format($dia['saldo_final'], 2, ',', '.') }}</td>
                                            @else
                                                <td class="px-4 py-3 text-sm text-gray-900 border-b"></td>
                                            @endif
                                            <td class="px-4 py-3 text-sm text-gray-900 border-b">{{ $lancamento['terceiro'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && document.activeElement.type === 'number') {
            event.preventDefault();
            Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('salvarEdicao');
        } else if (event.key === 'Escape') {
            event.preventDefault();
            Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('cancelarEdicao');
        }
    });
</script> 