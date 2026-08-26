<div
    class="p-4 sm:p-6 lg:p-8"
        @if($emAndamento || $status === 'running' || $avisoFila) wire:poll.2s="atualizarProgresso" @endif
>
    <div class="max-w-[1600px] mx-auto space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-900">Consultas avulsas</h1>
                    <span class="inline-flex items-center rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-semibold text-violet-800">
                        Super admin
                    </span>
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
                    Testes e implantações pontuais — sem depender de empresa/portal cadastrado no fluxo normal.
                </p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <a href="{{ route('automacao-fiscal.executar') }}" class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold hover:bg-gray-50">
                    Executar consulta
                </a>
                <a href="{{ route('automacao-fiscal.painel') }}" class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold hover:bg-gray-50">
                    Painel
                </a>
            </div>
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
                Selecione um escritório no menu superior para usar certificados e storage do tenant.
            </div>
        @elseif (empty($tipos))
            <div class="bg-white shadow-xl rounded-2xl p-8 text-center text-gray-500">
                Nenhum tipo de consulta avulsa disponível para o seu perfil.
            </div>
        @else
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
                <div class="xl:col-span-5 bg-white shadow-xl rounded-2xl p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Tipo</label>
                        <select wire:model.live="tipo" class="w-full h-11 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($tipos as $t)
                                <option value="{{ $t['codigo'] }}">{{ $t['nome'] }}</option>
                            @endforeach
                        </select>
                        @if ($meta)
                            <p class="mt-2 text-xs text-gray-500">{{ $meta['descricao'] }}</p>
                        @endif
                    </div>

                    @foreach (($meta['campos'] ?? []) as $campo)
                        @php $chave = $campo['chave']; @endphp
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">{{ $campo['label'] }}</label>
                            @if (($campo['tipo'] ?? '') === 'certificado')
                                <select wire:model="entrada.{{ $chave }}" class="w-full h-11 rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Automático (WS Contabilista)</option>
                                    @foreach ($certificados as $cert)
                                        <option value="{{ $cert->id }}">
                                            {{ $cert->nome }}
                                            @if ($cert->documento_titular) — {{ $cert->documento_titular }} @endif
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    type="text"
                                    wire:model="entrada.{{ $chave }}"
                                    placeholder="{{ $campo['placeholder'] ?? '' }}"
                                    class="w-full h-11 rounded-xl border-gray-300 text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"
                                >
                            @endif
                            @error('entrada.'.$chave)
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                            @if (!empty($campo['hint']))
                                <p class="mt-1 text-xs text-gray-500">{{ $campo['hint'] }}</p>
                            @endif
                        </div>
                    @endforeach

                    <div class="flex flex-wrap gap-2 pt-2">
                        <button
                            type="button"
                            wire:click="executar"
                            wire:loading.attr="disabled"
                            @disabled($emAndamento || $status === 'running' || $avisoFila)
                            class="h-12 px-6 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="executar">Executar</span>
                            <span wire:loading wire:target="executar">Enfileirando…</span>
                        </button>
                        <button type="button" wire:click="limpar" class="h-12 px-4 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200">
                            Limpar
                        </button>
                    </div>
                </div>

                <div class="xl:col-span-7" id="painel-progresso-avulsa">
                    @include('livewire.automacao-fiscal.partials.painel-progresso-avulso', [
                        'progresso' => $progresso,
                        'pipeline' => $pipeline,
                        'logs' => $logs,
                        'status' => $status,
                        'emAndamento' => $emAndamento,
                        'token' => $token,
                        'erro' => $erro,
                        'nomeArquivo' => $nomeArquivo,
                        'fonte' => $fonte,
                        'duracaoMs' => $duracaoMs,
                        'finishedAt' => $finishedAt,
                        'parametros' => $parametros,
                        'contextoLabel' => $contextoLabel,
                        'etapaAtual' => $etapaAtual,
                        'compact' => false,
                    ])
                </div>
            </div>
        @endif
    </div>
</div>
