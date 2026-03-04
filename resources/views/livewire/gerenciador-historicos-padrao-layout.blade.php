<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold">Históricos padrão por layout</h2>
                    <button wire:click="reanalisar" type="button" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        Nova configuração
                    </button>
                </div>

                <p class="text-gray-600 mb-6">
                    Defina quais descrições que se repetem no extrato de cada layout serão usadas como base para amarrações. 
                    Selecione o layout, envie um arquivo do mesmo tipo (ex.: PDF do banco) e marque as descrições que deseja utilizar.
                </p>

                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        {{ session('message') }}
                    </div>
                @endif

                @if ($erro)
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        {{ $erro }}
                    </div>
                @endif

                <!-- Configurações já salvas -->
                <div class="mb-8">
                    <h3 class="text-lg font-semibold mb-3">Configurações salvas</h3>
                    @if($configs->isEmpty())
                        <p class="text-gray-500 text-sm">Nenhuma configuração ainda. Use o formulário abaixo para criar.</p>
                    @else
                        <div class="overflow-x-auto border border-gray-200 rounded-lg">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase">Layout</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase">Nome</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase">Empresa</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase">Descrições</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase">Ações</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($configs as $c)
                                        <tr>
                                            <td class="px-4 py-2 text-sm">{{ $layouts[$c->layout_avancado] ?? $c->layout_avancado }}</td>
                                            <td class="px-4 py-2 text-sm">{{ $c->nome_sugerido }}</td>
                                            <td class="px-4 py-2 text-sm">{{ $c->empresa?->nome ?? '—' }}</td>
                                            <td class="px-4 py-2 text-sm">{{ $c->descricoes_count }}</td>
                                            <td class="px-4 py-2">
                                                <button wire:click="editar({{ $c->id }})" type="button" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                    Editar
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <!-- Formulário: layout + arquivo -->
                <div class="bg-gray-50 rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold mb-4">{{ $historico_padrao_layout_id ? 'Editar configuração' : 'Analisar arquivo e extrair descrições' }}</h3>
                    <p class="text-sm text-gray-600 mb-4">{{ $mensagem_status }}</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Layout do arquivo</label>
                            <select wire:model="layout_avancado" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Selecione...</option>
                                @foreach($layouts as $valor => $nome)
                                    <option value="{{ $valor }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('layout_avancado') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    @if(!$analise_concluida || !$historico_padrao_layout_id)
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700">Arquivo (PDF, CSV, TXT ou OFX conforme o layout)</label>
                            <input wire:model="arquivo" type="file" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:bg-blue-50 file:text-blue-700">
                            @error('arquivo') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <button wire:click="analisarArquivo" type="button" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Analisar e detectar descrições repetidas
                        </button>
                    @endif
                </div>

                <!-- Após análise: nome, empresa e grid de descrições -->
                @if($analise_concluida && count($descricoes_extraidas) > 0)
                    <div class="bg-gray-50 rounded-lg p-6 mb-6">
                        <h3 class="text-lg font-semibold mb-4">Revise e salve</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Nome sugerido (ex.: SICREDI)</label>
                                <input wire:model="nome_sugerido" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                @error('nome_sugerido') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Empresa (opcional)</label>
                                <select wire:model="empresa_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Nenhuma (uso geral)</option>
                                    @foreach($empresas as $emp)
                                        <option value="{{ $emp->id }}">{{ $emp->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <p class="text-sm text-gray-600 mb-3">Marque as descrições que deseja usar como base para amarrações por descrição:</p>
                        <div class="overflow-x-auto border border-gray-200 rounded-lg max-h-96 overflow-y-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase w-12">Usar</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase">Descrição</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase w-24">Ocorrências</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($descricoes_extraidas as $idx => $item)
                                        <tr>
                                            <td class="px-4 py-2">
                                                <input type="checkbox"
                                                    wire:model.live="descricoes_extraidas.{{ $idx }}.usar_para_amarracao"
                                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            </td>
                                            <td class="px-4 py-2 text-sm">{{ $item['descricao'] }}</td>
                                            <td class="px-4 py-2 text-sm">{{ $item['total_ocorrencias'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button wire:click="salvar" type="button" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                Salvar configuração
                            </button>
                            <button wire:click="reanalisar" type="button" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                Cancelar
                            </button>
                        </div>
                    </div>
                @elseif($analise_concluida && count($descricoes_extraidas) === 0)
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                        <p class="text-amber-800">Nenhuma descrição repetida foi encontrada neste arquivo. Tente outro arquivo ou outro layout.</p>
                        <button wire:click="reanalisar" type="button" class="mt-2 text-amber-700 underline text-sm">Analisar outro arquivo</button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
