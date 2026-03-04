<div class="py-12 w-full">
    <div class="w-full px-4 sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <h2 class="text-2xl font-bold mb-6">Regras de Amarração de Descrições</h2>

                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        {{ session('message') }}
                    </div>
                @endif

                <!-- Filtros: Empresa (global) e Layout -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Selecione o layout para a empresa atual</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Empresa</label>
                            @if($empresaAtual)
                                <div class="mt-1 border border-gray-200 rounded-md bg-white px-3 py-2 text-sm text-gray-800">
                                    <span class="font-semibold">{{ $empresaAtual->codigo_sistema ?? '—' }}</span>
                                    <span class="text-gray-500 mx-1">-</span>
                                    <span class="text-gray-700">{{ $empresaAtual->cnpj }}</span>
                                    <span class="text-gray-500 mx-1">-</span>
                                    <span class="text-gray-900">{{ $empresaAtual->nome }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    Para trocar a empresa, use o seletor no cabeçalho.
                                </p>
                            @else
                                <div class="mt-1 border border-red-300 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                                    Nenhuma empresa selecionada. Escolha uma empresa no seletor do cabeçalho.
                                </div>
                            @endif
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Layout (Importador Avançado)</label>
                            <select wire:model.live="layout_avancado" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                @foreach($layouts as $valor => $nome)
                                    <option value="{{ $valor }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                @if($empresa_id && $layout_avancado)
                    @if($this->historicoPadraoLayout)
                        <p class="text-sm text-gray-600 mb-4">
                            As regras são criadas automaticamente a partir do histórico padrão. Edite a <strong>parte digitável</strong> (opcional) e a <strong>conta contra-partida</strong> conforme a necessidade.
                        </p>
                    @else
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
                            <p class="text-amber-800">
                                Nenhum histórico padrão configurado para este layout. Configure as descrições em
                                <a href="{{ route('historicos-padrao-layout') }}" class="font-medium underline">Administração → Históricos padrão por layout</a>
                                para que as regras sejam geradas aqui.
                            </p>
                        </div>
                    @endif

                    <!-- Pesquisa, ordenação e por página -->
                    <div class="flex flex-wrap items-center gap-4 mb-4">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Pesquisar</label>
                            <input type="text"
                                   wire:model.live.debounce.300ms="busca"
                                   placeholder="Palavra-chave, parte digitável ou conta..."
                                   class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="flex items-end gap-2">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Por página</label>
                                <select wire:model.live="perPage" class="border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Grid único: todas as regras -->
                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                        <h3 class="bg-gray-50 px-4 py-2 font-semibold text-gray-800">Regras (empresa + layout)</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase cursor-pointer hover:bg-gray-100 select-none" wire:click="ordenar('palavra_chave')">
                                            Palavra-chave
                                            @if($ordenacao === 'palavra_chave')
                                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                                            @endif
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase cursor-pointer hover:bg-gray-100 select-none" wire:click="ordenar('parte_digitavel')">
                                            Parte digitável
                                            @if($ordenacao === 'parte_digitavel')
                                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                                            @endif
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase cursor-pointer hover:bg-gray-100 select-none" wire:click="ordenar('conta_contrapartida')">
                                            Conta contra-partida
                                            @if($ordenacao === 'conta_contrapartida')
                                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                                            @endif
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase cursor-pointer hover:bg-gray-100 select-none" wire:click="ordenar('descricao')">
                                            Histórico contábil
                                            @if($ordenacao === 'descricao')
                                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                                            @endif
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase w-20 cursor-pointer hover:bg-gray-100 select-none" wire:click="ordenar('ativo')">
                                            Ativo
                                            @if($ordenacao === 'ativo')
                                                <span class="ml-1">{{ $direcao === 'asc' ? '↑' : '↓' }}</span>
                                            @endif
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase w-36">Ações</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @forelse($regras as $regra)
                                        <tr>
                                            @if($editando && $regra->id === $regraId)
                                                <td colspan="6" class="px-4 py-3 bg-blue-50 border-l-4 border-blue-500">
                                                    <p class="text-sm font-semibold text-blue-900 mb-3">Editando: <span class="font-normal bg-blue-100 px-2 py-0.5 rounded">{{ $regra->palavra_chave }}</span></p>
                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                                        <div>
                                                            <label class="block text-xs font-medium text-gray-700 mb-1">
                                                                Parte digitável
                                                                <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-gray-300 text-gray-600 text-xs cursor-help ml-0.5" title="Se preenchido, a regra só será aplicada quando o histórico do lançamento contiver este texto (ex.: CPF ou parte do nome). Deixe em branco para aplicar a todos os lançamentos que batem com a palavra-chave.">?</span>
                                                            </label>
                                                            <input wire:model="edit_parte_digitavel" type="text" class="block w-full border-gray-300 rounded text-sm" placeholder="Opcional">
                                                        </div>
                                                        <div>
                                                            <label class="block text-xs font-medium text-gray-700 mb-1">Conta contra-partida</label>
                                                            <input wire:model="edit_conta_contrapartida" type="text" class="block w-full border-gray-300 rounded text-sm" placeholder="Ex.: 1083">
                                                        </div>
                                                        <div class="md:col-span-2">
                                                            <label class="block text-xs font-medium text-gray-700 mb-1">Histórico contábil</label>
                                                            <input wire:model="edit_descricao" type="text" class="block w-full border-gray-300 rounded text-sm" placeholder="Usado como histórico do lançamento na importação (opcional)">
                                                            <p class="text-xs text-gray-500 mt-0.5">Será usado como histórico do lançamento quando a regra for aplicada na importação.</p>
                                                        </div>
                                                    </div>
                                                    <div class="flex flex-wrap gap-2 items-center">
                                                        <label class="flex items-center gap-1">
                                                            <input wire:model="edit_ativo" type="checkbox" class="rounded border-gray-300 text-blue-600">
                                                            <span class="text-sm">Ativo</span>
                                                        </label>
                                                        <button wire:click="atualizarRegra" type="button" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Atualizar</button>
                                                        <button wire:click="cancelarEdicao" type="button" class="bg-gray-400 text-white px-3 py-1.5 rounded text-sm">Cancelar</button>
                                                    </div>
                                                </td>
                                            @else
                                                <td class="px-4 py-2 text-sm">{{ $regra->palavra_chave }}</td>
                                                <td class="px-4 py-2 text-sm">{{ $regra->parte_digitavel ?: '—' }}</td>
                                                <td class="px-4 py-2 text-sm">{{ $regra->conta_contrapartida ?: $regra->conta_credito ?: $regra->conta_debito ?: '—' }}</td>
                                                <td class="px-4 py-2 text-sm text-gray-700 max-w-xs truncate" title="{{ $regra->descricao }}">{{ $regra->descricao ?: '—' }}</td>
                                                <td class="px-4 py-2">
                                                    <span class="inline-flex px-2 py-0.5 text-xs rounded-full {{ $regra->ativo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                                        {{ $regra->ativo ? 'Sim' : 'Não' }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2 text-sm">
                                                    <button wire:click="toggleAtivo({{ $regra->id }})" class="text-blue-600 hover:underline mr-1">Ativar/Desativar</button>
                                                    <button wire:click="editarRegra({{ $regra->id }})" class="text-green-600 hover:underline mr-1">Editar</button>
                                                    <button wire:click="duplicarRegra({{ $regra->id }})" class="text-indigo-600 hover:underline mr-1" title="Duplicar para personalizar com parte digitável específica">Duplicar</button>
                                                    <button wire:click="excluir({{ $regra->id }})" onclick="return confirm('Excluir esta regra?')" class="text-red-600 hover:underline">Excluir</button>
                                                </td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-4 py-4 text-center text-gray-500 text-sm">
                                                Nenhuma regra para este filtro. Configure o histórico padrão em Administração → Históricos padrão por layout.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if($regras->hasPages())
                            <div class="px-4 py-2 border-t border-gray-200">{{ $regras->links() }}</div>
                        @endif
                    </div>
                @else
                    <p class="text-gray-500">Selecione a empresa e o layout para carregar as regras e editar conforme a necessidade.</p>
                @endif
            </div>
        </div>
    </div>
</div>
