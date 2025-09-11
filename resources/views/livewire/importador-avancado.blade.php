<div class="p-6">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">
                    Importador
                </h2>
                <p class="text-gray-600 mb-6">
                    Selecione o layout do arquivo, a empresa de destino e faça upload do arquivo para importação.
                </p>

                <form wire:submit.prevent="processarArquivo" class="space-y-6">
                    <!-- Seleção de Layout -->
                    <div>
                        <label for="layout" class="block text-sm font-medium text-gray-700 mb-2">
                            Layout do Arquivo
                        </label>
                        <select id="layout" wire:model="layout_selecionado" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Selecione o layout...</option>
                            @foreach($layouts as $valor => $nome)
                                <option value="{{ $valor }}">{{ $nome }}</option>
                            @endforeach
                        </select>
                        @error('layout_selecionado') 
                            <span class="text-red-500 text-sm">{{ $message }}</span> 
                        @enderror
                    </div>

                    <!-- Seleção de Empresa -->
                    <div>
                        <label for="empresa" class="block text-sm font-medium text-gray-700 mb-2">
                            Empresa de Destino
                        </label>
                        <select id="empresa" wire:model="empresa_id" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Selecione a empresa...</option>
                            @foreach($empresas as $empresa)
                                <option value="{{ $empresa->id }}">{{ $empresa->nome }} ({{ $empresa->cnpj }})</option>
                            @endforeach
                        </select>
                        @error('empresa_id') 
                            <span class="text-red-500 text-sm">{{ $message }}</span> 
                        @enderror
                    </div>

                    <!-- Conta do Banco (apenas para Grafeno, SICOOB, Caixa Federal e Registros) -->
                    @if($layout_selecionado === 'grafeno' || $layout_selecionado === 'sicoob' || $layout_selecionado === 'caixa_federal' || $layout_selecionado === 'registros')
                        <div>
                            <label for="conta_banco" class="block text-sm font-medium text-gray-700 mb-2">
                                Conta do Banco <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="conta_banco" wire:model="conta_banco" 
                                   placeholder="Ex: 1.1.1.01" 
                                   class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <p class="text-sm text-gray-500 mt-1">
                                Esta conta será usada para lançamentos de débito (recebimentos) e crédito (pagamentos)
                            </p>
                            @error('conta_banco') 
                                <span class="text-red-500 text-sm">{{ $message }}</span> 
                            @enderror
                        </div>
                    @endif

                    <!-- Upload de Arquivo -->
                    <div>
                        <label for="arquivo" class="block text-sm font-medium text-gray-700 mb-2">
                            Arquivo para Importação
                        </label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600">
                                    <label for="arquivo" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                        <span>Fazer upload de um arquivo</span>
                                        <input id="arquivo" wire:model="arquivo" type="file" class="sr-only" accept=".csv,.txt,.pdf,.ofx">
                                    </label>
                                    <p class="pl-1">ou arraste e solte</p>
                                </div>
                                <p class="text-xs text-gray-500">
                                    CSV, TXT, PDF ou OFX até 10MB
                                </p>
                            </div>
                        </div>
                        @if($arquivo)
                            <div class="mt-2 text-sm text-gray-600">
                                Arquivo selecionado: {{ $arquivo->getClientOriginalName() }}
                            </div>
                        @endif
                        @error('arquivo') 
                            <span class="text-red-500 text-sm">{{ $message }}</span> 
                        @enderror
                    </div>

                    <!-- Botão de Processamento -->
                    <div class="flex justify-end">
                        <button type="submit" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50"
                                {{ $status_importacao === 'processando' ? 'disabled' : '' }}>
                            {{ $status_importacao === 'processando' ? 'Processando...' : 'Processar Arquivo' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Status e Progresso -->
            @if($status_importacao !== 'pendente')
                <div class="p-6 border-t border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Status da Importação</h3>
                    
                    <!-- Barra de Progresso -->
                    <div class="mb-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Progresso</span>
                            <span>{{ $progresso }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                 style="width: {{ $progresso }}%"></div>
                        </div>
                        
                        @if($status_importacao === 'processando' && $totalLinhas > 0)
                            <div class="mt-2 text-sm text-gray-600">
                                <p>Linha atual: {{ $linhaAtual }} de {{ $totalLinhas }}</p>
                                <p>Processando registros...</p>
                            </div>
                        @endif
                    </div>

                    <!-- Mensagem de Status -->
                    <div class="p-4 rounded-md 
                        @if($status_importacao === 'processando') bg-blue-50 text-blue-700
                        @elseif($status_importacao === 'concluida') bg-green-50 text-green-700
                        @elseif($status_importacao === 'erro') bg-red-50 text-red-700
                        @endif">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                @if($status_importacao === 'processando')
                                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                @elseif($status_importacao === 'concluida')
                                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                @elseif($status_importacao === 'erro')
                                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                    </svg>
                                @endif
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium">
                                    {{ $mensagem_status }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Informações da Importação Concluída -->
                    @if($status_importacao === 'concluida')
                        <div class="mt-4 p-4 bg-green-50 border border-green-200 rounded-md">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-sm font-medium text-green-800">Importação Concluída</h4>
                                    <p class="text-sm text-green-700 mt-1">
                                        Total de registros importados: <strong>{{ number_format($total_registros_importados, 0, ',', '.') }}</strong>
                                    </p>
                                    @if($importacao_id)
                                        <p class="text-sm text-green-700">
                                            ID da Importação: <strong>{{ $importacao_id }}</strong>
                                        </p>
                                    @endif
                                </div>
                                <div class="flex space-x-2">
                                    <a href="{{ route('tabela', ['importacao' => $importacao_id]) }}" 
                                       class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm">
                                        Ver Lançamentos
                                    </a>
                                    <button wire:click="resetarImportacao" 
                                            class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                                        Nova Importação
                                    </button>
                                </div>
                            </div>
                        </div>
                    @elseif($status_importacao === 'erro')
                        <div class="mt-4">
                            <button wire:click="resetarImportacao" 
                                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                Nova Importação
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
