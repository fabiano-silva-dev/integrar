<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold">Importação Personalizada</h2>
                    @if($processando)
                        <div class="flex items-center text-blue-600">
                            <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600 mr-2"></div>
                            <span class="text-sm font-medium">Processando arquivo...</span>
                        </div>
                    @endif
                </div>

                <!-- Progress Steps -->
                <div class="mb-8">
                    @if($processando)
                    <div class="text-center mb-4">
                        <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-full">
                            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600 mr-2"></div>
                            <span class="text-sm font-medium">Processando arquivo...</span>
                        </div>
                    </div>
                    @endif
                    
                    <div class="flex items-center justify-center">
                        <div class="flex items-center space-x-16">
                            <div class="flex items-center relative">
                                <div class="rounded-full transition duration-500 ease-in-out h-12 w-12 py-3 border-2 {{ $step >= 1 ? 'bg-blue-600 border-blue-600' : 'border-gray-300' }} text-white text-center">
                                    <svg class="w-6 h-6 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="absolute top-0 left-1/2 transform -translate-x-1/2 mt-16 w-20 text-center text-xs font-medium text-gray-500">Upload</div>
                            </div>
                            <div class="flex-auto border-t-2 transition duration-500 ease-in-out {{ $step >= 2 ? 'border-blue-600' : 'border-gray-300' }}"></div>
                            <div class="flex items-center relative">
                                <div class="rounded-full transition duration-500 ease-in-out h-12 w-12 py-3 border-2 {{ $step >= 2 ? 'bg-blue-600 border-blue-600' : 'border-gray-300' }} text-white text-center">
                                    <svg class="w-6 h-6 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="absolute top-0 left-1/2 transform -translate-x-1/2 mt-16 w-20 text-center text-xs font-medium text-gray-500">Mapeamento</div>
                            </div>
                            <div class="flex-auto border-t-2 transition duration-500 ease-in-out {{ $step >= 3 ? 'border-blue-600' : 'border-gray-300' }}"></div>
                            <div class="flex items-center relative">
                                <div class="rounded-full transition duration-500 ease-in-out h-12 w-12 py-3 border-2 {{ $step >= 3 ? 'bg-blue-600 border-blue-600' : 'border-gray-300' }} text-white text-center">
                                    <svg class="w-6 h-6 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="absolute top-0 left-1/2 transform -translate-x-1/2 mt-16 w-20 text-center text-xs font-medium text-gray-500">Prévia</div>
                            </div>
                            <div class="flex-auto border-t-2 transition duration-500 ease-in-out {{ $step >= 4 ? 'border-blue-600' : 'border-gray-300' }}"></div>
                            <div class="flex items-center relative">
                                <div class="rounded-full transition duration-500 ease-in-out h-12 w-12 py-3 border-2 {{ $step >= 4 ? 'bg-blue-600 border-blue-600' : 'border-gray-300' }} text-white text-center">
                                    <svg class="w-6 h-6 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="absolute top-0 left-1/2 transform -translate-x-1/2 mt-16 w-20 text-center text-xs font-medium text-gray-500">Concluir</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 1: Upload -->
                @if($step == 1)
                <div class="space-y-6">
                    @if (session()->has('error'))
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                            </svg>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Erro ao processar arquivo</h3>
                                <p class="mt-1 text-sm text-red-700">{{ session('error') }}</p>
                            </div>
                        </div>
                    </div>
                    @endif
                    @if($processando)
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-yellow-600 mr-3"></div>
                        <div>
                            <h3 class="text-sm font-medium text-yellow-800">Processando arquivo...</h3>
                            <p class="text-sm text-yellow-700">Aguarde enquanto analisamos a estrutura do arquivo</p>
                        </div>
                    </div>
                </div>
                @endif
                
                <div wire:ignore.self>
                    <div x-data="{ 
                        processando: @entangle('processando'),
                        mensagem: 'Analisando estrutura do arquivo...'
                    }" 
                    x-init="
                        Livewire.on('previa-carregada', () => {
                            mensagem = 'Prévia carregada com sucesso!';
                            setTimeout(() => {
                                processando = false;
                            }, 1000);
                        });
                    ">
                        <div x-show="processando" class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center">
                                <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600 mr-3"></div>
                                <div>
                                    <h3 class="text-sm font-medium text-blue-800">Carregando prévia...</h3>
                                    <p class="text-sm text-blue-700" x-text="mensagem"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-blue-800 mb-2">Layouts Disponíveis</h3>
                        @if($empresa_id)
                            @if($layoutsDisponiveis->count() > 0)
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                                    @foreach($layoutsDisponiveis as $layout)
                                        <div class="border rounded-lg p-3 hover:bg-gray-50 cursor-pointer" wire:click="carregarLayout({{ $layout->id }})">
                                            <div class="font-medium">{{ $layout->nome }}</div>
                                            <div class="text-sm text-gray-600">{{ strtoupper($layout->tipo_arquivo) }}</div>
                                            <div class="text-xs text-gray-500">{{ $layout->colunas->count() }} colunas mapeadas</div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-gray-600">Nenhum layout salvo encontrado para esta empresa.</p>
                            @endif
                        @else
                            <p class="text-gray-600">Selecione uma empresa para ver os layouts disponíveis.</p>
                        @endif
                    </div>

                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                            <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div class="mt-4">
                            @if($processando)
                                <button disabled class="bg-blue-400 cursor-not-allowed text-white font-medium py-2 px-4 rounded-md flex items-center mx-auto">
                                    <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                    Processando...
                                </button>
                            @else
                                <label for="arquivo" class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    Selecionar Arquivo
                                </label>
                                <input wire:model="arquivo" type="file" id="arquivo" class="hidden" accept=".csv,.xls,.xlsx">
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-gray-600">
                            CSV, XLS ou XLSX (máx. 10MB)
                        </p>
                        @error('arquivo') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>

                    @if($arquivo)
                        @if($processando)
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600"></div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800">Processando arquivo...</h3>
                                    <div class="mt-2 text-sm text-blue-700">
                                        <p>Analisando estrutura e detectando colunas...</p>
                                        <p class="text-xs text-blue-600">Por favor, aguarde...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @else
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                            <div class="flex items-center">
                                <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-green-800">Arquivo selecionado</h3>
                                    <div class="mt-2 text-sm text-green-700">
                                        <p>{{ $arquivo->getClientOriginalName() }}</p>
                                        <p>Tamanho: {{ number_format($arquivo->getSize() / 1024, 2) }} KB</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif
                    @endif
                </div>
                @endif

                <!-- Step 2: Mapeamento -->
                @if($step == 2)
                <div class="space-y-6">
                                    @if (session()->has('error'))
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                            </svg>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Erro</h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <p>{{ session('error') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if (session()->has('message'))
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-green-800">Sucesso</h3>
                                <div class="mt-2 text-sm text-green-700">
                                    <p>{{ session('message') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if($colunasIncompatíveis && !$colunasIncompatíveis['total_compativel'])
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-orange-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-orange-800">Incompatibilidade de Colunas</h3>
                            <div class="mt-2 text-sm text-orange-700">
                                <p class="mb-2">Algumas colunas da regra não foram encontradas no arquivo atual:</p>
                                <ul class="list-disc list-inside space-y-1">
                                    @foreach($colunasIncompatíveis['colunas_nao_encontradas'] as $coluna)
                                        <li>
                                            <strong>{{ $coluna }}</strong>
                                            @if(isset($colunasIncompatíveis['sugestoes'][$coluna]))
                                                <span class="text-gray-600"> → Sugestão: <strong>{{ $colunasIncompatíveis['sugestoes'][$coluna] }}</strong></span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="mt-2 text-xs">A regra foi aplicada parcialmente. Verifique e ajuste o mapeamento conforme necessário.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold mb-4">Configurações do Arquivo</h3>
                        <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md">
                            <h4 class="text-sm font-medium text-blue-800 mb-2">Formatação de Valores</h4>
                            <ul class="text-xs text-blue-700 space-y-1">
                                <li>• Símbolos de moeda (R$, $, €, £) são automaticamente removidos</li>
                                <li>• Valores negativos podem ser convertidos para positivos (opcional)</li>
                                <li>• Suporte a formatos brasileiros (1.234,56) e americanos (1,234.56)</li>
                            </ul>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Empresa <span class="text-red-500">*</span>
                                </label>
                                <select wire:model="empresa_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 {{ !$empresa_id ? 'border-red-300' : '' }}">
                                    <option value="">Selecione uma empresa</option>
                                    @foreach($empresas as $empresa)
                                        <option value="{{ $empresa->id }}">{{ $empresa->nome }}</option>
                                    @endforeach
                                </select>
                                @if(!$empresa_id)
                                    <p class="mt-1 text-sm text-red-600">Empresa é obrigatória</p>
                                @endif
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Nome do Layout</label>
                                <input wire:model="nomeLayout" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Tipo de Arquivo</label>
                                <input type="text" value="{{ strtoupper($tipoArquivo) }}" readonly class="mt-1 block w-full bg-gray-100 border-gray-300 rounded-md shadow-sm">
                            </div>
                            @if($tipoArquivo == 'csv')
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Delimitador</label>
                                <select wire:model="delimitador" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value=",">Vírgula (,)</option>
                                    <option value=";">Ponto e vírgula (;)</option>
                                    <option value="\t">Tabulação (\t)</option>
                                    <option value="|">Pipe (|)</option>
                                </select>
                            </div>
                            @endif
                            <div>
                                <label class="flex items-center">
                                    <input wire:model.live="temCabecalho" type="checkbox" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-700">Arquivo possui linha de cabeçalho</span>
                                </label>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Número da linha do cabeçalho</label>
                                <input wire:model.live.debounce.300ms="linhaCabecalho" type="number" min="1" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="1">
                                <p class="mt-1 text-xs text-gray-500">Linha onde estão os nomes das colunas (ex: 7 = cabeçalho na 7ª linha)</p>
                            </div>
                            <div>
                                <label class="flex items-center">
                                    <input wire:model="converterNegativosParaPositivos" type="checkbox" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-700">Converter valores negativos para positivos</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Seleção de Regras Existentes -->
                    @if($empresa_id && count($regrasDisponiveis) > 0)
                    <div class="bg-yellow-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold mb-4">Regras Salvas</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Selecionar Regra Existente</label>
                                <select wire:model="regraSelecionada" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Selecione uma regra...</option>
                                    @foreach($regrasDisponiveis as $regra)
                                        <option value="{{ $regra->id }}">{{ $regra->nome_regra }} ({{ $regra->tipo }})</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($regraSelecionada)
                            <div class="flex gap-2">
                                <button wire:click="aplicarRegraSelecionada" type="button" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    Aplicar Regra
                                </button>
                                <button wire:click="selecionarRegra(null)" type="button" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    Limpar Seleção
                                </button>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    {{-- Funcionalidade temporariamente oculta
                    <div class="bg-blue-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold mb-4">Regras Automáticas (Opcional)</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="flex items-center">
                                    <input wire:model="aplicarRegrasAutomaticas" type="checkbox" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-700">Aplicar regras automáticas de débito/crédito</span>
                                </label>
                            </div>
                            @if($aplicarRegrasAutomaticas)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Coluna de Descrição</label>
                                <select wire:model="colunaDescricao" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Selecione uma coluna</option>
                                    @foreach($colunasArquivo as $coluna)
                                        <option value="{{ $coluna }}">{{ $coluna }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                        </div>
                    </div>
                    --}}

                    <!-- Regras de Amarração -->
                    <div class="bg-green-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold mb-4">Regras de Amarração</h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center">
                                <div class="flex items-start gap-2">
                                    <p class="text-sm text-gray-600">Configure regras para mapeamento automático ou manual</p>
                                    <div class="group relative flex-shrink-0">
                                        <svg class="w-4 h-4 text-gray-400 hover:text-gray-600 cursor-help" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                        </svg>
                                        <div class="absolute left-6 top-0 hidden group-hover:block w-72 p-3 bg-gray-800 text-white text-xs rounded-lg shadow-lg z-10">
                                            <p class="font-semibold mb-2">Automática:</p>
                                            <p class="mb-2">Os valores vêm das colunas do arquivo. Você mapeia colunas (Data, Valor, etc.) e pode criar vários lançamentos por linha com contas diferentes para cada valor.</p>
                                            <p class="font-semibold mb-2">Manual:</p>
                                            <p>Conta débito, crédito e histórico são fixos (iguais para todas as linhas). Apenas a data vem do arquivo. Use quando todos os lançamentos vão para as mesmas contas.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    @if(!empty($regraAtual['nome_regra']) || !empty($regraAtual['coluna_data']) || !empty($regraAtual['conta_debito_fixa']))
                                        <button wire:click="salvarRegra" type="button" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                            Salvar Regra
                                        </button>
                                    @endif
                                    <button wire:click="adicionarRegra" type="button" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        + Adicionar Regra
                                    </button>
                                </div>
                            </div>

                            <!-- Regras existentes -->
                            @foreach($regrasAmarracao as $indice => $regra)
                            <div class="bg-white border rounded-lg p-4">
                                <div class="flex justify-between items-center mb-4">
                                    <h4 class="font-medium">{{ $regra['nome_regra'] ?: 'Regra ' . ($indice + 1) }}</h4>
                                    <button wire:click="removerRegra({{ $indice }})" type="button" class="text-red-600 hover:text-red-800">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Nome da Regra</label>
                                        <input wire:model="regrasAmarracao.{{ $indice }}.nome_regra" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Tipo</label>
                                        <select wire:model="regrasAmarracao.{{ $indice }}.tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" title="Automática: valores do arquivo. Manual: contas fixas, só data do arquivo.">
                                            <option value="automatica">Automática</option>
                                            <option value="manual">Manual</option>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500">{{ $regra['tipo'] === 'automatica' ? 'Valores vêm das colunas do arquivo' : 'Contas fixas, apenas data do arquivo' }}</p>
                                    </div>
                                </div>

                                @if($regra['tipo'] === 'automatica')
                                <!-- Mapeamento Automático -->
                                <div class="mt-4 grid grid-cols-1 md:grid-cols-1 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Coluna Data <span class="text-red-500">*</span></label>
                                        <select wire:model.live="regrasAmarracao.{{ $indice }}.coluna_data" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 {{ empty($regra['coluna_data']) ? 'border-red-300' : '' }}">
                                            <option value="">Selecione</option>
                                            @foreach($colunasArquivo as $coluna)
                                                <option value="{{ $coluna }}">{{ $coluna }}</option>
                                            @endforeach
                                        </select>
                                        @if(empty($regra['coluna_data']))
                                            <p class="mt-1 text-sm text-red-600">Coluna Data é obrigatória</p>
                                        @endif
                                    </div>
                                </div>

                                <!-- Múltiplos Valores -->
                                @if(!empty($regra['coluna_data']) && $regra['coluna_data'] !== '' && strlen($regra['coluna_data']) > 0)
                                <div class="mt-4">
                                    <div class="flex justify-between items-center mb-2">
                                        <label class="block text-sm font-medium text-gray-700">Múltiplos Valores</label>
                                        <button wire:click="adicionarValorMultiplo({{ $indice }})" type="button" class="text-blue-600 hover:text-blue-800 text-sm">
                                            + Adicionar mais lançamentos por linha
                                        </button>
                                    </div>
                                    @foreach($regra['colunas_valores'] as $valorIndice => $valor)
                                    <div class="grid grid-cols-1 md:grid-cols-6 gap-2 mb-2">
                                        <div>
                                            <label class="block text-xs text-gray-600">Coluna Valor</label>
                                            <select wire:model="regrasAmarracao.{{ $indice }}.colunas_valores.{{ $valorIndice }}" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                                <option value="">Selecione</option>
                                                @foreach($colunasArquivo as $coluna)
                                                    <option value="{{ $coluna }}">{{ $coluna }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-600">Conta Débito</label>
                                            <input wire:model="regrasAmarracao.{{ $indice }}.contas_debito.{{ $valorIndice }}" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-600">Conta Crédito</label>
                                            <input wire:model="regrasAmarracao.{{ $indice }}.contas_credito.{{ $valorIndice }}" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-xs text-gray-600">Histórico</label>
                                            <input wire:model="regrasAmarracao.{{ $indice }}.historicos.{{ $valorIndice }}" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        </div>
                                        <div class="flex items-end">
                                            <button wire:click="removerValorMultiplo({{ $valorIndice }}, {{ $indice }})" type="button" class="text-red-600 hover:text-red-800 text-sm">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    @endforeach
                                </div>
                                @endif
                                @else
                                <!-- Mapeamento Manual -->
                                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Conta Débito Fixa</label>
                                        <input wire:model="regrasAmarracao.{{ $indice }}.conta_debito_fixa" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Conta Crédito Fixa</label>
                                        <input wire:model="regrasAmarracao.{{ $indice }}.conta_credito_fixa" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Histórico Fixo</label>
                                        <input wire:model="regrasAmarracao.{{ $indice }}.historico_fixo" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Centro de Custo Fixo</label>
                                        <input wire:model="regrasAmarracao.{{ $indice }}.centro_custo_fixo" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                                @endif
                            </div>
                            @endforeach

                            <!-- Nova regra -->
                            @if(!empty($regraAtual['nome_regra']) || !empty($regraAtual['coluna_data']) || !empty($regraAtual['conta_debito_fixa']))
                            <div class="bg-blue-50 border-2 border-dashed border-blue-300 rounded-lg p-4">
                                <div class="flex justify-between items-center mb-4">
                                    <h4 class="font-medium text-blue-800">Nova Regra</h4>
                                    <div class="space-x-2">
                                        <button wire:click="adicionarRegra" type="button" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                            Salvar Regra
                                        </button>
                                        <button wire:click="resetarRegraAtual" type="button" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                            Cancelar
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Nome da Regra</label>
                                        <input wire:model="regraAtual.nome_regra" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Tipo</label>
                                        <select wire:model="regraAtual.tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" title="Automática: valores do arquivo. Manual: contas fixas, só data do arquivo.">
                                            <option value="automatica">Automática</option>
                                            <option value="manual">Manual</option>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500">{{ ($regraAtual['tipo'] ?? 'automatica') === 'automatica' ? 'Valores vêm das colunas do arquivo' : 'Contas fixas, apenas data do arquivo' }}</p>
                                    </div>
                                </div>

                                @if($regraAtual['tipo'] === 'automatica')
                                <!-- Mapeamento Automático -->
                                <div class="mt-4 grid grid-cols-1 md:grid-cols-1 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Coluna Data <span class="text-red-500">*</span></label>
                                        <select wire:model.live="regraAtual.coluna_data" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 {{ empty($regraAtual['coluna_data']) ? 'border-red-300' : '' }}">
                                            <option value="">Selecione</option>
                                            @foreach($colunasArquivo as $coluna)
                                                <option value="{{ $coluna }}">{{ $coluna }}</option>
                                            @endforeach
                                        </select>
                                        @if(empty($regraAtual['coluna_data']))
                                            <p class="mt-1 text-sm text-red-600">Coluna Data é obrigatória</p>
                                        @endif
                                    </div>
                                </div>

                                <!-- Múltiplos Valores -->
                                @if(!empty($regraAtual['coluna_data']) && $regraAtual['coluna_data'] !== '' && strlen($regraAtual['coluna_data']) > 0)
                                <div class="mt-4">
                                    <div class="flex justify-between items-center mb-2">
                                        <label class="block text-sm font-medium text-gray-700">Múltiplos Valores</label>
                                        <button wire:click="adicionarValorMultiplo()" type="button" class="text-blue-600 hover:text-blue-800 text-sm">
                                            + Adicionar mais lançamentos por linha
                                        </button>
                                    </div>
                                    @foreach($regraAtual['colunas_valores'] as $valorIndice => $valor)
                                    <div class="multiples-valores-row">
                                        <div class="multiples-valores-coluna">
                                            <label class="block text-xs text-gray-600">Coluna Valor</label>
                                            <select wire:model="regraAtual.colunas_valores.{{ $valorIndice }}" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                                <option value="">Selecione</option>
                                                @foreach($colunasArquivo as $coluna)
                                                    <option value="{{ $coluna }}">{{ $coluna }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="multiples-valores-conta">
                                            <label class="block text-xs text-gray-600">Conta Débito</label>
                                            <input wire:model="regraAtual.contas_debito.{{ $valorIndice }}" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        </div>
                                        <div class="multiples-valores-conta">
                                            <label class="block text-xs text-gray-600">Conta Crédito</label>
                                            <input wire:model="regraAtual.contas_credito.{{ $valorIndice }}" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        </div>
                                        <div class="multiples-valores-historico">
                                            <label class="block text-xs text-gray-600">Histórico</label>
                                            <input wire:model="regraAtual.historicos.{{ $valorIndice }}" type="text" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        </div>
                                        <div class="multiples-valores-botao">
                                            <button wire:click="removerValorMultiplo({{ $valorIndice }})" type="button" class="text-red-600 hover:text-red-800 text-sm">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    @endforeach
                                </div>
                                @endif
                                @else
                                <!-- Mapeamento Manual -->
                                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Conta Débito Fixa</label>
                                        <input wire:model="regraAtual.conta_debito_fixa" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Conta Crédito Fixa</label>
                                        <input wire:model="regraAtual.conta_credito_fixa" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Histórico Fixo</label>
                                        <input wire:model="regraAtual.historico_fixo" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Centro de Custo Fixo</label>
                                        <input wire:model="regraAtual.centro_custo_fixo" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                                @endif
                            </div>
                            @endif
                        </div>
                    </div>

                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-lg font-semibold mb-4">Mapeamento de Colunas</h3>
                        
                        <!-- Mapeamento e Prévia Integrados -->
                        <div class="mt-6" x-data="{
                            syncScroll(source, target) {
                                if (!target) return;
                                target.scrollLeft = source.scrollLeft;
                            }
                        }">
                            <h4 class="text-md font-semibold mb-3 text-gray-700">Mapeamento e Prévia do Arquivo</h4>

                            <!-- Scrollbar superior sincronizada -->
                            <div class="overflow-x-auto border-t border-l border-r rounded-t-lg" x-ref="topScroll"
                                 @scroll="syncScroll($event.target, $refs.bottomScroll)">
                                <div class="h-2"
                                     style="width: {{ max((count($colunasArquivo) + 1) * 160, 600) }}px">
                                </div>
                            </div>

                            <!-- Tabela com scrollbar inferior -->
                            <div class="overflow-x-auto border-b border-l border-r rounded-b-lg" x-ref="bottomScroll"
                                 @scroll="syncScroll($event.target, $refs.topScroll)">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-100">
                                        <!-- Cabeçalho com nomes das colunas -->
                                        <tr>
                                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-400 uppercase tracking-wider w-12">#</th>
                                            @foreach($colunasArquivo as $coluna)
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-600 uppercase tracking-wider min-w-[120px]">
                                                {{ $coluna }}
                                            </th>
                                            @endforeach
                                        </tr>
                                        <!-- Linha de mapeamento -->
                                        <tr class="bg-blue-50 border-t-2 border-blue-200">
                                            <td class="px-2 py-2 text-center border-r border-gray-200"></td>
                                            @foreach($colunasArquivo as $coluna)
                                            <td class="px-3 py-2 text-center border-r border-gray-200">
                                                <select wire:model="mapeamentoColunas.{{ $coluna }}" class="block w-full border-blue-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs bg-white">
                                                    <option value="">Não mapear</option>
                                                    <option value="data">Data</option>
                                                    <option value="valor">Valor</option>
                                                    <option value="descricao">Descrição</option>
                                                    <option value="conta_debito">Conta Débito</option>
                                                    <option value="conta_credito">Conta Crédito</option>
                                                    <option value="centro_custo">Centro de Custo</option>
                                                    <option value="documento">Documento</option>
                                                    <option value="historico">Histórico</option>
                                                </select>
                                            </td>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        @if(isset($dadosPrevia) && count($dadosPrevia) > 0)
                                            @php $linhaInicialDados = $linhaCabecalho + ($temCabecalho ? 1 : 0); @endphp
                                            @foreach(array_slice($dadosPrevia, 0, 10) as $linhaIndex => $linha)
                                            <tr class="{{ $linhaIndex % 2 == 0 ? 'bg-white' : 'bg-gray-50' }}">
                                                <td class="px-2 py-2 text-center text-xs text-gray-400 border-r border-gray-200">{{ $linhaInicialDados + $linhaIndex }}</td>
                                                @foreach($colunasArquivo as $colunaIndex => $coluna)
                                                <td class="px-3 py-2 text-xs text-gray-900 border-r border-gray-200">
                                                    @if(isset($linha[$colunaIndex]))
                                                        {{ is_string($linha[$colunaIndex]) ? Str::limit($linha[$colunaIndex], 30) : $linha[$colunaIndex] }}
                                                    @else
                                                        <span class="text-gray-400">-</span>
                                                    @endif
                                                </td>
                                                @endforeach
                                            </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td colspan="{{ count($colunasArquivo) + 1 }}" class="px-3 py-4 text-center text-sm text-gray-500">
                                                    Clique em "Gerar Prévia" para visualizar os dados
                                                </td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button wire:click="$set('step', 1)" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Voltar
                        </button>
                        <button wire:click="avancarParaPrevia" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Gerar Prévia
                        </button>
                    </div>
                </div>
                @endif

                <!-- Step 3: Prévia -->
                @if($step == 3)
                <div class="space-y-6">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                            </svg>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">Prévia dos Dados</h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>Mostrando {{ count($dadosPrevia) }} de {{ $totalLinhas }} registros encontrados.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if(count($dadosPrevia) > 0)
                    <div class="bg-white border rounded-lg overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">
                                            Identificação
                                        </th>
                                        @if(count($dadosPrevia) > 0)
                                            @php
                                                $primeiroItem = is_array($dadosPrevia[0]) && isset($dadosPrevia[0][0]) ? $dadosPrevia[0][0] : $dadosPrevia[0];
                                            @endphp
                                            @foreach(array_keys($primeiroItem) as $campo)
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider {{ $campo === 'valores_multiplos' ? 'w-auto' : ($campo === 'data' ? 'w-20' : 'w-32') }}">
                                                {{ ucfirst(str_replace('_', ' ', $campo)) }}
                                            </th>
                                            @endforeach
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($dadosPrevia as $linhaIndex => $linha)
                                        @if(is_array($linha) && isset($linha[0]))
                                            <!-- Múltiplos lançamentos por linha -->
                                            @foreach($linha as $lancamentoIndex => $lancamento)
                                            <tr class="{{ $lancamentoIndex > 0 ? 'bg-gray-50' : '' }}">
                                                <td class="px-4 py-3 text-xs text-gray-500 border-l-4 border-blue-500">
                                                    <div class="font-medium">Linha {{ $linhaIndex + 1 }}</div>
                                                    <div class="text-gray-400">Lançamento {{ $lancamentoIndex + 1 }}</div>
                                                </td>
                                                @foreach($lancamento as $campo => $valor)
                                                <td class="px-4 py-3 text-sm text-gray-900 {{ $campo === 'valores_multiplos' ? 'whitespace-normal' : 'whitespace-nowrap' }}">
                                                    @if($campo === 'valores_multiplos' && is_array($valor))
                                                        <div class="space-y-2">
                                                            @foreach($valor as $index => $item)
                                                                <div class="bg-blue-50 border border-blue-200 rounded p-2">
                                                                    <div class="flex items-center gap-4 text-xs">
                                                                        <div class="font-medium text-blue-800">Lançamento {{ $index + 1 }}</div>
                                                                        @if(isset($item['valor']))
                                                                            <div class="font-semibold text-green-700">R$ {{ $item['valor'] }}</div>
                                                                        @endif
                                                                        @if(isset($item['conta_debito']))
                                                                            <div><span class="text-gray-600">Débito:</span> {{ $item['conta_debito'] }}</div>
                                                                        @endif
                                                                        @if(isset($item['conta_credito']))
                                                                            <div><span class="text-gray-600">Crédito:</span> {{ $item['conta_credito'] }}</div>
                                                                        @endif
                                                                        @if(isset($item['historico']))
                                                                            <div class="text-gray-700 truncate flex-1" title="{{ $item['historico'] }}">{{ $item['historico'] }}</div>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @elseif($campo === 'data')
                                                        <div class="font-medium text-blue-600">{{ $valor }}</div>
                                                    @elseif($campo === 'valor')
                                                        <div class="font-medium text-green-600">R$ {{ $valor }}</div>
                                                    @else
                                                        <div class="truncate" title="{{ is_array($valor) ? json_encode($valor, JSON_PRETTY_PRINT) : $valor }}">
                                                            {{ is_array($valor) ? json_encode($valor, JSON_PRETTY_PRINT) : $valor }}
                                                        </div>
                                                    @endif
                                                </td>
                                                @endforeach
                                            </tr>
                                            @endforeach
                                        @else
                                            <!-- Lançamento único (estrutura antiga) -->
                                            <tr>
                                                <td class="px-4 py-3 text-xs text-gray-500 border-l-4 border-blue-500">
                                                    <div class="font-medium">Linha {{ $linhaIndex + 1 }}</div>
                                                    <div class="text-gray-400">Lançamento único</div>
                                                </td>
                                                @foreach($linha as $campo => $valor)
                                                <td class="px-4 py-3 text-sm text-gray-900 {{ $campo === 'valores_multiplos' ? 'whitespace-normal' : 'whitespace-nowrap' }}">
                                                    @if($campo === 'valores_multiplos' && is_array($valor))
                                                        <div class="space-y-2">
                                                            @foreach($valor as $index => $item)
                                                                <div class="bg-blue-50 border border-blue-200 rounded p-2">
                                                                    <div class="flex items-center gap-4 text-xs">
                                                                        <div class="font-medium text-blue-800">Lançamento {{ $index + 1 }}</div>
                                                                        @if(isset($item['valor']))
                                                                            <div class="font-semibold text-green-700">R$ {{ $item['valor'] }}</div>
                                                                        @endif
                                                                        @if(isset($item['conta_debito']))
                                                                            <div><span class="text-gray-600">Débito:</span> {{ $item['conta_debito'] }}</div>
                                                                        @endif
                                                                        @if(isset($item['conta_credito']))
                                                                            <div><span class="text-gray-600">Crédito:</span> {{ $item['conta_credito'] }}</div>
                                                                        @endif
                                                                        @if(isset($item['historico']))
                                                                            <div class="text-gray-700 truncate flex-1" title="{{ $item['historico'] }}">{{ $item['historico'] }}</div>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @elseif($campo === 'data')
                                                        <div class="font-medium text-blue-600">{{ $valor }}</div>
                                                    @elseif($campo === 'valor')
                                                        <div class="font-medium text-green-600">R$ {{ $valor }}</div>
                                                    @else
                                                        <div class="truncate" title="{{ is_array($valor) ? json_encode($valor, JSON_PRETTY_PRINT) : $valor }}">
                                                            {{ is_array($valor) ? json_encode($valor, JSON_PRETTY_PRINT) : $valor }}
                                                        </div>
                                                    @endif
                                                </td>
                                                @endforeach
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @else
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                            </svg>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Nenhum dado encontrado</h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <p>Verifique as configurações e o mapeamento de colunas.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="flex justify-end space-x-3">
                        <button wire:click="$set('step', 2)" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Voltar
                        </button>
                        @if(count($dadosPrevia) > 0)
                        <button wire:click="confirmarImportacao" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Confirmar Importação
                        </button>
                        @endif
                    </div>
                </div>
                @endif

                <!-- Step 4: Processando -->
                @if($step == 4)
                <div class="text-center py-12">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <h3 class="mt-4 text-lg font-medium text-gray-900">Processando importação...</h3>
                    <p class="mt-2 text-sm text-gray-600">Aguarde enquanto os dados são importados.</p>
                </div>
                @endif
            </div>
        </div>
    </div>
</div> 