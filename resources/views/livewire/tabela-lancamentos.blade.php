<div class="w-full px-2 md:px-6 py-4">
    <div class="flex justify-end mb-2">
        <label for="perPage" class="mr-2 text-sm text-gray-700">Exibir:</label>
        <select id="perPage" wire:model.lazy="perPage" class="border-gray-300 rounded-md text-sm">
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="200">200</option>
        </select>
        <span class="ml-2 text-sm text-gray-700">por página</span>
    </div>
            <div class="bg-white rounded-lg shadow-md">
            <!-- Mensagens de Feedback -->
            @if (session()->has('message'))
                <div class="p-4 bg-green-100 border-b border-green-200">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-800">{{ session('message') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            @if (session()->has('error'))
                <div class="p-4 bg-red-100 border-b border-red-200">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-800">{{ session('error') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Cabeçalho -->
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-800">Lançamentos Contábeis</h2>
                <p class="text-gray-600 mt-1">Gerencie e edite os lançamentos importados</p>
            </div>

        <!-- Filtros globais acima da tabela -->
        <div class="flex flex-wrap gap-3 items-end mb-4">
            <div class="mr-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Importação</label>
                <select wire:model.live="filtroImportacao" class="h-10 px-4 rounded-lg border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow outline-none">
                        <option value="">Todas</option>
                        @foreach($importacoes as $importacao)
                            <option value="{{ $importacao['id'] }}">{{ $importacao['display_text'] }}</option>
                        @endforeach
                    </select>
                </div>
            <div class="mr-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                <select wire:model.live="filtroConferido" class="h-10 px-4 rounded-lg border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow outline-none">
                        <option value="">Todos</option>
                        <option value="conferidos">Conferidos</option>
                        <option value="nao_conferidos">Não Conferidos</option>
                    </select>
                </div>
            <div class="mr-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Déb/Créd</label>
                <input type="text" wire:model.live.debounce.300ms="filtroContaAmbas" placeholder="Débito ou Crédito..." class="h-10 px-4 rounded-lg border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow outline-none" />
            </div>
            <div class="mr-2">
                <button wire:click="limparFiltros" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-white text-xs shadow transition-all">Limpar</button>
            </div>
            <div>
                <button wire:click="abrirModalNovoLancamento" class="px-5 py-2 bg-green-600 hover:bg-green-700 rounded-lg text-white text-xs shadow transition-all flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Novo Lançamento
                </button>
            </div>
        </div>





        <!-- Modal de Novo Lançamento -->
        @if($modalNovoLancamento)
            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-semibold mb-4">Novo Lançamento</h3>
                    <form wire:submit.prevent="salvarNovoLancamento">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Importação *</label>
                                <select wire:model="novoLancamento.importacao_id" wire:change="carregarDadosImportacao" class="w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="">Selecione uma importação</option>
                                    @foreach($importacoes as $importacao)
                                        <option value="{{ $importacao['id'] }}">{{ $importacao['display_text'] }}</option>
                                    @endforeach
                                </select>
                                @error('novoLancamento.importacao_id') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Data *</label>
                                <input type="date" wire:model="novoLancamento.data" class="w-full rounded-md border-gray-300 shadow-sm">
                                @error('novoLancamento.data') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Conta Débito *</label>
                                <input type="text" wire:model="novoLancamento.conta_debito" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: 1.1.1.01.001">
                                @error('novoLancamento.conta_debito') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Conta Crédito *</label>
                                <input type="text" wire:model="novoLancamento.conta_credito" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: 2.1.1.01.001">
                                @error('novoLancamento.conta_credito') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Valor *</label>
                                <input type="number" wire:model="novoLancamento.valor" step="0.01" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="0,00">
                                @error('novoLancamento.valor') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Terceiro</label>
                                <input type="text" wire:model="novoLancamento.nome_empresa" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Nome do terceiro">
                                @error('novoLancamento.nome_empresa') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Histórico *</label>
                                <textarea wire:model="novoLancamento.historico" rows="3" class="w-full rounded-md border-gray-300 shadow-sm resize-none" placeholder="Digite o histórico do lançamento"></textarea>
                                @error('novoLancamento.historico') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Código Filial/Matriz</label>
                                <input type="text" wire:model="novoLancamento.codigo_filial_matriz" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Código da filial ou matriz">
                                <p class="text-xs text-gray-500 mt-1">Preenchido automaticamente ao selecionar importação</p>
                                @error('novoLancamento.codigo_filial_matriz') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Arquivo Origem</label>
                                <input type="text" wire:model="novoLancamento.arquivo_origem" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: Lançamento Manual - Ajuste, NF 123456, Sistema ERP">
                                <p class="text-xs text-gray-500 mt-1">Opcional: Documento, sistema ou referência de origem</p>
                                @error('novoLancamento.arquivo_origem') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="flex justify-end space-x-2 mt-6">
                            <button type="button" wire:click="fecharModalNovoLancamento" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                                Cancelar
                            </button>
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                                Salvar Lançamento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <!-- Modal de Edição de Lançamento -->
        @if($modalEditarLancamento)
            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-semibold mb-4">Editar Lançamento</h3>
                    <form wire:submit.prevent="salvarEdicaoLancamento">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Data *</label>
                                <input type="date" wire:model="dadosEdicao.data" class="w-full rounded-md border-gray-300 shadow-sm">
                                @error('dadosEdicao.data') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Valor *</label>
                                <input type="number" wire:model="dadosEdicao.valor" step="0.01" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="0,00">
                                @error('dadosEdicao.valor') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Conta Débito *</label>
                                <input type="text" wire:model="dadosEdicao.conta_debito" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: 1.1.1.01.001">
                                @error('dadosEdicao.conta_debito') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Conta Crédito *</label>
                                <input type="text" wire:model="dadosEdicao.conta_credito" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: 2.1.1.01.001">
                                @error('dadosEdicao.conta_credito') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Terceiro</label>
                                <input type="text" wire:model="dadosEdicao.nome_empresa" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Nome do terceiro">
                                @error('dadosEdicao.nome_empresa') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Código Filial/Matriz</label>
                                <input type="text" wire:model="dadosEdicao.codigo_filial_matriz" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Código da filial ou matriz">
                                @error('dadosEdicao.codigo_filial_matriz') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Histórico *</label>
                                <textarea wire:model="dadosEdicao.historico" rows="3" class="w-full rounded-md border-gray-300 shadow-sm resize-none" placeholder="Digite o histórico do lançamento"></textarea>
                                @error('dadosEdicao.historico') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Arquivo Origem</label>
                                <input type="text" wire:model="dadosEdicao.arquivo_origem" class="w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: Lançamento Manual - Ajuste, NF 123456, Sistema ERP">
                                <p class="text-xs text-gray-500 mt-1">Opcional: Documento, sistema ou referência de origem</p>
                                @error('dadosEdicao.arquivo_origem') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="flex justify-end space-x-2 mt-6">
                            <button type="button" wire:click="fecharModalEdicao" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                                Cancelar
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @php
            $showModalAmarracao = $confirmarSalvarAmarracao || $edicaoTipo === 'amarracao';
        @endphp
        <div x-data="{ showAmarracao: @js($edicaoTipo === 'amarracao') }" @keydown.window.enter.prevent="if(showAmarracao){ $refs.btnNaoAmarracao.focus(); }">
            <template x-if="showAmarracao">
                <div class="fixed inset-0 flex items-center justify-center z-50 bg-black bg-opacity-40">
                    <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
                        <h3 class="text-lg font-semibold mb-4">Salvar na amarração</h3>
                        <p class="mb-4">Deseja salvar essa conta na amarração?</p>
                        <div class="flex justify-end gap-2">
                            <button @click="$wire.confirmarSalvarContaAmarracao(); showAmarracao=false" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Sim</button>
                            <button x-ref="btnNaoAmarracao" @click="$wire.cancelarConfirmacaoEdicao(); showAmarracao=false" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">Não</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Tabela -->
        <div>
            <table class="min-w-full table-auto divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-1 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal w-12">
                            <div class="leading-tight">Conf.</div>
                        </th>
                        <th class="px-1 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal cursor-pointer w-20" wire:click="ordenar('data')">
                            <div class="leading-tight">Data</div>
                            @if($ordenacao === 'data')
                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-1 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal cursor-pointer w-48" wire:click="ordenar('nome_empresa')">
                            <div class="leading-tight">Terceiro</div>
                            @if($ordenacao === 'nome_empresa')
                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-1 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal cursor-pointer w-20" wire:click="ordenar('conta_debito')">
                            <div class="leading-tight">Débito</div>
                            @if($ordenacao === 'conta_debito')
                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-1 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal cursor-pointer w-20" wire:click="ordenar('conta_credito')">
                            <div class="leading-tight">Crédito</div>
                            @if($ordenacao === 'conta_credito')
                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-1 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal cursor-pointer w-20" wire:click="ordenar('valor')">
                            <div class="leading-tight">Valor_Contabilizado</div>
                            @if($ordenacao === 'valor')
                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-1 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal cursor-pointer w-80" wire:click="ordenar('historico')">
                            <div class="leading-tight">Histórico</div>
                            @if($ordenacao === 'historico')
                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-1 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal cursor-pointer w-20" wire:click="ordenar('codigo_filial_matriz')">
                            <div class="leading-tight">Código<br>Filial/Matriz</div>
                            @if($ordenacao === 'codigo_filial_matriz')
                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-1 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider break-words whitespace-normal w-12">
                            <div class="leading-tight">Ações</div>
                        </th>
                    </tr>
                    <tr>
                        <th></th>
                        <th>
                            <input type="date" wire:model.live="filtroData" class="h-7 px-1 rounded-md border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow-sm outline-none" />
                        </th>
                        <th>
                            <input type="text" wire:model.live.debounce.300ms="filtroTerceiro" placeholder="Buscar..." class="h-7 px-1 rounded-md border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow-sm outline-none" />
                        </th>
                        <th>
                            <input type="text" wire:model.live.debounce.300ms="filtroContaDebito" placeholder="Débito..." class="h-7 px-1 rounded-md border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow-sm outline-none" />
                        </th>
                        <th>
                            <input type="text" wire:model.live.debounce.300ms="filtroContaCredito" placeholder="Crédito..." class="h-7 px-1 rounded-md border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow-sm outline-none" />
                        </th>
                        <th>
                            <input type="text" wire:model.live.debounce.300ms="filtroValor" placeholder="R$ 0,00" class="h-7 px-1 rounded-md border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow-sm outline-none" />
                        </th>
                        <th>
                            <input type="text" wire:model.live.debounce.300ms="filtroHistorico" placeholder="Buscar..." class="h-7 px-1 rounded-md border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow-sm outline-none" />
                        </th>
                        <th>
                            <input type="text" wire:model.live.debounce.300ms="filtroCodigoFilial" placeholder="Filial/Matriz..." class="h-7 px-1 rounded-md border border-gray-300 bg-white text-xs placeholder-gray-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-100 transition-all w-full shadow-sm outline-none" />
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($lancamentos as $lancamento)
                        @php
                            $temAlteracaoConta = $lancamento->alteracoes()->where('tipo_alteracao', 'conta')->exists();
                            $debitoAtual = $lancamento->conta_debito;
                            $debitoOriginal = $lancamento->conta_debito_original;
                            $creditoAtual = $lancamento->conta_credito;
                            $creditoOriginal = $lancamento->conta_credito_original;
                            $temAmarracaoDebito = isset($lancamento->amarracao) && $lancamento->amarracao->conta_debito && $lancamento->amarracao->conta_debito !== $debitoAtual;
                            $temAmarracaoCredito = isset($lancamento->amarracao) && $lancamento->amarracao->conta_credito && $lancamento->amarracao->conta_credito !== $creditoAtual;
                        @endphp
                        <tr class="hover:bg-gray-50 {{ $temAlteracaoConta ? 'bg-yellow-50' : '' }} {{ $lancamento->conferido ? 'bg-green-50' : '' }}" 
                            wire:click="toggleConferido({{ $lancamento->id }})" 
                            wire:key="lancamento-{{ $lancamento->id }}"
                            data-lancamento-id="{{ $lancamento->id }}"
                            style="cursor: pointer;">
                            <td class="px-1 py-3 text-center text-sm text-gray-900 break-words whitespace-normal" onclick="event.stopPropagation()">
                                <div class="w-4 h-4 rounded-full border-2 {{ $lancamento->conferido ? 'bg-green-400 border-green-600' : 'bg-white border-gray-300' }} mx-auto"></div>
                            </td>
                            <td class="px-1 py-3 text-center text-sm text-gray-900 break-words whitespace-normal" onclick="event.stopPropagation()">
                                @if($editandoId === $lancamento->id && $editandoCampo === 'data')
                                    <input type="date" 
                                           wire:model="valorEditando"
                                           wire:keydown.enter="salvarEdicao"
                                           wire:keydown.escape="cancelarEdicao"
                                           class="w-full rounded border-2 border-blue-500 text-xs bg-white focus:border-blue-600"
                                           value="{{ $lancamento->data->format('Y-m-d') }}"
                                           autofocus>
                                @else
                                    <div class="cursor-pointer hover:bg-blue-50 p-1 rounded text-xs" 
                                         wire:click="iniciarEdicao({{ $lancamento->id }}, 'data', '{{ $lancamento->data->format('Y-m-d') }}')"
                                         title="Clique para editar a data">
                                        {{ $lancamento->data->format('d/m/Y') }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-1 py-3 text-sm text-gray-900 break-words whitespace-normal">
                                <div class="text-xs leading-tight">
                                    @if($lancamento->terceiro_id && $lancamento->nome_terceiro)
                                        {{ $lancamento->nome_terceiro }}
                                    @else
                                        {{ $lancamento->nome_empresa }}
                                    @endif
                                </div>
                            </td>
                            <td class="px-1 py-3 text-sm text-gray-900">
                                <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                    <input type="text"
                                           value="{{ $debitoAtual }}"
                                           wire:blur="iniciarEdicao({{ $lancamento->id }}, 'conta_debito', $event.target.value)"
                                           class="w-full rounded border-2 border-black text-xs bg-white focus:border-blue-500 input-debito"
                                           placeholder="Débito"
                                           data-lancamento-id="{{ $lancamento->id }}"
                                           data-campo="conta_debito"
                                           onkeydown="handleKeyDown(event, this)"
                                </div>
                            </td>
                            <td class="px-1 py-3 text-sm text-gray-900">
                                <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                    <input type="text"
                                           value="{{ $creditoAtual }}"
                                           wire:blur="iniciarEdicao({{ $lancamento->id }}, 'conta_credito', $event.target.value)"
                                           class="w-full rounded border-2 border-black text-xs bg-white focus:border-blue-500 input-credito"
                                           placeholder="Crédito"
                                           data-lancamento-id="{{ $lancamento->id }}"
                                           data-campo="conta_credito"
                                           onkeydown="handleKeyDownCredito(event, this)"
                                </div>
                            </td>
                            <td class="px-1 py-3 text-center text-sm text-gray-900 break-words whitespace-normal" onclick="event.stopPropagation()">
                                @if($editandoId === $lancamento->id && $editandoCampo === 'valor')
                                    <input type="number" 
                                           wire:model="valorEditando"
                                           wire:keydown.enter="salvarEdicao"
                                           wire:keydown.escape="cancelarEdicao"
                                           step="0.01"
                                           min="0"
                                           class="w-full rounded border-2 border-blue-500 text-xs bg-white focus:border-blue-600"
                                           value="{{ $lancamento->valor }}"
                                           autofocus>
                                @else
                                    <div class="cursor-pointer hover:bg-blue-50 p-1 rounded text-xs" 
                                         wire:click="iniciarEdicao({{ $lancamento->id }}, 'valor', '{{ $lancamento->valor }}')"
                                         title="Clique para editar o valor">
                                        R$ {{ number_format($lancamento->valor, 2, ',', '.') }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-1 py-3 text-sm text-gray-900 break-words whitespace-normal" onclick="event.stopPropagation()">
                                <textarea 
                                       wire:blur="iniciarEdicao({{ $lancamento->id }}, 'historico', $event.target.value)"
                                       class="w-full rounded border-2 border-black text-xs bg-white focus:border-blue-500 input-historico resize-none"
                                       placeholder="Histórico"
                                       data-lancamento-id="{{ $lancamento->id }}"
                                       data-campo="historico"
                                       rows="2"
                                       onkeydown="handleKeyDownHistorico(event, this)">{{ $lancamento->historico }}</textarea>
                            </td>
                            <td class="px-1 py-3 text-center text-sm text-gray-900 break-words whitespace-normal">
                                <div class="text-xs">{{ $lancamento->codigo_filial_matriz }}</div>
                            </td>
                            <td class="px-1 py-3 text-center text-sm text-gray-900 whitespace-nowrap" onclick="event.stopPropagation()">
                                <div class="relative">
                                    <button wire:click="abrirMenuAcoes({{ $lancamento->id }})" 
                                            class="w-8 h-8 flex items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 hover:border-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path>
                                        </svg>
                                    </button>
                                    
                                    @if($menuAcoesAberto === $lancamento->id)
                                        <div class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-50 border border-gray-200">
                                            <div class="py-1">
                                                <button wire:click="editarLancamento({{ $lancamento->id }})" 
                                                        class="flex items-center w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                    Editar
                                                </button>
                                                <button wire:click="duplicarLancamento({{ $lancamento->id }})" 
                                                        class="flex items-center w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                    </svg>
                                                    Duplicar
                                                </button>
                                                <button wire:click="excluirLancamento({{ $lancamento->id }})" 
                                                        class="flex items-center w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                    Excluir
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-4 text-center text-gray-500">
                                Nenhum lançamento encontrado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $lancamentos->links() }}
        </div>
    </div>
</div>

<script>
// Cache de debounce para evitar múltiplas chamadas
const debounceTimers = {};

// Função de debounce otimizada para inputs
function debounceInput(input, callback, delay = 300) {
    const key = input.dataset.lancamentoId + '_' + input.dataset.campo;
    
    // Limpar timer anterior se existir
    if (debounceTimers[key]) {
        clearTimeout(debounceTimers[key]);
    }
    
    // Configurar novo timer
    debounceTimers[key] = setTimeout(() => {
        callback(input.value);
    }, delay);
}

function handleKeyDown(event, input) {
    if (event.key === 'Enter' || event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        
        const currentValue = input.value;
        const currentId = input.dataset.lancamentoId;
        const campo = input.dataset.campo;
        const direction = (event.key === 'ArrowUp') ? 'up' : 'down';
        
        console.log('Navegação:', { currentId, campo, direction, currentValue });
        
        // Salvar o valor primeiro usando o método original
        if (window.Livewire && campo) {
            const wireId = document.querySelector('[wire\\:id]')?.getAttribute('wire:id');
            if (wireId) {
                window.Livewire.find(wireId).call('iniciarEdicao', currentId, campo, currentValue);
                window.Livewire.find(wireId).call('salvarEdicao');
            }
        }
        
        // Se for Enter, marcar como conferido
        if (event.key === 'Enter') {
            setTimeout(() => {
                if (window.Livewire) {
                    const wireId = document.querySelector('[wire\\:id]')?.getAttribute('wire:id');
                    if (wireId) {
                        window.Livewire.find(wireId).call('marcarComoConferido', currentId);
                    }
                }
            }, 50);
        }
        
        // Navegar para o próximo input
        setTimeout(() => {
            const inputClass = campo === 'conta_debito' ? '.input-debito' : 
                              campo === 'conta_credito' ? '.input-credito' : '.input-historico';
            const inputs = document.querySelectorAll(inputClass);
            let targetInput = null;
            
            console.log('Buscando inputs:', { inputClass, totalInputs: inputs.length });
            
            // Encontrar o input atual
            let currentIndex = -1;
            for (let i = 0; i < inputs.length; i++) {
                if (inputs[i].dataset.lancamentoId == currentId) {
                    currentIndex = i;
                    break;
                }
            }
            
            console.log('Índice atual:', currentIndex);
            
            if (currentIndex !== -1) {
                if (direction === 'down' && currentIndex + 1 < inputs.length) {
                    targetInput = inputs[currentIndex + 1];
                } else if (direction === 'up' && currentIndex - 1 >= 0) {
                    targetInput = inputs[currentIndex - 1];
                }
            }
            
            console.log('Input alvo encontrado:', targetInput);
            
            if (targetInput) {
                targetInput.focus();
                targetInput.select();
            }
        }, 100); // Aumentado delay para garantir que o valor foi salvo
    }
}

// Funções unificadas - todas usam a mesma lógica otimizada
function handleKeyDownCredito(event, input) {
    handleKeyDown(event, input);
}

function handleKeyDownHistorico(event, input) {
    handleKeyDown(event, input);
}

function reverterContaDebito(btn, valorOriginal, lancamentoId) {
    let input = btn.closest('.flex').querySelector('.input-debito');
    if (!input) {
        input = document.querySelector('.input-debito[data-lancamento-id="' + lancamentoId + '"]');
    }
    let valorFora = document.querySelector('.valor-fora[data-tipo="debito"][data-lancamento-id="' + lancamentoId + '"]');
    if (input && valorFora) {
        const valorAtual = input.value;
        input.value = valorFora.textContent;
        valorFora.textContent = valorAtual;
        // Atualiza o tooltip
        if (valorFora.getAttribute('title') === 'Valor da amarração') {
            valorFora.setAttribute('title', 'Valor original da importação');
        } else {
            valorFora.setAttribute('title', 'Valor da amarração');
        }
        input.dispatchEvent(new Event('blur', { bubbles: true }));
        input.focus();
        input.select();
    }
}
function reverterContaCredito(btn, valorOriginal, lancamentoId) {
    let input = btn.closest('.flex').querySelector('.input-credito');
    if (!input) {
        input = document.querySelector('.input-credito[data-lancamento-id="' + lancamentoId + '"]');
    }
    let valorFora = document.querySelector('.valor-fora[data-tipo="credito"][data-lancamento-id="' + lancamentoId + '"]');
    if (input && valorFora) {
        const valorAtual = input.value;
        input.value = valorFora.textContent;
        valorFora.textContent = valorAtual;
        // Atualiza o tooltip
        if (valorFora.getAttribute('title') === 'Valor da amarração') {
            valorFora.setAttribute('title', 'Valor original da importação');
        } else {
            valorFora.setAttribute('title', 'Valor da amarração');
        }
        input.dispatchEvent(new Event('blur', { bubbles: true }));
        input.focus();
        input.select();
    }
}



// JavaScript otimizado - apenas eventos essenciais
document.addEventListener('livewire:init', () => {
    // Aplicar cursor pointer apenas uma vez
    function aplicarCursorPointer() {
        const rows = document.querySelectorAll('tr[wire\\:click*="toggleConferido"]');
        rows.forEach(row => {
            row.style.cursor = 'pointer';
        });
    }
    
    // Aplicar cursor pointer inicialmente
    aplicarCursorPointer();
    
    // Reaplicar apenas após ordenação (evento específico)
    Livewire.on('ordenacao-alterada', () => {
        setTimeout(aplicarCursorPointer, 50);
    });
    
    // Fechar menu de ações quando clicar fora (evento único)
    document.addEventListener('click', function(event) {
        const menuButtons = document.querySelectorAll('[wire\\:click*="abrirMenuAcoes"]');
        const isMenuButton = Array.from(menuButtons).some(button => button.contains(event.target));
        
        if (!isMenuButton && !event.target.closest('.absolute')) {
            if (window.Livewire) {
                const wireId = document.querySelector('[wire\\:id]')?.getAttribute('wire:id');
                const component = wireId ? window.Livewire.find(wireId) : null;
                if (component) {
                    component.call('fecharMenuAcoes');
                }
            }
        }
    });
});
</script>

