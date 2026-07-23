<div class="p-6">
    <div class="max-w-2xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="text-2xl font-bold text-gray-900">Importar empresas</h1>
                    <a href="{{ route('empresas') }}" class="text-sm text-indigo-600 hover:underline">Voltar ao cadastro</a>
                </div>
                <div class="mt-4 flex gap-2 text-xs">
                    @foreach ([1 => 'Arquivo', 2 => 'Mapeamento', 3 => 'Prévia', 4 => 'Concluído'] as $n => $label)
                        <span class="px-2 py-1 rounded {{ $step === $n ? 'bg-indigo-600 text-white' : ($step > $n ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-500') }}">
                            {{ $n }}. {{ $label }}
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="p-6 space-y-4">
                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">{{ session('message') }}</div>
                @endif

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior.
                    </div>
                @elseif ($step === 1)
                    <form wire:submit.prevent="processarArquivo" class="space-y-4">
                        <x-zona-upload
                            wire:model="arquivo"
                            accept=".csv,.xls,.xlsx"
                            formato="CSV, XLS ou XLSX"
                            :nome-arquivo="$arquivo?->getClientOriginalName()"
                        />
                        @error('arquivo') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror

                        @if ($arquivo)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Delimitador (CSV)</label>
                                <select wire:model="delimitador" class="mt-1 border-gray-300 rounded-md">
                                    <option value=";">Ponto e vírgula (;)</option>
                                    <option value=",">Vírgula (,)</option>
                                </select>
                            </div>
                        @endif

                        <button type="submit" class="w-full h-14 rounded-xl bg-indigo-600 text-white font-semibold hover:bg-indigo-700" @disabled(!$arquivo)>
                            Continuar
                        </button>
                    </form>
                @elseif ($step === 2)
                    <form wire:submit.prevent="gerarPrevia" class="space-y-4">
                        <p class="text-sm text-gray-600">Associe as colunas do arquivo aos campos do cadastro.</p>
                        @foreach($campos as $campo => $label)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                                <select wire:model="mapeamento.{{ $campo }}" class="mt-1 w-full border-gray-300 rounded-md">
                                    <option value="">Ignorar</option>
                                    @foreach($colunasArquivo as $coluna)
                                        <option value="{{ $coluna }}">{{ $coluna }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                        @error('mapeamento.cnpj') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        <div class="flex gap-3">
                            <button type="button" wire:click="voltar(1)" class="flex-1 h-14 rounded-xl bg-gray-200 text-gray-800 font-semibold">Voltar</button>
                            <button type="submit" class="flex-1 h-14 rounded-xl bg-indigo-600 text-white font-semibold">Gerar prévia</button>
                        </div>
                    </form>
                @elseif ($step === 3)
                    <div class="bg-indigo-50 rounded-xl p-4 text-sm text-indigo-900">
                        {{ $previewResumo['total'] ?? 0 }} linhas ·
                        {{ $previewResumo['criar'] ?? 0 }} novas ·
                        {{ $previewResumo['atualizar'] ?? 0 }} atualizações ·
                        {{ $previewResumo['erro'] ?? 0 }} com erro
                    </div>
                    <div class="max-h-80 overflow-auto border rounded-lg">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-2 py-2 text-left">Linha</th>
                                    <th class="px-2 py-2 text-left">Ação</th>
                                    <th class="px-2 py-2 text-left">CNPJ</th>
                                    <th class="px-2 py-2 text-left">Nome</th>
                                    <th class="px-2 py-2 text-left">Erro</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($previewItens as $item)
                                    <tr class="border-t">
                                        <td class="px-2 py-1">{{ $item['numero_linha'] }}</td>
                                        <td class="px-2 py-1">{{ $item['acao'] }}</td>
                                        <td class="px-2 py-1">{{ $item['dados_normalizados']['cnpj'] ?? '' }}</td>
                                        <td class="px-2 py-1">{{ $item['dados_normalizados']['nome_fantasia'] ?: ($item['dados_normalizados']['razao_social'] ?? '') }}</td>
                                        <td class="px-2 py-1 text-red-600">{{ $item['mensagem_erro'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" wire:click="voltar(2)" class="flex-1 h-14 rounded-xl bg-gray-200 text-gray-800 font-semibold">Voltar</button>
                        <button type="button" wire:click="confirmar" class="flex-1 h-14 rounded-xl bg-indigo-600 text-white font-semibold"
                                @disabled(($previewResumo['criar'] ?? 0) + ($previewResumo['atualizar'] ?? 0) === 0)>
                            Confirmar importação
                        </button>
                    </div>
                @else
                    <div class="text-center space-y-4 py-6">
                        <p class="text-lg font-semibold text-gray-900">Importação concluída</p>
                        <p class="text-sm text-gray-600">{{ session('message') }}</p>
                        <a href="{{ route('empresas') }}" class="inline-flex h-14 px-6 items-center rounded-xl bg-indigo-600 text-white font-semibold">Ir para empresas</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
