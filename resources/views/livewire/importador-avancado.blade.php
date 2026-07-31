<div class="p-6">
    <div class="max-w-2xl mx-auto">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            <div class="p-6 sm:p-8">
                <h2 class="text-2xl font-bold text-gray-900">
                    Importação de Extratos
                </h2>

                @if($empresaAtual)
                    <p class="mt-2 text-sm text-gray-600">
                        Empresa: <span class="font-medium text-gray-900">{{ $empresaAtual->nome }}</span>
                        <span class="text-gray-400 mx-1">·</span>
                        {{ $empresaAtual->codigo_sistema ?? '—' }}
                    </p>
                @else
                    <p class="mt-2 text-sm text-red-600">Selecione uma empresa no cabeçalho antes de importar.</p>
                @endif

                @if($status_importacao === 'pendente')
                    {{-- Progresso: 3 barras mais visíveis --}}
                    <div class="mt-6 mb-8 flex gap-2">
                        @for($i = 1; $i <= 3; $i++)
                            <div @class([
                                'h-2 flex-1 rounded-full transition-all duration-500',
                                'bg-indigo-600' => $passo_atual >= $i,
                                'bg-gray-200' => $passo_atual < $i,
                            ])></div>
                        @endfor
                    </div>

                    <form wire:submit.prevent="processarArquivo">
                        <div
                            class="relative"
                            x-data="{ passo: @entangle('passo_atual') }"
                        >
                            {{-- Passo 1 --}}
                            <div wire:key="wizard-passo-1" class="w-full" x-show="passo === 1"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-x-8"
                                 x-transition:enter-end="opacity-100 translate-x-0">

                                <h3 class="text-xl font-semibold text-gray-900">1. Envie o extrato</h3>
                                <p class="mt-1 mb-5 text-sm text-gray-500">PDF, OFX, CSV ou TXT — até 10 MB</p>

                                <x-zona-upload
                                    input-id="arquivo-extrato"
                                    wire:model="arquivo"
                                    :accept="$this->formatosAceitosLayout()"
                                    :formato="$this->descricaoFormatoLayout()"
                                    :nome-arquivo="$arquivo ? $arquivo->getClientOriginalName() : null"
                                    :bloqueado="!$empresa_id"
                                />
                                <div wire:loading wire:target="arquivo" class="mt-2 text-sm text-indigo-600">Carregando arquivo...</div>
                                @error('arquivo') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror
                                @error('empresa_id') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror

                                @if($arquivo && !$empresa_id)
                                    <p class="mt-4 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                        Selecione uma empresa no cabeçalho para enviar o extrato.
                                    </p>
                                @endif

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

                            {{-- Passo 2 --}}
                            <div wire:key="wizard-passo-2" class="w-full" x-show="passo === 2" x-cloak
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-x-8"
                                 x-transition:enter-end="opacity-100 translate-x-0">

                                <h3 class="text-xl font-semibold text-gray-900">2. Identifique o formato</h3>
                                <p class="mt-1 mb-5 text-sm text-gray-500">Como o arquivo foi exportado do banco ou sistema</p>

                                @if($arquivo)
                                    <p class="mb-4 text-sm text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2">
                                        Arquivo: <span class="font-medium">{{ $arquivo->getClientOriginalName() }}</span>
                                    </p>
                                @endif

                                <select id="layout_selecionado" wire:model.live="layout_selecionado"
                                        class="w-full border-gray-300 rounded-xl shadow-sm focus:ring-indigo-500 focus:border-indigo-500 py-3.5 px-4 text-base">
                                    <option value="">Selecione...</option>
                                    @foreach($todosLayouts as $valor => $nome)
                                        <option value="{{ $valor }}">{{ $nome }}</option>
                                    @endforeach
                                </select>

                                @if($layout_selecionado === 'caixa' || $layout_selecionado === 'caixa_federal')
                                    <p class="mt-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                        Extrato da Caixa. O sistema identifica o padrão do PDF e aplica o conversor adequado.
                                    </p>
                                @endif
                                @error('layout_selecionado') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror
                                @error('empresa_id') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror

                                <div class="mt-8 flex flex-col sm:flex-row gap-4">
                                    <button type="button" wire:click="passoAnterior"
                                            class="w-full sm:flex-1 h-14 flex items-center justify-center rounded-xl border-2 border-gray-300 text-lg font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                                        Voltar
                                    </button>
                                    <button type="button" wire:click="proximoPasso" @disabled(!$layout_selecionado)
                                            class="w-full sm:flex-1 h-14 flex items-center justify-center gap-2 rounded-xl text-lg font-bold shadow-sm
                                                bg-indigo-600 hover:bg-indigo-700 text-white
                                                disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed disabled:shadow-none transition-colors">
                                        Continuar
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>
                                @if(!$layout_selecionado)
                                    <p class="text-sm text-gray-500 text-center mt-3">Escolha o formato para continuar</p>
                                @endif
                            </div>

                            {{-- Passo 3 --}}
                            <div wire:key="wizard-passo-3" class="w-full" x-show="passo === 3" x-cloak
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-x-8"
                                 x-transition:enter-end="opacity-100 translate-x-0">

                                <h3 class="text-xl font-semibold text-gray-900">3. Confirme e importe</h3>
                                <p class="mt-1 mb-5 text-sm text-gray-500">Revise os dados antes de processar</p>

                                @if($arquivo && $layout_selecionado)
                                    <dl class="mb-5 rounded-xl bg-gray-50 border border-gray-200 divide-y divide-gray-200 text-sm">
                                        <div class="flex justify-between gap-4 px-4 py-3">
                                            <dt class="text-gray-500 shrink-0">Arquivo</dt>
                                            <dd class="font-medium text-gray-900 text-right truncate">{{ $arquivo->getClientOriginalName() }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-4 px-4 py-3">
                                            <dt class="text-gray-500 shrink-0">Formato</dt>
                                            <dd class="font-medium text-gray-900 text-right">{{ $todosLayouts[$layout_selecionado] ?? $layout_selecionado }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-4 px-4 py-3">
                                            <dt class="text-gray-500 shrink-0">Conta no sistema da Contabilidade</dt>
                                            <dd class="font-medium text-indigo-700 text-right">{{ $conta_banco ?: '—' }}</dd>
                                        </div>
                                    </dl>
                                @endif

                                <label for="conta_banco" class="block text-sm font-medium text-gray-700 mb-1.5">Conta contábil do Banco no sistema da Contabilidade</label>
                                <x-busca-plano-conta
                                    valor-model="conta_banco"
                                    :valor="$conta_banco"
                                    :pesquisa-habilitada="$empresaTemPlano"
                                    :resultados="$sugestoesContaBanco"
                                    selecionar-method="selecionarContaBanco"
                                    placeholder="Ex: 254"
                                    input-class="w-full border-gray-300 rounded-xl shadow-sm focus:ring-indigo-500 focus:border-indigo-500 py-3.5 px-4 text-base"
                                />
                                @error('conta_banco') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror
                                @error('empresa_id') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror

                                <div class="mt-8 flex flex-col sm:flex-row gap-4">
                                    <button type="button" wire:click="passoAnterior"
                                            class="w-full sm:flex-1 h-14 flex items-center justify-center rounded-xl border-2 border-gray-300 text-lg font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                                        Voltar
                                    </button>
                                    <button type="submit" wire:target="processarArquivo" wire:loading.attr="disabled"
                                            @disabled(!$this->podeImportar())
                                            class="w-full sm:flex-1 h-14 flex items-center justify-center gap-2 rounded-xl text-lg font-bold shadow-md
                                                bg-indigo-600 hover:bg-indigo-700 text-white
                                                disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed disabled:shadow-none transition-colors">
                                        <span wire:loading.remove wire:target="processarArquivo" class="inline-flex items-center gap-2">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Importar
                                        </span>
                                        <span wire:loading wire:target="processarArquivo" class="inline-flex items-center gap-2">
                                            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                            Importando...
                                        </span>
                                    </button>
                                </div>
                                @if(!$this->podeImportar())
                                    <p class="text-sm text-gray-500 text-center mt-3">
                                        @if(!$empresa_id)
                                            Selecione a empresa no cabeçalho para importar
                                        @elseif(trim($conta_banco) === '')
                                            Informe a conta contábil do Banco para importar
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    </form>

                @else
                    <div wire:key="resultado-{{ $status_importacao }}" class="mt-8 text-center"
                         x-transition:enter="transition ease-out duration-400"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100">

                        @if($status_importacao === 'processando')
                            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-indigo-100">
                                <svg class="animate-spin h-8 w-8 text-indigo-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900">Importando...</h3>
                            <p class="text-sm text-gray-600 mt-2 mb-6">{{ $mensagem_status }}</p>
                            <div class="max-w-sm mx-auto">
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-indigo-600 h-2.5 rounded-full transition-all duration-300" style="width: {{ $progresso }}%;"></div>
                                </div>
                                <p class="mt-2 text-xs text-gray-500">{{ $progresso }}% @if($totalLinhas > 0)— linha {{ $linhaAtual }} de {{ $totalLinhas }}@endif</p>
                            </div>

                        @elseif($status_importacao === 'concluida')
                            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
                                <svg class="h-8 w-8 text-green-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900">Importação concluída</h3>
                            <p class="text-gray-600 mt-2">{{ number_format($total_registros_importados, 0, ',', '.') }} lançamentos importados</p>

                            <div class="mt-6 flex flex-col sm:flex-row gap-4 max-w-lg mx-auto">
                                <a href="{{ route('tabela', ['importacao' => $importacao_id]) }}"
                                   class="w-full sm:flex-1 h-14 flex items-center justify-center rounded-xl text-lg font-bold shadow-md bg-green-600 hover:bg-green-700 text-white transition-colors">
                                    Ver lançamentos
                                </a>
                                <button wire:click="resetarImportacao"
                                        class="w-full sm:flex-1 h-14 flex items-center justify-center rounded-xl border-2 border-gray-300 text-lg font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                                    Nova importação
                                </button>
                            </div>

                        @elseif($status_importacao === 'erro')
                            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
                                <svg class="h-8 w-8 text-red-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900">Erro na importação</h3>
                            <p class="text-sm text-red-600 mt-2 mb-6 max-w-md mx-auto">{{ $mensagem_status }}</p>
                            <button wire:click="resetarImportacao"
                                    class="w-full max-w-sm mx-auto h-14 flex items-center justify-center rounded-xl text-lg font-bold shadow-md bg-indigo-600 hover:bg-indigo-700 text-white transition-colors">
                                Tentar novamente
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
