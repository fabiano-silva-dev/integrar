<div class="p-6">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-900 mb-2">
                    PDF para OFX
                </h2>
                <p class="text-gray-600 mb-6">
                    Selecione a instituição do extrato, envie o PDF e baixe o arquivo OFX gerado.
                    <a href="{{ route('conversoes-extrato') }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Ver histórico de conversões</a>
                </p>

                <form wire:submit.prevent="converter" class="space-y-6">
                    <div>
                        <label for="familia_layout" class="block text-sm font-medium text-gray-700 mb-2">
                            Instituição / Origem do Arquivo
                        </label>
                        <select id="familia_layout" wire:model.live="familia_layout"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Selecione a origem...</option>
                            @foreach($familiasLayout as $valor => $nome)
                                <option value="{{ $valor }}">{{ $nome }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if(!empty($familia_layout))
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Layout{{ count($layoutsDisponiveis) > 1 ? 's' : '' }} disponível{{ count($layoutsDisponiveis) > 1 ? 'eis' : '' }}
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                @foreach($layoutsDisponiveis as $valor => $nome)
                                    <label class="relative border rounded-lg p-3 cursor-pointer transition-all
                                        {{ $layout_selecionado === $valor ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300 bg-white' }}">
                                        <div class="flex items-start gap-3">
                                            <input type="radio"
                                                wire:model.live="layout_selecionado"
                                                value="{{ $valor }}"
                                                class="mt-1 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900">{{ $nome }}</p>
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div>
                        @error('layout_selecionado')
                            <span class="text-red-500 text-sm">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Arquivo PDF do extrato
                        </label>
                        <x-zona-upload
                            class="mt-1"
                            input-id="arquivo-pdf-ofx"
                            wire:model="arquivo"
                            accept=".pdf"
                            formato="PDF do extrato até 10 MB"
                            :nome-arquivo="$arquivo ? $arquivo->getClientOriginalName() : null"
                        />
                        @error('arquivo')
                            <span class="text-red-500 text-sm">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="flex gap-3">
                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:target="converter,arquivo"
                                @disabled($status === 'processando')
                                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 disabled:opacity-50">
                            <span wire:loading.remove wire:target="converter">Converter para OFX</span>
                            <span wire:loading wire:target="converter">Convertendo...</span>
                        </button>

                        @if($arquivo_processado || $status === 'erro')
                            <button type="button"
                                    wire:click="resetar"
                                    class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300">
                                Nova conversão
                            </button>
                        @endif
                    </div>
                </form>

                @if($status !== 'pendente')
                    <div class="mt-8">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Status da conversão</h3>

                        <div class="w-full bg-gray-200 rounded-full h-2.5 mb-4">
                            <div class="h-2.5 rounded-full transition-all duration-500
                                {{ $status === 'erro' ? 'bg-red-500' : ($status === 'concluida' ? 'bg-green-500' : 'bg-indigo-600') }}"
                                 style="width: {{ $progresso }}%"></div>
                        </div>

                        <p class="text-sm {{ $status === 'erro' ? 'text-red-600' : ($status === 'concluida' ? 'text-green-600' : 'text-gray-700') }}">
                            {{ $mensagem_status }}
                        </p>

                        @if($status === 'concluida' && $arquivo_processado)
                            <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                                <h4 class="text-sm font-medium text-green-800 mb-2">Conversão concluída</h4>
                                <ul class="text-sm text-green-700 space-y-1">
                                    @if($cooperativa_extraida)
                                        <li>Agência/Cooperativa: <strong>{{ $cooperativa_extraida }}</strong></li>
                                    @endif
                                    @if($numero_conta_extraido)
                                        <li>Conta extraída do PDF: <strong>{{ $numero_conta_extraido }}</strong></li>
                                    @endif
                                    @if($titular_extraido)
                                        <li>Titular: <strong>{{ $titular_extraido }}</strong></li>
                                    @endif
                                    @if($data_inicial)
                                        <li>Data inicial: <strong>{{ $data_inicial }}</strong></li>
                                    @endif
                                    @if($data_final)
                                        <li>Data final: <strong>{{ $data_final }}</strong></li>
                                    @endif
                                    @if($total_lancamentos > 0)
                                        <li>Lançamentos convertidos: <strong>{{ $total_lancamentos }}</strong></li>
                                    @endif
                                    <li>Arquivo: <strong>{{ $arquivo_gerado }}</strong></li>
                                </ul>
                                <button type="button"
                                        wire:click="downloadArquivo"
                                        class="mt-4 inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700">
                                    Baixar arquivo OFX
                                </button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
