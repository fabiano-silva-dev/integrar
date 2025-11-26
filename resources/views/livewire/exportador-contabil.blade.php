<div class="max-w-4xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Exportador Contábil</h2>
        
        @if($mensagem)
            <div class="mb-4 p-4 rounded-lg {{ str_contains($mensagem, 'Erro') ? 'bg-red-100 text-red-700' : (str_contains($mensagem, 'ATENÇÃO') ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700') }}">
                {{ $mensagem }}
            </div>
        @endif

        @if($mostrarAvisoContasVazias)
            <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">Atenção: Lançamentos com Contas Vazias</h3>
                        <div class="mt-2 text-sm text-yellow-700">
                            <p>Foram encontrados <strong>{{ count($lancamentosComContasVazias) }}</strong> lançamento(s) com conta débito ou crédito vazias que serão exportados.</p>
                            <p class="mt-2">Para corrigir essas informações, acesse a tabela de lançamentos:</p>
                            <div class="mt-3">
                                <a href="{{ route('tabela') }}{{ $importacaoId ? '?importacao=' . $importacaoId : '' }}" 
                                   class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                    <svg class="ml-2 -mr-0.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                    Ir para Tabela de Lançamentos
                                    @if($importacaoId)
                                        (Filtrado por esta importação)
                                    @endif
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="space-y-6">
            <!-- Seleção de arquivo importado -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Arquivo Importado</label>
                <select wire:change="selecionarImportacao($event.target.value)" class="w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">Selecione um arquivo importado (opcional)</option>
                    @foreach($importacoes as $importacao)
                        <option value="{{ $importacao->id }}" {{ $importacaoId == $importacao->id ? 'selected' : '' }}>
                            {{ $importacao->nome_arquivo }} 
                            @if($importacao->empresa)
                                - {{ $importacao->empresa->nome }}
                            @endif
                            ({{ $importacao->data_inicial }} a {{ $importacao->data_final }})
                            - {{ $importacao->created_at->format('d/m/Y H:i') }}
                        </option>
                    @endforeach
                </select>
            </div>
            
            <!-- Informações da Importação Selecionada -->
            @if($importacaoId)
                @php $importacaoSelecionada = $importacoes->find($importacaoId) @endphp
                @if($importacaoSelecionada)
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h3 class="font-semibold text-blue-800 mb-3">Informações da Importação</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-blue-700"><strong>Arquivo:</strong> {{ $importacaoSelecionada->nome_arquivo }}</p>
                                <p class="text-blue-700"><strong>Empresa:</strong> 
                                    @if($importacaoSelecionada->empresa)
                                        {{ $importacaoSelecionada->empresa->nome }}
                                        @if($importacaoSelecionada->empresa->codigo_sistema)
                                            (Código: {{ $importacaoSelecionada->empresa->codigo_sistema }})
                                        @endif
                                    @else
                                        <span class="text-gray-500">Não informada</span>
                                    @endif
                                </p>
                                <p class="text-blue-700"><strong>Período:</strong> {{ $importacaoSelecionada->data_inicial }} a {{ $importacaoSelecionada->data_final }}</p>
                            </div>
                            <div>
                                <p class="text-blue-700"><strong>Data de Importação:</strong> {{ $importacaoSelecionada->created_at->format('d/m/Y H:i:s') }}</p>
                                <p class="text-blue-700"><strong>Status:</strong> 
                                    <span class="px-2 py-1 text-xs rounded-full 
                                        @if($importacaoSelecionada->status === 'concluida') bg-green-100 text-green-800
                                        @elseif($importacaoSelecionada->status === 'processando') bg-yellow-100 text-yellow-800
                                        @elseif($importacaoSelecionada->status === 'erro') bg-red-100 text-red-800
                                        @else bg-gray-100 text-gray-800
                                        @endif">
                                        {{ ucfirst($importacaoSelecionada->status) }}
                                    </span>
                                </p>
                                @if($importacaoSelecionada->total_registros)
                                    <p class="text-blue-700"><strong>Registros Importados:</strong> {{ number_format($importacaoSelecionada->total_registros, 0, ',', '.') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endif
            
            <!-- Período -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Data Início</label>
                    <input type="date" wire:model.live="dataInicio" class="w-full rounded-md border-gray-300 shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Data Fim</label>
                    <input type="date" wire:model.live="dataFim" class="w-full rounded-md border-gray-300 shadow-sm">
                </div>
            </div>

            <!-- Formato -->
            <!-- REMOVIDO: opções de formato -->

            <!-- Layout -->
            <!-- REMOVIDO: opções de layout -->

            <!-- Campos específicos para Domínio -->
            @if(true)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-blue-50 rounded-lg">
                    @if(empty($codigoEmpresa) || empty($cnpjEmpresa))
                        <div class="col-span-2 mb-4 p-3 bg-yellow-100 border border-yellow-400 rounded-lg">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-yellow-800">Atenção</h3>
                                    <div class="mt-2 text-sm text-yellow-700">
                                        <p>Para o layout Domínio, os campos <strong>Código da Empresa</strong> e <strong>CNPJ da Empresa</strong> são obrigatórios.</p>
                                        @if($importacaoId)
                                            <p class="mt-1">Selecione uma importação que tenha empresa associada ou preencha manualmente.</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Seleção manual de empresa -->
                        <div class="col-span-2 mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Selecionar Empresa</label>
                            <select wire:model="empresaSelecionada" class="w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">Selecione uma empresa...</option>
                                @foreach($empresas as $empresa)
                                    <option value="{{ $empresa->id }}">
                                        {{ $empresa->nome }} (Código: {{ $empresa->codigo_sistema }}, CNPJ: {{ $empresa->cnpj }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">Selecione uma empresa para preencher automaticamente os campos obrigatórios.</p>
                        </div>
                    @endif
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Código da Empresa</label>
                        <input type="text" wire:model="codigoEmpresa" placeholder="Ex: 0000001" class="w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">CNPJ da Empresa</label>
                        <input type="text" wire:model="cnpjEmpresa" placeholder="00.000.000/0000-00" class="w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Nota</label>
                        <select wire:model="tipoNota" class="w-full rounded-md border-gray-300 shadow-sm">
                            <option value="01">01 - Contabilidade</option>
                            <option value="02">02 - Entradas</option>
                            <option value="03">03 - Saídas</option>
                            <option value="04">04 - Serviços</option>
                            <option value="05">05 - Contabilidade-Lançamentos em lote</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Sistema</label>
                        <select wire:model="sistema" class="w-full rounded-md border-gray-300 shadow-sm">
                            <option value="1">1 - Contabilidade</option>
                            <option value="2">2 - Caixa</option>
                            <option value="0">0 - Outro</option>
                        </select>
                    </div>
                </div>
            @endif

            <!-- Quantidade de Registros -->
            @if($importacaoId || ($dataInicio && $dataFim))
                <div class="bg-green-50 p-4 rounded-lg">
                    <h3 class="font-semibold text-green-800 mb-2">Resumo da Exportação</h3>
                    <div class="text-sm text-green-700">
                        <p><strong>Registros que serão exportados:</strong> {{ number_format($this->getQuantidadeRegistros(), 0, ',', '.') }} lançamento(s)</p>
                        @if($mostrarAvisoContasVazias)
                            <p class="text-yellow-700"><strong>⚠️ Lançamentos com contas vazias:</strong> {{ count($lancamentosComContasVazias) }}</p>
                        @endif
                        @if($importacaoId)
                            @php $importacao = $importacoes->find($importacaoId) @endphp
                            @if($importacao)
                                <p><strong>Arquivo:</strong> {{ $importacao->nome_arquivo }}</p>
                                <p><strong>Empresa:</strong> 
                                    @if($importacao->empresa)
                                        {{ $importacao->empresa->nome }}
                                    @else
                                        <span class="text-gray-500">Não informada</span>
                                    @endif
                                </p>
                                <p><strong>Período:</strong> {{ $importacao->data_inicial }} a {{ $importacao->data_final }}</p>
                                <p><strong>Data de Importação:</strong> {{ $importacao->created_at->format('d/m/Y H:i') }}</p>
                            @endif
                        @else
                            <p><strong>Período:</strong> {{ $dataInicio }} a {{ $dataFim }}</p>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Descrição dos Layouts -->
            <!-- REMOVIDO: descrição dos layouts -->

            <button 
                type="button"
                wire:click="exportar"
                wire:loading.attr="disabled"
                class="w-full bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <span wire:loading.remove>Exportar Lançamentos</span>
                <span wire:loading>Gerando arquivo...</span>
            </button>
        </div>

        @if($arquivoGerado)
            <div class="mt-6 p-4 bg-green-50 rounded-lg">
                <h3 class="font-semibold text-green-800 mb-2">Arquivo Gerado</h3>
                <div class="mb-3">
                    <p class="text-green-700"><strong>Nome do arquivo:</strong> {{ $arquivoGerado }}</p>
                    <p class="text-green-700"><strong>Registros exportados:</strong> {{ number_format($quantidadeRegistros, 0, ',', '.') }} lançamento(s)</p>
                </div>
                <a 
                    href="{{ route('download.arquivo', ['arquivo' => $arquivoGerado]) }}" 
                    class="inline-block bg-green-600 text-white py-2 px-4 rounded hover:bg-green-700"
                >
                    Download do Arquivo
                </a>
            </div>
        @endif
    </div>
</div>


