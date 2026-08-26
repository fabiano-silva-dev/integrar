<div class="p-6">
    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Automação Fiscal</h1>
                <p class="text-sm text-gray-600">Situação das coletas e certificados do escritório.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($podeExecutar)
                    <a href="{{ route('automacao-fiscal.executar') }}" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                        Executar consulta
                    </a>
                @endif
                <a href="{{ route('automacao-fiscal.analises') }}" class="px-4 py-2 rounded-lg bg-white border border-indigo-200 text-indigo-700 text-sm font-semibold hover:bg-indigo-50">
                    Análises fiscais
                </a>
                @if ($podeConfigurar)
                    <a href="{{ route('configuracoes.automacao-fiscal') }}" class="px-4 py-2 rounded-lg bg-white border border-indigo-200 text-indigo-700 text-sm font-semibold hover:bg-indigo-50">
                        Configurações
                    </a>
                @endif
            </div>
        </div>

        <x-aviso-fila-automacoes />

        @if ($precisaSelecionarEscritorio)
            <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                Selecione um escritório no menu superior.
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white shadow rounded-xl p-4">
                    <p class="text-xs text-gray-500 uppercase">Integrações ativas</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $totais['integracoes_ativas'] }}</p>
                </div>
                <div class="bg-white shadow rounded-xl p-4">
                    <p class="text-xs text-gray-500 uppercase">Execuções hoje</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $totais['execucoes_hoje'] }}</p>
                </div>
                <div class="bg-white shadow rounded-xl p-4">
                    <p class="text-xs text-gray-500 uppercase">Falhas (7 dias)</p>
                    <p class="text-3xl font-bold text-red-600 mt-1">{{ $totais['falhas_7d'] }}</p>
                </div>
                <div class="bg-white shadow rounded-xl p-4">
                    <p class="text-xs text-gray-500 uppercase">Certificados a vencer</p>
                    <p class="text-3xl font-bold text-amber-600 mt-1">{{ $totais['certificados_a_vencer'] }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white shadow rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b font-semibold text-gray-900">Últimas execuções</div>
                    <ul class="divide-y text-sm">
                        @forelse($ultimas as $execucao)
                            <li>
                                @if ($podeExecutar)
                                    <a href="{{ route('automacao-fiscal.execucao', $execucao) }}"
                                        class="px-4 py-3 flex justify-between gap-3 hover:bg-indigo-50/70 transition-colors group">
                                        <div>
                                            <p class="font-medium text-gray-900 group-hover:text-indigo-800">{{ $execucao->empresa?->nome }}</p>
                                            <p class="text-gray-500">{{ $execucao->portalRecurso?->portal?->nome }} / {{ $execucao->portalRecurso?->nome }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-medium">{{ $execucao->status }}</p>
                                            <p class="text-xs text-gray-500">{{ $execucao->created_at?->format('d/m H:i') }}</p>
                                        </div>
                                    </a>
                                @else
                                    <div class="px-4 py-3 flex justify-between gap-3">
                                        <div>
                                            <p class="font-medium text-gray-900">{{ $execucao->empresa?->nome }}</p>
                                            <p class="text-gray-500">{{ $execucao->portalRecurso?->portal?->nome }} / {{ $execucao->portalRecurso?->nome }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-medium">{{ $execucao->status }}</p>
                                            <p class="text-xs text-gray-500">{{ $execucao->created_at?->format('d/m H:i') }}</p>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-gray-500">Nenhuma execução ainda.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="bg-white shadow rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b font-semibold text-gray-900">Certificados próximos do vencimento</div>
                    <ul class="divide-y text-sm">
                        @forelse($certificados as $cert)
                            <li class="px-4 py-3 flex justify-between gap-3">
                                <span>{{ $cert->nome }}</span>
                                <span class="text-amber-700">{{ $cert->valido_ate?->format('d/m/Y') }}</span>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-gray-500">Nenhum alerta no momento.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif
    </div>
</div>
