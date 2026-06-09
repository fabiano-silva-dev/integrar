<div class="p-6">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-900 mb-2">
                    PDF para OFX — Sicoob
                </h2>
                <p class="text-gray-600 mb-6">
                    Envie o extrato PDF do Sicoob (mesmo formato usado na importação) e baixe o arquivo OFX gerado.
                </p>

                <form wire:submit.prevent="converter" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Arquivo PDF do extrato Sicoob
                        </label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-indigo-400 transition-colors">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label for="arquivo" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500">
                                        <span>Selecionar arquivo</span>
                                        <input id="arquivo" wire:model="arquivo" type="file" class="sr-only" accept=".pdf">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PDF do extrato Sicoob até 10MB</p>
                                @if($arquivo)
                                    <p class="text-sm text-green-600 font-medium">{{ $arquivo->getClientOriginalName() }}</p>
                                @endif
                            </div>
                        </div>
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
                                        <li>Cooperativa: <strong>{{ $cooperativa_extraida }}</strong></li>
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
