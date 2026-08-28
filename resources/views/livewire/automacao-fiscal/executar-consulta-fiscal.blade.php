<div
    class="p-4 sm:p-6 lg:p-8"
    @if($emAndamento || $avisoFila) wire:poll.2s @endif
    x-data
    @scroll-progresso-execucao.window="$nextTick(() => document.getElementById('painel-progresso-execucao')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))"
>
    <div class="max-w-[1600px] mx-auto space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-900">Executar consulta</h1>
                    @if ($fakeMode)
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                            Modo simulado
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                            Modo real
                        </span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-600">
                    Escolha o modelo, ajuste se precisar e execute — o acompanhamento fica ao lado.
                </p>
            </div>
            <a href="{{ route('automacao-fiscal.painel') }}" class="shrink-0 px-4 py-2 rounded-lg bg-white border border-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-50">
                Voltar ao painel
            </a>
        </div>

        @if (session()->has('message'))
            <div class="bg-green-100 border border-green-400 text-green-800 px-4 py-3 rounded-xl text-sm">{{ session('message') }}</div>
        @endif
        @if (session()->has('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-xl text-sm">{{ session('error') }}</div>
        @endif

        <x-aviso-fila-automacoes :aviso="$avisoFila" />

        @if ($precisaSelecionarEscritorio)
            <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded-xl">
                Selecione um escritório no menu superior.
            </div>
        @else
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                {{-- Formulário mais largo --}}
                <div class="xl:col-span-7 bg-white shadow-xl rounded-2xl flex flex-col min-h-0">
                    <div class="flex-1 space-y-3 p-4 sm:p-5">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Empresa</label>
                                <select wire:model.live="empresa_id" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Selecione a empresa</option>
                                    @foreach ($empresas as $empresa)
                                        <option value="{{ $empresa->id }}">{{ $empresa->nome }} — {{ $empresa->cnpj }}</option>
                                    @endforeach
                                </select>
                                @error('empresa_id') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Portal</label>
                                <select wire:model.live="empresa_integracao_id" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" @disabled(!$empresa_id)>
                                    <option value="">Selecione o portal</option>
                                    @foreach ($integracoes as $integracao)
                                        <option value="{{ $integracao->id }}">{{ $integracao->portal?->nome }}</option>
                                    @endforeach
                                </select>
                                @if ($empresa_id && $integracoes->isEmpty())
                                    <p class="text-sm text-amber-700 mt-1">Nenhum portal ativo. Ative em Cadastros → Empresas.</p>
                                @endif
                                @error('empresa_integracao_id') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($empresa_integracao_id)
                            <div>
                                <span class="block text-sm font-semibold text-gray-700 mb-1.5">Tipo</span>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    @forelse ($tiposDisponiveis as $tipo)
                                        <label @class([
                                            'flex items-center gap-2.5 rounded-xl border px-3 py-2.5 cursor-pointer transition',
                                            'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-200' => $tipo_consulta === $tipo['value'],
                                            'border-gray-200 hover:border-gray-300 bg-white' => $tipo_consulta !== $tipo['value'],
                                        ])>
                                            <input type="radio" wire:model.live="tipo_consulta" value="{{ $tipo['value'] }}"
                                                class="border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            <span class="text-sm font-semibold text-gray-900">{{ $tipo['label'] }}</span>
                                        </label>
                                    @empty
                                        <p class="text-sm text-amber-700">Este portal ainda não tem consultas disponíveis.</p>
                                    @endforelse
                                </div>
                                @error('tipo_consulta') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if ($tipo_consulta === 'validar_acesso')
                            <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-3">
                                <p class="text-sm text-gray-600">Confirma se o certificado da empresa autentica no portal.</p>
                            </div>
                        @elseif (in_array($tipo_consulta, ['extrato_nfse_emitidas', 'extrato_nfse_recebidas'], true) && $portal_recurso_id && !empty($schema))
                            <div class="rounded-xl border border-indigo-100 bg-indigo-50/40 px-3 py-3 space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h2 class="text-xs font-semibold text-indigo-800 uppercase tracking-wide">Modelo de consulta</h2>
                                    @if ($consulta_salva_id)
                                        <button type="button" wire:click="excluirConsultaSalva" wire:confirm="Excluir este modelo?"
                                            class="text-xs font-semibold text-red-600 hover:text-red-700">
                                            Excluir modelo
                                        </button>
                                    @endif
                                </div>
                                <div class="grid grid-cols-1 lg:grid-cols-12 gap-2 items-end">
                                    <div class="lg:col-span-4">
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Abrir modelo</label>
                                        <select wire:model.live="consulta_salva_id" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white">
                                            <option value="">Novo / sem modelo</option>
                                            @foreach ($consultasSalvas as $consulta)
                                                <option value="{{ $consulta->id }}">{{ $consulta->nome }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="lg:col-span-5">
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Nome</label>
                                        <input type="text" wire:model.live.debounce.400ms="nome_consulta_salva" maxlength="120"
                                            placeholder="{{ $this->gerarNomeModeloSugerido() }}"
                                            class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white">
                                        @if ($this->nomeModeloEhSugestao())
                                            <p class="text-xs text-indigo-600 mt-1">Sugestão com base nos filtros — salve para reutilizar no agendamento.</p>
                                        @endif
                                        @error('nome_consulta_salva') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="lg:col-span-3">
                                        <button type="button" wire:click="salvarConsultaNomeada" wire:loading.attr="disabled"
                                            class="w-full h-10 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60">
                                            {{ $consulta_salva_id ? 'Atualizar modelo' : 'Salvar modelo' }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-3 pt-1">
                                <div class="rounded-xl border border-gray-200 px-3 py-2.5">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                        <span class="text-xs font-semibold text-gray-500 uppercase">Período</span>
                                        @foreach ($this->opcoesPeriodoModo() as $opt)
                                            <label class="inline-flex items-center gap-1.5 text-sm text-gray-800 cursor-pointer">
                                                <input type="radio" wire:model.live="periodo_modo" value="{{ $opt['value'] }}"
                                                    class="border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                <span>{{ $opt['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @if ($periodo_modo === 'personalizado')
                                        <div class="mt-2 grid grid-cols-2 gap-2">
                                            <input type="date" wire:model.live="parametros.periodo_inicial" class="w-full h-9 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <input type="date" wire:model.live="parametros.periodo_final" class="w-full h-9 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                        <p class="mt-1 text-xs text-red-600">Máx. 30 dias</p>
                                    @else
                                        @php
                                            $ini = $parametros['periodo_inicial'] ?? null;
                                            $fim = $parametros['periodo_final'] ?? null;
                                        @endphp
                                        <p class="mt-1.5 text-xs text-gray-600">
                                            {{ $ini ? \Carbon\Carbon::parse($ini)->format('d/m/Y') : '—' }}
                                            →
                                            {{ $fim ? \Carbon\Carbon::parse($fim)->format('d/m/Y') : '—' }}
                                        </p>
                                    @endif
                                    @error('parametros.periodo_inicial') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                    @error('parametros.periodo_final') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ $this->labelCampo('busca') }}</label>
                                    <input type="text" wire:model.blur="parametros.busca"
                                        placeholder="Opcional"
                                        class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error('parametros.busca') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @elseif ($tipo_consulta === 'extrato_nfe_nfce' && $portal_recurso_id && !empty($schema))
                            <div class="rounded-xl border border-indigo-100 bg-indigo-50/40 px-3 py-3 space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h2 class="text-xs font-semibold text-indigo-800 uppercase tracking-wide">Modelo de consulta</h2>
                                    @if ($consulta_salva_id)
                                        <button type="button" wire:click="excluirConsultaSalva" wire:confirm="Excluir este modelo?"
                                            class="text-xs font-semibold text-red-600 hover:text-red-700">
                                            Excluir modelo
                                        </button>
                                    @endif
                                </div>
                                <div class="grid grid-cols-1 lg:grid-cols-12 gap-2 items-end">
                                    <div class="lg:col-span-4">
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Abrir modelo</label>
                                        <select wire:model.live="consulta_salva_id" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white">
                                            <option value="">Novo / sem modelo</option>
                                            @foreach ($consultasSalvas as $consulta)
                                                <option value="{{ $consulta->id }}">{{ $consulta->nome }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="lg:col-span-5">
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Nome</label>
                                        <input type="text" wire:model.live.debounce.400ms="nome_consulta_salva" maxlength="120"
                                            placeholder="{{ $this->gerarNomeModeloSugerido() }}"
                                            class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white">
                                        @if ($this->nomeModeloEhSugestao())
                                            <p class="text-xs text-indigo-600 mt-1">Sugestão com base nos filtros — salve para reutilizar no agendamento.</p>
                                        @endif
                                        @error('nome_consulta_salva') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="lg:col-span-3">
                                        <button type="button" wire:click="salvarConsultaNomeada" wire:loading.attr="disabled"
                                            class="w-full h-10 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60">
                                            {{ $consulta_salva_id ? 'Atualizar modelo' : 'Salvar modelo' }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-3 pt-1">
                                @if ($this->schemaEstiloPortal($schema))
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ $this->labelCampo('ie') }}</label>
                                            <input type="text" wire:model.blur="parametros.ie" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            @error('parametros.ie') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">{{ $this->labelCampo('cnpj') }}</label>
                                            <input type="text" wire:model.blur="parametros.cnpj" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            @error('parametros.cnpj') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                                        <div class="rounded-xl border border-gray-200 px-3 py-2.5">
                                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                                <span class="text-xs font-semibold text-gray-500 uppercase">Modelo</span>
                                                <label class="inline-flex items-center gap-1.5 text-sm text-gray-800 cursor-pointer">
                                                    <input type="checkbox" wire:model.live="modelo_nfe"
                                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                    <span>NF-e</span>
                                                </label>
                                                <label class="inline-flex items-center gap-1.5 text-sm text-gray-800 cursor-pointer">
                                                    <input type="checkbox" wire:model.live="modelo_nfce"
                                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                    <span>NFC-e</span>
                                                </label>
                                                @if (isset($schema['totalizado_por_mes']))
                                                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-800 cursor-pointer">
                                                        <input type="checkbox" wire:model.live="parametros.totalizado_por_mes"
                                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                        <span>Totalizado/mês</span>
                                                    </label>
                                                @endif
                                            </div>
                                            @error('modelo_nfe') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                            @error('parametros.modelo') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                        </div>

                                        <div class="rounded-xl border border-gray-200 px-3 py-2.5">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                <span class="text-xs font-semibold text-gray-500 uppercase">Período</span>
                                                @foreach ($this->opcoesPeriodoModo() as $opt)
                                                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-800 cursor-pointer">
                                                        <input type="radio" wire:model.live="periodo_modo" value="{{ $opt['value'] }}"
                                                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                        <span>{{ $opt['label'] }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            @if ($periodo_modo === 'personalizado')
                                                <div class="mt-2 grid grid-cols-2 gap-2">
                                                    <input type="date" wire:model.live="parametros.periodo_inicial" class="w-full h-9 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <input type="date" wire:model.live="parametros.periodo_final" class="w-full h-9 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                </div>
                                                <p class="mt-1 text-xs text-red-600">Máx. 31 dias</p>
                                            @else
                                                @php
                                                    $ini = $parametros['periodo_inicial'] ?? null;
                                                    $fim = $parametros['periodo_final'] ?? null;
                                                @endphp
                                                <p class="mt-1.5 text-xs text-gray-600">
                                                    {{ $ini ? \Carbon\Carbon::parse($ini)->format('d/m/Y') : '—' }}
                                                    →
                                                    {{ $fim ? \Carbon\Carbon::parse($fim)->format('d/m/Y') : '—' }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                        <div class="lg:col-span-2 rounded-xl border border-emerald-200 overflow-hidden">
                                            <div class="bg-emerald-700 px-3 py-1 text-xs font-semibold text-white uppercase tracking-wide">Operação</div>
                                            <div class="bg-emerald-50/40 px-3 py-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                                @foreach ($this->opcoesCampo('operacao', (array) ($schema['operacao'] ?? [])) as $opt)
                                                    <label class="flex items-start gap-2 text-sm text-gray-800 cursor-pointer rounded-lg hover:bg-white/70 px-1.5 py-1"
                                                        title="{{ $opt['label'] }}">
                                                        <input type="radio" wire:model.live="parametros.operacao" value="{{ $opt['value'] }}"
                                                            class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                        <span>{{ $this->labelEnumCurto('operacao', $opt['value']) }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            @if (($parametros['operacao'] ?? '') === 'saida-consulente' && isset($schema['excluir_venda_fora_estabelecimento']))
                                                <label class="flex items-start gap-2 text-xs text-gray-700 px-3 py-2 border-t border-emerald-100 bg-white/50">
                                                    <input type="checkbox" wire:model.live="parametros.excluir_venda_fora_estabelecimento"
                                                        class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                    <span>Excluir venda fora do estabelecimento (CFOP 5103/5104/6103/6104)</span>
                                                </label>
                                            @endif
                                            @error('parametros.operacao') <p class="text-red-600 text-sm px-3 pb-2">{{ $message }}</p> @enderror
                                        </div>

                                        <div class="rounded-xl border border-emerald-200 overflow-hidden">
                                            <div class="bg-emerald-700 px-3 py-1 text-xs font-semibold text-white uppercase tracking-wide">Situação</div>
                                            <div class="bg-emerald-50/40 px-3 py-3 flex flex-col gap-2">
                                                <label class="inline-flex items-center gap-2 text-sm text-gray-800">
                                                    <input type="checkbox" wire:model.live="parametros.situacao_normal" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                    <span>Normal</span>
                                                </label>
                                                <label class="inline-flex items-center gap-2 text-sm text-gray-800">
                                                    <input type="checkbox" wire:model.live="parametros.situacao_cancelada" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                    <span>Cancelada</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    @php
                                        $camposBoolean = collect($schema)->filter(fn ($def) => ($def['type'] ?? '') === 'boolean');
                                        $camposNormais = collect($schema)->reject(fn ($def) => ($def['type'] ?? '') === 'boolean');
                                    @endphp

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        @foreach ($camposNormais as $chave => $def)
                                            @php
                                                $def = (array) $def;
                                                $type = $def['type'] ?? 'string';
                                                $label = $this->labelCampo($chave);
                                            @endphp
                                            <div>
                                                @if ($type === 'enum')
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ $label }}</label>
                                                    <select wire:model.live="parametros.{{ $chave }}" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                        <option value="">Selecione</option>
                                                        @foreach ($this->opcoesCampo($chave, $def) as $opt)
                                                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                @elseif ($type === 'date')
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ $label }}</label>
                                                    <input type="date" wire:model.live="parametros.{{ $chave }}" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                @else
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ $label }}</label>
                                                    <input type="text" wire:model.blur="parametros.{{ $chave }}" class="w-full h-10 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                @endif
                                                @error('parametros.'.$chave) <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                                            </div>
                                        @endforeach
                                    </div>

                                    @if ($camposBoolean->isNotEmpty())
                                        <div class="flex flex-wrap gap-x-5 gap-y-2 rounded-xl bg-gray-50 px-3 py-2.5">
                                            @foreach ($camposBoolean as $chave => $def)
                                                <label class="inline-flex items-center gap-2 text-sm text-gray-800">
                                                    <input type="checkbox" wire:model.live="parametros.{{ $chave }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                    <span>{{ $this->labelCampo($chave) }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="sticky bottom-0 border-t border-gray-100 bg-white/95 backdrop-blur rounded-b-2xl px-4 sm:px-5 py-3">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                            @if (in_array($tipo_consulta, ['extrato_nfe_nfce', 'extrato_nfse_emitidas', 'extrato_nfse_recebidas'], true))
                                <label class="inline-flex items-center gap-2 text-sm text-gray-600 sm:mr-auto">
                                    <input type="checkbox" wire:model="salvarAoExecutar" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span>Lembrar filtros ao executar</span>
                                </label>
                                <button type="button" wire:click="executar" wire:loading.attr="disabled"
                                    class="h-11 px-5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60 sm:min-w-[200px]"
                                    @disabled(!$portal_recurso_id || $avisoFila)>
                                    Executar agora
                                </button>
                            @elseif ($tipo_consulta === 'validar_acesso')
                                <button type="button" wire:click="testarAcesso" wire:loading.attr="disabled"
                                    class="h-11 flex-1 px-4 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60"
                                    @disabled(!$empresa_integracao_id || $avisoFila)>
                                    Validar acesso
                                </button>
                            @else
                                <button type="button" disabled
                                    class="h-11 flex-1 px-4 rounded-xl bg-gray-200 text-gray-500 text-sm font-semibold cursor-not-allowed">
                                    Selecione o tipo de consulta
                                </button>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Acompanhamento --}}
                <div id="painel-progresso-execucao" class="xl:col-span-5 space-y-4">
                    @if ($ultima)
                        {{-- Resumo do resultado (destaque quando encerrado) --}}
                        @if (!$emAndamento)
                            <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
                                <div class="px-5 py-4 flex flex-wrap items-start justify-between gap-3 border-b border-gray-100">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h2 class="font-semibold text-gray-900">Resultado</h2>
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                                'bg-emerald-100 text-emerald-800' => in_array($ultima->status, ['sucesso', 'sucesso_parcial'], true),
                                                'bg-red-100 text-red-800' => $ultima->status === 'falha',
                                                'bg-amber-100 text-amber-800' => !in_array($ultima->status, ['sucesso', 'sucesso_parcial', 'falha'], true),
                                            ])>
                                                {{ $progresso->labelStatus($ultima->status) }}
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-sm text-gray-500 truncate">
                                            {{ $ultima->empresa?->nome }} · {{ $ultima->portalRecurso?->portal?->nome }} / {{ $ultima->portalRecurso?->nome }}
                                        </p>
                                    </div>
                                    @if ($ultima->finalizada_em)
                                        <p class="text-xs text-gray-400 shrink-0">{{ $ultima->finalizada_em->format('d/m/Y H:i:s') }}</p>
                                    @endif
                                </div>

                                <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-gray-100">
                                    <div class="px-4 py-3">
                                        <p class="text-[11px] uppercase tracking-wide text-gray-500">Status</p>
                                        <p class="mt-0.5 text-lg font-semibold text-gray-900">{{ $progresso->labelStatus($ultima->status) }}</p>
                                    </div>
                                    <div class="px-4 py-3">
                                        <p class="text-[11px] uppercase tracking-wide text-gray-500">Duração</p>
                                        <p class="mt-0.5 text-lg font-semibold text-gray-900">
                                            @if ($ultima->duracao_ms)
                                                {{ number_format($ultima->duracao_ms / 1000, 1, ',', '.') }}s
                                            @else
                                                —
                                            @endif
                                        </p>
                                    </div>
                                    <div class="px-4 py-3">
                                        <p class="text-[11px] uppercase tracking-wide text-gray-500">Documentos</p>
                                        <p class="mt-0.5 text-lg font-semibold text-gray-900">
                                            {{ $ultima->quantidade_encontrada ?? 0 }}
                                            @if ($ultima->quantidade_erros)
                                                <span class="text-sm font-medium text-red-600">({{ $ultima->quantidade_erros }} erro{{ $ultima->quantidade_erros > 1 ? 's' : '' }})</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="px-4 py-3">
                                        <p class="text-[11px] uppercase tracking-wide text-gray-500">Etapa</p>
                                        <p class="mt-0.5 text-sm font-semibold text-gray-900 truncate" title="{{ $ultima->etapa_atual ?: '—' }}">
                                            {{ $ultima->etapa_atual ?: '—' }}
                                        </p>
                                    </div>
                                </div>

                                @if ($ultima->mensagem_usuario)
                                    @php
                                        $msgVazia = (bool) preg_match(
                                            '/nenhuma\s+nf-?e|n[aã]o\s+foram\s+localizad|sem\s+documentos\s+no\s+filtro/i',
                                            (string) $ultima->mensagem_usuario
                                        );
                                    @endphp
                                    <div @class([
                                        'px-5 py-3 border-t text-sm',
                                        'border-amber-100 bg-amber-50 text-amber-900' => $msgVazia && in_array($ultima->status, ['sucesso', 'sucesso_parcial'], true),
                                        'border-gray-100 text-gray-800' => !($msgVazia && in_array($ultima->status, ['sucesso', 'sucesso_parcial'], true)),
                                    ])>
                                        {{ $ultima->mensagem_usuario }}
                                    </div>
                                @endif

                                @if ($erros->isNotEmpty() || $ultima->status === 'falha')
                                    <div class="mx-5 mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-red-700 mb-2">Erros</p>
                                        @forelse ($erros as $erro)
                                            <p class="text-sm text-red-800">
                                                <span class="font-mono text-xs text-red-500">{{ optional($erro->ocorrido_em)->format('H:i:s') }}</span>
                                                {{ $erro->mensagem }}
                                            </p>
                                        @empty
                                            <p class="text-sm text-red-800">{{ $ultima->mensagem_usuario ?: 'A execução falhou.' }}</p>
                                        @endforelse
                                    </div>
                                @endif

                                @if ($screenshots->isNotEmpty())
                                    <div class="px-5 pb-4 border-t border-gray-100 pt-4">
                                        <p class="text-xs uppercase text-gray-500 font-semibold mb-2">Screenshots</p>
                                        <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 gap-2">
                                            @foreach ($screenshots as $shot)
                                                @php
                                                    $nomeShot = (string) ($shot->nome_original ?: '');
                                                    preg_match('/^(\d+)/', $nomeShot, $etapaMatch);
                                                    $numeroEtapa = $etapaMatch[1] ?? null;
                                                @endphp
                                                <a href="{{ route('automacao-fiscal.artefato', $shot) }}" target="_blank" rel="noopener"
                                                    class="relative block overflow-hidden rounded-lg border border-gray-200 bg-gray-50 hover:border-indigo-300"
                                                    title="{{ $nomeShot }}">
                                                    @if ($numeroEtapa !== null)
                                                        <span class="absolute left-1.5 top-1.5 z-10 inline-flex min-w-[1.5rem] items-center justify-center rounded-md bg-slate-900/85 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-white shadow-sm">
                                                            {{ $numeroEtapa }}
                                                        </span>
                                                    @endif
                                                    <img
                                                        src="{{ route('automacao-fiscal.artefato', $shot) }}"
                                                        alt="{{ $nomeShot }}"
                                                        class="h-20 w-full object-cover object-top"
                                                    >
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if (!empty($ultima->parametros))
                                    <details class="border-t border-gray-100 px-5 py-3 group">
                                        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500 list-none flex items-center justify-between">
                                            <span>Parâmetros usados</span>
                                            <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                                        </summary>
                                        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                                            @foreach ($ultima->parametros as $k => $v)
                                                <div>
                                                    <dt class="text-gray-500">{{ $this->labelCampo($k) }}</dt>
                                                    <dd class="font-medium text-gray-900">
                                                        @if (is_bool($v))
                                                            {{ $v ? 'Sim' : 'Não' }}
                                                        @else
                                                            {{ is_scalar($v) ? $v : json_encode($v) }}
                                                        @endif
                                                    </dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </details>
                                @endif
                            </div>
                        @endif

                        {{-- Painel de progresso / atividade — altura limitada à janela --}}
                        <div @class([
                            'overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 text-slate-100 shadow-xl flex flex-col',
                            'h-[calc(100dvh-10rem)] max-h-[calc(100dvh-10rem)] xl:sticky xl:top-4' => $emAndamento,
                            'h-[calc(100dvh-14rem)] max-h-[28rem]' => !$emAndamento,
                        ])>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 px-4 py-2.5 shrink-0">
                                <div class="flex items-center gap-3">
                                    <span @class([
                                        'inline-flex h-2.5 w-2.5 rounded-full',
                                        'bg-sky-400 animate-pulse' => $emAndamento,
                                        'bg-emerald-400' => !$emAndamento && in_array($ultima->status, ['sucesso', 'sucesso_parcial'], true),
                                        'bg-red-400' => !$emAndamento && $ultima->status === 'falha',
                                        'bg-amber-400' => !$emAndamento && !in_array($ultima->status, ['sucesso', 'sucesso_parcial', 'falha'], true),
                                    ])></span>
                                    <div>
                                        <p class="text-sm font-semibold tracking-tight">
                                            {{ $emAndamento ? 'Processando execução…' : 'Histórico da execução' }}
                                        </p>
                                        <p class="text-xs text-slate-400">
                                            {{ $progresso->labelStatus($ultima->status) }}
                                            @if ($emAndamento)
                                                · atualiza a cada 1,5s
                                            @elseif ($ultima->finalizada_em)
                                                · {{ $ultima->finalizada_em->format('d/m/Y H:i:s') }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <div class="text-xs text-slate-500 font-mono">#{{ substr((string) $ultima->uuid, -8) }}</div>
                            </div>

                            <div class="grid flex-1 min-h-0 gap-0 lg:grid-cols-[minmax(0,12rem)_1fr]">
                                <aside class="border-b border-slate-800 p-3 lg:border-b-0 lg:border-r overflow-y-auto min-h-0">
                                    <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Etapas</p>
                                    <ol class="space-y-2">
                                        @foreach ($pipeline as $step)
                                            <li class="flex gap-2.5">
                                                <span @class([
                                                    'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                                    'bg-emerald-500' => $step['state'] === 'done',
                                                    'bg-sky-500 animate-pulse' => $step['state'] === 'active',
                                                    'bg-red-500' => $step['state'] === 'error',
                                                    'bg-amber-500' => $step['state'] === 'warn',
                                                    'bg-slate-600' => $step['state'] === 'pending',
                                                ])></span>
                                                <div class="min-w-0">
                                                    <p @class([
                                                        'text-sm leading-snug',
                                                        'text-slate-500' => $step['state'] === 'pending',
                                                        'font-medium text-sky-300' => $step['state'] === 'active',
                                                        'text-slate-200' => in_array($step['state'], ['done', 'error', 'warn'], true),
                                                    ])>
                                                        {{ $step['label'] }}
                                                    </p>
                                                    @if (!empty($step['detail']))
                                                        <p class="truncate text-xs text-slate-500">{{ $step['detail'] }}</p>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                </aside>

                                <div class="flex min-h-0 flex-col">
                                    <div class="border-b border-slate-800 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 shrink-0">
                                        Atividade
                                    </div>
                                    <div
                                        id="feed-atividade-execucao"
                                        class="flex-1 space-y-2 overflow-y-auto px-3 py-3 min-h-0"
                                        data-log-count="{{ $logs->count() }}"
                                        x-data="{
                                            scrollBottom() {
                                                this.$nextTick(() => {
                                                    this.$el.scrollTop = this.$el.scrollHeight;
                                                });
                                            }
                                        }"
                                        x-init="
                                            scrollBottom();
                                            new MutationObserver(() => scrollBottom())
                                                .observe($el, { childList: true, subtree: true });
                                            Livewire.hook('morph.updated', ({ el }) => {
                                                if ($el === el || $el.contains(el)) {
                                                    scrollBottom();
                                                }
                                            });
                                        "
                                    >
                                        @forelse ($logs as $log)
                                            <article @class([
                                                'rounded-lg border px-3 py-2',
                                                'border-red-500/40 bg-red-950/40' => $log->nivel === 'error' || in_array((string) $log->etapa, ['RUN_FAILED', 'JOB_FAILED', 'erro'], true),
                                                'border-amber-500/40 bg-amber-950/30' => $log->nivel === 'warning' || in_array((string) $log->etapa, ['MANUAL_CONFIRMATION_DETECTED', 'ROLE_SELECTION_DETECTED'], true),
                                                'border-emerald-500/30 bg-emerald-950/20' => in_array((string) $log->etapa, ['AUTHENTICATION_CONFIRMED', 'RUN_FINISHED', 'JOB_FINISHED'], true),
                                                'border-slate-700 bg-slate-900/60' => !($log->nivel === 'error' || $log->nivel === 'warning' || in_array((string) $log->etapa, ['RUN_FAILED', 'JOB_FAILED', 'erro', 'MANUAL_CONFIRMATION_DETECTED', 'ROLE_SELECTION_DETECTED', 'AUTHENTICATION_CONFIRMED', 'RUN_FINISHED', 'JOB_FINISHED'], true)),
                                            ])>
                                                <div class="flex items-start justify-between gap-2">
                                                    <p class="text-sm font-medium text-slate-100">
                                                        {{ $progresso->labelEvento($log->etapa, $log->mensagem) }}
                                                    </p>
                                                    <span class="shrink-0 font-mono text-[10px] text-slate-500">
                                                        {{ optional($log->ocorrido_em ?? $log->created_at)->format('H:i:s') }}
                                                    </span>
                                                </div>
                                                <p class="mt-0.5 text-xs text-slate-300">{{ $log->mensagem }}</p>
                                                @if ($log->etapa)
                                                    <p class="mt-1 font-mono text-[10px] text-slate-500">{{ $log->etapa }}</p>
                                                @endif
                                            </article>
                                        @empty
                                            <div class="rounded-lg border border-dashed border-slate-700 px-3 py-8 text-center text-sm text-slate-500">
                                                Aguardando primeiros eventos do runner…
                                            </div>
                                        @endforelse

                                        @if ($emAndamento)
                                            <p class="pt-1 text-center text-xs text-slate-500 animate-pulse">Aguardando próxima etapa…</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center min-h-[16rem] flex flex-col items-center justify-center">
                            <p class="text-base font-semibold text-slate-700">Acompanhe a execução aqui</p>
                            <p class="mt-2 max-w-sm text-sm text-slate-500">
                                Ao validar ou executar, o andamento, as etapas, os erros e o resultado aparecem neste painel.
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
