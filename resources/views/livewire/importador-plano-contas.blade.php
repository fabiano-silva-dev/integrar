<div class="p-6">
    <div class="max-w-2xl mx-auto">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <div class="p-6 sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">Importação de Plano de Contas</h2>

                        @if($empresaAtual)
                            <p class="mt-2 text-sm text-gray-600">
                                Empresa: <span class="font-medium text-gray-900">{{ $empresaAtual->nome }}</span>
                                <span class="text-gray-400 mx-1">·</span>
                                {{ $empresaAtual->codigo_sistema ?? '—' }}
                            </p>
                        @else
                            <p class="mt-2 text-sm text-red-600">Selecione uma empresa no cabeçalho antes de importar.</p>
                        @endif
                    </div>
                    <a href="{{ route('plano-contas') }}" class="shrink-0 text-sm text-indigo-600 hover:text-indigo-800">← Voltar</a>
                </div>

                <div class="mt-6 mb-8 flex gap-2">
                    @for($i = 1; $i <= 3; $i++)
                        <div @class([
                            'h-2 flex-1 rounded-full transition-all duration-500',
                            'bg-indigo-600' => $this->barraProgresso() >= $i,
                            'bg-gray-200' => $this->barraProgresso() < $i,
                        ])></div>
                    @endfor
                </div>

                <div
                    x-data="{ passo: @entangle('step') }"
                >
                    {{-- Passo 1: arquivo --}}
                    <div wire:key="plano-passo-1" class="w-full" x-show="passo === 1"
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-x-8"
                         x-transition:enter-end="opacity-100 translate-x-0">

                        <h3 class="text-xl font-semibold text-gray-900">1. Envie o arquivo</h3>
                        <p class="mt-1 mb-5 text-sm text-gray-500">{{ $this->descricaoFormatos() }}</p>

                        <x-zona-upload
                            input-id="arquivo-plano-contas"
                            wire:model.live="arquivo"
                            accept=".csv,.xls,.xlsx,.pdf"
                            :formato="$this->descricaoFormatos()"
                            :nome-arquivo="$arquivo ? $arquivo->getClientOriginalName() : null"
                            :bloqueado="!$empresa_id"
                        />
                        <div wire:loading wire:target="arquivo" class="mt-2 text-sm text-indigo-600">Carregando arquivo...</div>
                        @error('arquivo') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror

                        <div class="mt-8">
                            @if(!$empresa_id)
                                <p class="text-sm text-gray-500 text-center mb-3">Selecione a empresa no cabeçalho para enviar o arquivo</p>
                            @elseif(!$arquivo)
                                <p class="text-sm text-gray-500 text-center mb-3">Selecione um arquivo para continuar</p>
                            @endif
                            <button type="button" wire:click="proximoPasso" @disabled(!$arquivo || !$empresa_id)
                                    class="w-full h-14 flex items-center justify-center gap-2 rounded-xl text-lg font-bold shadow-sm
                                        bg-indigo-600 hover:bg-indigo-700 text-white
                                        disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed disabled:shadow-none transition-colors">
                                Continuar
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>

                    {{-- Passo 2: configuração --}}
                    <div wire:key="plano-passo-2" class="w-full" x-show="passo === 2" x-cloak
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-x-8"
                         x-transition:enter-end="opacity-100 translate-x-0">

                        <h3 class="text-xl font-semibold text-gray-900">2. Configure a importação</h3>
                        <p class="mt-1 mb-5 text-sm text-gray-500">Como as contas devem ser gravadas</p>

                        @if($arquivo)
                            <p class="mb-4 text-sm text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2">
                                Arquivo: <span class="font-medium">{{ $arquivo->getClientOriginalName() }}</span>
                            </p>
                        @endif

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1.5">Estratégia</label>
                                <select wire:model="estrategia" class="w-full border-gray-300 rounded-xl shadow-sm focus:ring-indigo-500 focus:border-indigo-500 py-3.5 px-4 text-base">
                                    @foreach($estrategias as $valor => $nome)
                                        <option value="{{ $valor }}">{{ $nome }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @unless($arquivoEhPdf)
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Delimitador (CSV)</label>
                                    <select wire:model="delimitador" class="w-full border-gray-300 rounded-xl shadow-sm focus:ring-indigo-500 focus:border-indigo-500 py-3.5 px-4 text-base">
                                        <option value=";">Ponto e vírgula (;)</option>
                                        <option value=",">Vírgula (,)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Linha do cabeçalho</label>
                                    <input type="number" wire:model="linhaCabecalho" min="1"
                                           class="w-full border-gray-300 rounded-xl shadow-sm focus:ring-indigo-500 focus:border-indigo-500 py-3.5 px-4 text-base">
                                </div>
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model="temCabecalho" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    Arquivo tem linha de cabeçalho
                                </label>
                            @endunless
                        </div>

                        @if($estrategia === 'substituir')
                            <p class="mt-4 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                Contas ativas que não estiverem no arquivo serão inativadas.
                            </p>
                        @endif

                        <div class="mt-8 flex flex-col sm:flex-row gap-4">
                            <button type="button" wire:click="passoAnterior"
                                    class="w-full sm:flex-1 h-14 flex items-center justify-center rounded-xl border-2 border-gray-300 text-lg font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                                Voltar
                            </button>
                            <button type="button" wire:click="processarArquivo" wire:loading.attr="disabled" @disabled($processando)
                                    class="w-full sm:flex-1 h-14 flex items-center justify-center gap-2 rounded-xl text-lg font-bold shadow-sm
                                        bg-indigo-600 hover:bg-indigo-700 text-white
                                        disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed disabled:shadow-none transition-colors">
                                <span wire:loading.remove wire:target="processarArquivo">Continuar</span>
                                <span wire:loading wire:target="processarArquivo" class="inline-flex items-center gap-2">
                                    <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                    Processando...
                                </span>
                            </button>
                        </div>
                    </div>

                    {{-- Passo 3: mapeamento (planilha) --}}
                    <div wire:key="plano-passo-3" class="w-full" x-show="passo === 3" x-cloak
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-x-8"
                         x-transition:enter-end="opacity-100 translate-x-0">

                        <h3 class="text-xl font-semibold text-gray-900">3. Mapeie as colunas</h3>
                        <p class="mt-1 mb-5 text-sm text-gray-500">Relacione as colunas do arquivo com os campos do plano de contas</p>

                        @if($arquivo)
                            <p class="mb-4 text-sm text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2">
                                Arquivo: <span class="font-medium">{{ $arquivo->getClientOriginalName() }}</span>
                            </p>
                        @endif

                        <form wire:submit.prevent="gerarPrevia" class="space-y-4">
                            <div class="grid grid-cols-1 gap-4">
                                @foreach($campos as $campo => $label)
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1.5">
                                            {{ $label }}
                                            @if(in_array($campo, ['codigo', 'descricao']))
                                                <span class="text-red-500">*</span>
                                            @endif
                                        </label>
                                        <select wire:model="mapeamento.{{ $campo }}" class="w-full border-gray-300 rounded-xl shadow-sm focus:ring-indigo-500 focus:border-indigo-500 py-3 px-4 text-base">
                                            <option value="">— Não mapear —</option>
                                            @foreach($colunasArquivo as $coluna)
                                                <option value="{{ $coluna }}">{{ $coluna }}</option>
                                            @endforeach
                                        </select>
                                        @error('mapeamento.' . $campo) <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-8 flex flex-col sm:flex-row gap-4">
                                <button type="button" wire:click="passoAnterior"
                                        class="w-full sm:flex-1 h-14 flex items-center justify-center rounded-xl border-2 border-gray-300 text-lg font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                                    Voltar
                                </button>
                                <button type="submit"
                                        class="w-full sm:flex-1 h-14 flex items-center justify-center gap-2 rounded-xl text-lg font-bold shadow-sm bg-indigo-600 hover:bg-indigo-700 text-white transition-colors">
                                    Gerar prévia
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                        </form>
                    </div>

                    {{-- Passo 4: prévia e confirmação --}}
                    @if($step === 4 && !empty($preview))
                        <div wire:key="plano-passo-4" class="w-full"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 translate-x-8"
                             x-transition:enter-end="opacity-100 translate-x-0">

                            <h3 class="text-xl font-semibold text-gray-900">4. Confirme a importação</h3>
                            <p class="mt-1 mb-5 text-sm text-gray-500">Revise o resumo antes de gravar</p>

                            <dl class="mb-5 rounded-xl bg-gray-50 border border-gray-200 divide-y divide-gray-200 text-sm">
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="text-gray-500 shrink-0">Linhas lidas</dt>
                                    <dd class="font-medium text-gray-900">{{ $preview['total_linhas'] ?? 0 }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="text-gray-500 shrink-0">Novas</dt>
                                    <dd class="font-medium text-green-700">{{ $preview['contas_novas'] ?? 0 }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="text-gray-500 shrink-0">Atualizadas</dt>
                                    <dd class="font-medium text-indigo-700">{{ $preview['contas_atualizadas'] ?? 0 }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="text-gray-500 shrink-0">Erros</dt>
                                    <dd class="font-medium {{ ($preview['linhas_erro'] ?? 0) > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $preview['linhas_erro'] ?? 0 }}</dd>
                                </div>
                            </dl>

                            @if(($preview['contas_ignoradas'] ?? 0) > 0)
                                <p class="text-sm text-gray-600 mb-3">{{ $preview['contas_ignoradas'] }} conta(s) ignorada(s) (já existentes).</p>
                            @endif
                            @if(($preview['contas_inativadas'] ?? 0) > 0)
                                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                                    {{ $preview['contas_inativadas'] }} conta(s) serão inativadas.
                                </p>
                            @endif

                            @if(!empty($preview['erros']))
                                <div class="mb-5 max-h-40 overflow-y-auto bg-red-50 border border-red-200 rounded-xl p-3 text-sm">
                                    @foreach(array_slice($preview['erros'], 0, 30) as $erro)
                                        <div class="text-red-700">Linha {{ $erro['linha'] }}: {{ $erro['mensagem'] }}</div>
                                    @endforeach
                                    @if(count($preview['erros']) > 30)
                                        <div class="text-red-600 mt-1">... e mais {{ count($preview['erros']) - 30 }} erro(s)</div>
                                    @endif
                                </div>
                            @endif

                            @if(!empty($preview['amostra']))
                                <div class="overflow-x-auto mb-5 rounded-xl border border-gray-200">
                                    <table class="min-w-full text-sm divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-gray-500 font-medium">Código</th>
                                                <th class="px-4 py-2 text-left text-gray-500 font-medium">Classificação</th>
                                                <th class="px-4 py-2 text-left text-gray-500 font-medium">Nome</th>
                                                <th class="px-4 py-2 text-left text-gray-500 font-medium">Ação</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach($preview['amostra'] as $item)
                                                <tr>
                                                    <td class="px-4 py-2 font-mono text-gray-900">{{ $item['codigo'] }}</td>
                                                    <td class="px-4 py-2 font-mono text-gray-600">{{ $item['classificacao'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-gray-700">{{ $item['descricao'] }}</td>
                                                    <td class="px-4 py-2 text-gray-600">{{ ($item['_existe'] ?? false) ? 'Atualizar' : 'Incluir' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    <p class="text-xs text-gray-500 px-4 py-2 bg-gray-50">Amostra das primeiras 20 contas válidas.</p>
                                </div>
                            @endif

                            <div class="flex flex-col sm:flex-row gap-4">
                                <button type="button" wire:click="passoAnterior"
                                        class="w-full sm:flex-1 h-14 flex items-center justify-center rounded-xl border-2 border-gray-300 text-lg font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                                    Voltar
                                </button>
                                <button type="button" wire:click="confirmarImportacao" wire:loading.attr="disabled"
                                        @disabled($processando || (empty($preview['contas_validas']) && $estrategia !== 'validar_apenas'))
                                        class="w-full sm:flex-1 h-14 flex items-center justify-center gap-2 rounded-xl text-lg font-bold shadow-md
                                            bg-indigo-600 hover:bg-indigo-700 text-white
                                            disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed disabled:shadow-none transition-colors">
                                    <span wire:loading.remove wire:target="confirmarImportacao" class="inline-flex items-center gap-2">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        @if($estrategia === 'validar_apenas')
                                            Concluir validação
                                        @else
                                            Confirmar importação
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="confirmarImportacao" class="inline-flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                        Gravando...
                                    </span>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
