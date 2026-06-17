<div class="p-6">
    <div class="max-w-5xl mx-auto">
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
                                                @if($valor === 'banrisul_enriquecido')
                                                    <p class="text-xs text-gray-500 mt-1">Extrato + relatório de PIX + relatório de pagamentos de títulos</p>
                                                @endif
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

                    @if(!empty($layout_selecionado))
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                1. Extrato em PDF
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

                        @if($layoutRequerAuxiliares)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    2. Relatório de PIX
                                </label>
                                <x-zona-upload
                                    class="mt-1"
                                    input-id="arquivo-pix-ofx"
                                    wire:model="arquivo_pix"
                                    accept=".pdf"
                                    formato="PDF do relatório de PIX até 10 MB"
                                    :nome-arquivo="$arquivo_pix ? $arquivo_pix->getClientOriginalName() : null"
                                />
                                @error('arquivo_pix')
                                    <span class="text-red-500 text-sm">{{ $message }}</span>
                                @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    3. Relatório de pagamentos de títulos
                                </label>
                                <x-zona-upload
                                    class="mt-1"
                                    input-id="arquivo-pagamentos-ofx"
                                    wire:model="arquivo_pagamentos"
                                    accept=".pdf"
                                    formato="PDF do relatório de pagamentos até 10 MB"
                                    :nome-arquivo="$arquivo_pagamentos ? $arquivo_pagamentos->getClientOriginalName() : null"
                                />
                                @error('arquivo_pagamentos')
                                    <span class="text-red-500 text-sm">{{ $message }}</span>
                                @enderror
                            </div>
                        @endif
                    @endif

                    <div class="flex gap-3">
                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:target="converter,arquivo,arquivo_pix,arquivo_pagamentos"
                                @disabled($status === 'processando' || empty($layout_selecionado))
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
                                        <li>Agência: <strong>{{ $cooperativa_extraida }}</strong></li>
                                    @endif
                                    @if($numero_conta_extraido)
                                        <li>Conta: <strong>{{ $numero_conta_extraido }}</strong></li>
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
                                    @if($total_enriquecidos > 0)
                                        <li>Históricos identificados: <strong>{{ $total_enriquecidos }}</strong></li>
                                    @endif
                                    @if($total_separados_encargos > 0)
                                        <li>Pagamentos separados (juros/multa): <strong>{{ $total_separados_encargos }}</strong></li>
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

                @if($status === 'concluida' && count($lancamentos) > 0)
                    <div class="mt-8">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">Lançamentos do extrato</h3>
                            <p class="text-sm text-gray-500">Somente visualização — use o botão acima para baixar o OFX.</p>
                        </div>

                        <div class="overflow-x-auto border border-gray-200 rounded-lg">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Data</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Histórico</th>
                                        <th class="px-3 py-2 text-right font-medium text-gray-600">Valor</th>
                                        @if($layout_selecionado === 'banrisul_enriquecido')
                                            <th class="px-3 py-2 text-center font-medium text-gray-600 leading-tight">
                                                Identificado<br>
                                                <span class="font-normal">Pagamento/Recebimento</span>
                                            </th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @foreach($lancamentos as $lancamento)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $lancamento['data'] ?? '' }}</td>
                                            <td class="px-3 py-2 text-gray-900">
                                                {{ $lancamento['historico'] ?? '' }}
                                                @if(!empty($lancamento['encargos']))
                                                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Juros/multa</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap text-right font-medium {{ ($lancamento['valor'] ?? 0) < 0 ? 'text-red-600' : 'text-green-600' }}">
                                                R$ {{ number_format(abs($lancamento['valor'] ?? 0), 2, ',', '.') }}
                                                {{ ($lancamento['valor'] ?? 0) < 0 ? 'D' : 'C' }}
                                            </td>
                                            @if($layout_selecionado === 'banrisul_enriquecido')
                                                <td class="px-3 py-2 text-center">
                                                    @if(!empty($lancamento['enriquecido']))
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">Sim</span>
                                                    @else
                                                        <span class="text-gray-300">—</span>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
