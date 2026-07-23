<div class="p-6">
    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Análises fiscais</h1>
                <p class="text-sm text-gray-600">
                    @if ($coletaId)
                        Resumos, valores e agrupamentos da coleta selecionada.
                    @else
                        Resumos, valores e gráficos das coletas realizadas.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
                @if ($coletaId)
                    <a href="{{ route('automacao-fiscal.analises') }}" class="text-indigo-600 hover:underline">Voltar à listagem</a>
                @endif
                <a href="{{ route('automacao-fiscal.painel') }}" class="text-indigo-600 hover:underline">Painel da automação</a>
            </div>
        </div>

        @if (session()->has('message'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">{{ session('message') }}</div>
        @endif

        @if ($precisaSelecionarEscritorio)
            <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                Selecione um escritório no menu superior.
            </div>
        @elseif (!$coletaId)
            <div class="bg-white shadow-xl rounded-xl p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Empresa</label>
                        <select wire:model.live="filtro_empresa_id" class="mt-1 w-full border-gray-300 rounded-md">
                            <option value="">Todas</option>
                            @foreach($empresas as $empresa)
                                <option value="{{ $empresa->id }}">{{ $empresa->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Período início</label>
                        <input type="date" wire:model.live="filtro_periodo_inicio" class="mt-1 w-full border-gray-300 rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Período fim</label>
                        <input type="date" wire:model.live="filtro_periodo_fim" class="mt-1 w-full border-gray-300 rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Portal</label>
                        <select wire:model.live="filtro_portal_id" class="mt-1 w-full border-gray-300 rounded-md" @disabled($modoPeriodo)>
                            <option value="">Todos</option>
                            @foreach($portais as $portal)
                                <option value="{{ $portal->id }}">{{ $portal->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @if($modoPeriodo)
                    <div class="flex justify-end">
                        <button type="button" wire:click="limparPeriodo" class="text-sm text-indigo-600 hover:underline">
                            Limpar período e voltar às coletas
                        </button>
                    </div>
                @else
                    <p class="text-xs text-gray-500">
                        Selecione a empresa e o período para ver saídas/entradas emitidas pela empresa ou por terceiros.
                    </p>
                @endif
            </div>

            @if($modoPeriodo)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach(($resumoPeriodo['por_tipo_operacao'] ?? []) as $linha)
                        @php $codigo = $linha['chave']; @endphp
                        <button type="button"
                                wire:click="$set('filtro_tipo_operacao', '{{ $filtro_tipo_operacao === $codigo ? '' : $codigo }}')"
                                @class([
                                    'text-left bg-white shadow rounded-xl p-4 border-2 transition-colors',
                                    'border-indigo-600 ring-1 ring-indigo-200' => $filtro_tipo_operacao === $codigo,
                                    'border-transparent hover:border-gray-200' => $filtro_tipo_operacao !== $codigo,
                                ])>
                            <p class="text-sm font-semibold text-gray-900">
                                {{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelTipoOperacao($codigo) }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-4 text-sm">
                                <div>
                                    <p class="text-xs text-gray-500">Docs</p>
                                    <p class="text-xl font-bold">{{ $linha['quantidade'] }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Total</p>
                                    <p class="text-xl font-bold">R$ {{ number_format((float) $linha['valor_total'], 2, ',', '.') }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">ICMS</p>
                                    <p class="font-semibold">R$ {{ number_format((float) $linha['valor_icms'], 2, ',', '.') }}</p>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>

                <div class="bg-white shadow rounded-xl p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Empresa</p>
                        <p class="font-semibold mt-1">{{ $resumoPeriodo['empresa_nome'] ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Período</p>
                        <p class="font-semibold mt-1">
                            {{ \Illuminate\Support\Carbon::parse($filtro_periodo_inicio)->format('d/m/Y') }}
                            –
                            {{ \Illuminate\Support\Carbon::parse($filtro_periodo_fim)->format('d/m/Y') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Documentos</p>
                        <p class="font-semibold mt-1">{{ $resumoPeriodo['quantidade'] ?? 0 }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Total NF-e</p>
                        <p class="font-semibold mt-1">R$ {{ number_format((float) ($resumoPeriodo['totais_colunas']['valor_total'] ?? 0), 2, ',', '.') }}</p>
                    </div>
                </div>

                <div class="bg-white shadow rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold text-sm">Documentos do período</span>
                        @if($filtro_tipo_operacao !== '')
                            <span class="text-xs text-indigo-700 bg-indigo-50 px-2 py-1 rounded">
                                {{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelTipoOperacao($filtro_tipo_operacao) }}
                            </span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left">Emissão</th>
                                    <th class="px-3 py-2 text-left">Número</th>
                                    <th class="px-3 py-2 text-left">Operação</th>
                                    <th class="px-3 py-2 text-left">Destinatário</th>
                                    <th class="px-3 py-2 text-right">Total</th>
                                    <th class="px-3 py-2 text-left">Sit</th>
                                    <th class="px-3 py-2 text-left">Chave</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @forelse($documentosPeriodo ?? [] as $doc)
                                    @php
                                        $tipoDoc = data_get($doc->dados_complementares, 'tipo_operacao')
                                            ?: \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::classificarTipoOperacao(
                                                optional($empresas->firstWhere('id', (int) $filtro_empresa_id))->cnpj,
                                                $doc->toArray()
                                            );
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2">{{ $doc->data_emissao?->format('d/m/Y') }}</td>
                                        <td class="px-3 py-2">{{ $doc->numero }}{{ $doc->serie ? '/'.$doc->serie : '' }}</td>
                                        <td class="px-3 py-2">{{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelTipoOperacao($tipoDoc) }}</td>
                                        <td class="px-3 py-2">{{ $doc->nome_destinatario ?: $doc->cnpj_destinatario }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format((float) $doc->valor_total, 2, ',', '.') }}</td>
                                        <td class="px-3 py-2">{{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelSituacao($doc->situacao) ?? $doc->situacao }}</td>
                                        <td class="px-3 py-2 font-mono">{{ $doc->chave_acesso }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-3 py-8 text-center text-gray-500">
                                            Nenhum documento neste período. Execute as consultas de extrato no painel.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($documentosPeriodo && $documentosPeriodo->hasPages())
                        <div class="p-3 border-t">{{ $documentosPeriodo->links() }}</div>
                    @endif
                </div>
            @else
            <div class="bg-white shadow-xl rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Empresa</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Período</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Portal</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Docs</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Coletado em</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($coletas ?? [] as $coleta)
                                <tr
                                    class="hover:bg-indigo-50 cursor-pointer transition-colors"
                                    onclick="window.location='{{ route('automacao-fiscal.analises', $coleta->id) }}'"
                                >
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $coleta->empresa?->nome ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $coleta->periodoLabel() }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $coleta->nomePortal() }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ $coleta->quantidade_documentos }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $coleta->created_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-10 text-center text-gray-500">
                                        Nenhuma coleta encontrada. Execute uma consulta no painel da automação.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($coletas && $coletas->hasPages())
                    <div class="p-3 border-t">{{ $coletas->links() }}</div>
                @endif
            </div>
            @endif
        @else
            <div class="bg-white shadow rounded-xl p-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                <div>
                    <p class="text-xs text-gray-500 uppercase">Empresa</p>
                    <p class="font-semibold text-gray-900 mt-1">{{ $coletaEmpresaNome ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase">Período</p>
                    <p class="font-semibold text-gray-900 mt-1">{{ $coletaPeriodoLabel ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase">Portal</p>
                    <p class="font-semibold text-gray-900 mt-1">{{ $coletaPortalNome ?? '—' }}</p>
                </div>
            </div>

            @if (!empty($resumo))
                <div class="flex flex-wrap gap-2">
                    @foreach (['resumo' => 'Resumo', 'grupos' => 'Agrupamentos', 'documentos' => 'Documentos', 'colunas' => 'Colunas do arquivo'] as $key => $label)
                        <button type="button" wire:click="setAba('{{ $key }}')"
                                class="px-3 py-2 text-sm rounded-lg {{ $aba === $key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @foreach($avisos as $aviso)
                    <div class="bg-amber-50 border border-amber-300 text-amber-900 text-sm px-4 py-3 rounded-lg">{{ $aviso }}</div>
                @endforeach

                @if ($aba === 'resumo')
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-white shadow rounded-xl p-4">
                            <p class="text-xs text-gray-500 uppercase">Documentos</p>
                            <p class="text-3xl font-bold mt-1">{{ $resumo['quantidade'] ?? 0 }}</p>
                        </div>
                        <div class="bg-white shadow rounded-xl p-4">
                            <p class="text-xs text-gray-500 uppercase">Período</p>
                            <p class="text-sm font-semibold mt-2">
                                {{ isset($resumo['periodo_inicio']) ? \Illuminate\Support\Carbon::parse($resumo['periodo_inicio'])->format('d/m/Y') : '-' }}
                                –
                                {{ isset($resumo['periodo_fim']) ? \Illuminate\Support\Carbon::parse($resumo['periodo_fim'])->format('d/m/Y') : '-' }}
                            </p>
                        </div>
                        <div class="bg-white shadow rounded-xl p-4 md:col-span-2">
                            <p class="text-xs text-gray-500 uppercase">{{ $coletaEhNfse ? 'Prestador' : 'Emitente' }}</p>
                            <p class="text-sm font-semibold mt-2">{{ $resumo['nome_emitente'] ?? $coletaEmpresaNome ?? '-' }}</p>
                            <p class="text-xs text-gray-500">{{ $resumo['emitente'] ?? '' }}</p>
                        </div>
                    </div>

                    @if (!$coletaEhNfse)
                        <div class="bg-white shadow rounded-xl overflow-hidden">
                            <div class="px-4 py-3 border-b font-semibold">Por tipo de operação</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-4">
                                @foreach(($resumo['por_tipo_operacao'] ?? []) as $linha)
                                    <div class="border rounded-lg p-3">
                                        <p class="text-sm font-semibold text-gray-900">
                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelTipoOperacao($linha['chave']) }}
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            {{ $linha['quantidade'] }} docs ·
                                            R$ {{ number_format((float) $linha['valor_total'], 2, ',', '.') }}
                                        </p>
                                    </div>
                                @endforeach
                                @if(empty($resumo['por_tipo_operacao']))
                                    <p class="text-sm text-gray-500 md:col-span-2">Sem classificação de operação nesta coleta.</p>
                                @endif
                            </div>
                        </div>
                    @elseif (!empty($resumo['por_tipo']))
                        <div class="bg-white shadow rounded-xl overflow-hidden">
                            <div class="px-4 py-3 border-b font-semibold">Por tipo de listagem</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-4">
                                @foreach($resumo['por_tipo'] as $linha)
                                    <div class="border rounded-lg p-3">
                                        <p class="text-sm font-semibold text-gray-900">
                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfseParser::labelTipoListagem($linha['chave']) }}
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            {{ $linha['quantidade'] }} docs ·
                                            R$ {{ number_format((float) $linha['valor_total'], 2, ',', '.') }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="bg-white shadow rounded-xl overflow-hidden">
                        <div class="px-4 py-3 border-b font-semibold">
                            {{ $coletaEhNfse ? 'Total dos serviços' : 'Totais das colunas de valor' }}
                        </div>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left">Campo</th>
                                    <th class="px-4 py-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach($colunasValor as $campo => $label)
                                    <tr>
                                        <td class="px-4 py-2">{{ $label }}</td>
                                        <td class="px-4 py-2 text-right font-medium">
                                            R$ {{ number_format((float) ($resumo['totais_colunas'][$campo] ?? $resumo['valor_total'] ?? 0), 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div @class([
                        'grid gap-4 text-sm',
                        'grid-cols-1 md:grid-cols-3' => !$coletaEhNfse,
                        'grid-cols-1 md:grid-cols-2' => $coletaEhNfse,
                    ])>
                        @unless ($coletaEhNfse)
                            <div class="bg-white shadow rounded-xl p-4">
                                <p class="text-gray-500">Com ICMS &gt; 0</p>
                                <p class="text-2xl font-bold">{{ $resumo['indicadores']['com_icms'] ?? 0 }}</p>
                            </div>
                            <div class="bg-white shadow rounded-xl p-4">
                                <p class="text-gray-500">Sem base de ICMS</p>
                                <p class="text-2xl font-bold">{{ $resumo['indicadores']['sem_base_icms'] ?? 0 }}</p>
                            </div>
                        @endunless
                        <div class="bg-white shadow rounded-xl p-4">
                            <p class="text-gray-500">Chaves únicas</p>
                            <p class="text-2xl font-bold">{{ $resumo['indicadores']['chaves_unicas'] ?? $resumo['chaves_unicas'] ?? 0 }}</p>
                        </div>
                        @if ($coletaEhNfse)
                            <div class="bg-white shadow rounded-xl p-4">
                                <p class="text-gray-500">Total dos serviços</p>
                                <p class="text-2xl font-bold">
                                    R$ {{ number_format((float) ($resumo['valor_total'] ?? $resumo['totais_colunas']['valor_total'] ?? 0), 2, ',', '.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                @elseif ($aba === 'grupos')
                    @php
                        if ($coletaEhNfse) {
                            $grupos = [
                                'por_tipo' => 'Por tipo de listagem',
                                'por_situacao' => 'Por situação',
                                'por_municipio_emissor' => 'Por município emissor',
                                'por_dia' => 'Por dia de geração',
                            ];
                        } else {
                            $grupos = [
                                'por_tipo_operacao' => 'Por tipo de operação',
                                'por_situacao' => 'Por situação',
                                'por_entrada_saida' => 'Por entrada/saída',
                                'por_modelo' => 'Por modelo',
                                'por_uf_destino' => 'Por UF destino',
                                'por_dia' => 'Por dia de emissão',
                            ];
                            if (!empty($resumo['cfop_disponivel'])) {
                                $grupos = ['por_cfop' => 'Por CFOP'] + $grupos;
                            }
                        }
                    @endphp
                    <div class="space-y-6">
                        @foreach($grupos as $chave => $titulo)
                            <div class="bg-white shadow rounded-xl overflow-hidden">
                                <div class="px-4 py-3 border-b font-semibold">{{ $titulo }}</div>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left">Grupo</th>
                                                <th class="px-4 py-2 text-right">Qtd</th>
                                                <th class="px-4 py-2 text-right">{{ $coletaEhNfse ? 'Total serviços' : 'Total NF-e' }}</th>
                                                @unless ($coletaEhNfse)
                                                    <th class="px-4 py-2 text-right">ICMS</th>
                                                    <th class="px-4 py-2 text-right">BC ICMS</th>
                                                @endunless
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            @forelse(($resumo[$chave] ?? []) as $linha)
                                                <tr>
                                                    <td class="px-4 py-2">
                                                        @if ($coletaEhNfse && $chave === 'por_tipo')
                                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfseParser::labelTipoListagem($linha['chave']) }}
                                                        @elseif ($coletaEhNfse && $chave === 'por_situacao')
                                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfseParser::labelSituacao($linha['chave']) ?? $linha['chave'] }}
                                                        @elseif ($coletaEhNfse)
                                                            {{ $linha['chave'] }}
                                                        @else
                                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelGrupo($chave, $linha['chave']) }}
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-2 text-right">{{ $linha['quantidade'] }}</td>
                                                    <td class="px-4 py-2 text-right">R$ {{ number_format($linha['valor_total'], 2, ',', '.') }}</td>
                                                    @unless ($coletaEhNfse)
                                                        <td class="px-4 py-2 text-right">R$ {{ number_format($linha['valor_icms'] ?? 0, 2, ',', '.') }}</td>
                                                        <td class="px-4 py-2 text-right">R$ {{ number_format($linha['valor_bc_icms'] ?? 0, 2, ',', '.') }}</td>
                                                    @endunless
                                                </tr>
                                            @empty
                                                <tr><td colspan="{{ $coletaEhNfse ? 3 : 5 }}" class="px-4 py-6 text-center text-gray-500">Sem dados</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @elseif ($aba === 'documentos')
                    <div class="bg-white shadow rounded-xl overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left">{{ $coletaEhNfse ? 'Geração' : 'Emissão' }}</th>
                                        <th class="px-3 py-2 text-left">Número</th>
                                        @unless ($coletaEhNfse)
                                            <th class="px-3 py-2 text-left">Modelo</th>
                                        @endunless
                                        <th class="px-3 py-2 text-left">{{ $coletaEhNfse ? 'Tomador / Prestador' : 'Destinatário' }}</th>
                                        <th class="px-3 py-2 text-right">{{ $coletaEhNfse ? 'Serviço' : 'Total' }}</th>
                                        @unless ($coletaEhNfse)
                                            <th class="px-3 py-2 text-right">ICMS</th>
                                            <th class="px-3 py-2 text-left">E/S</th>
                                        @endunless
                                        <th class="px-3 py-2 text-left">Situação</th>
                                        <th class="px-3 py-2 text-left">Chave</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    @forelse($documentos ?? [] as $doc)
                                        <tr>
                                            <td class="px-3 py-2">{{ $doc->data_emissao?->format('d/m/Y') }}</td>
                                            <td class="px-3 py-2">
                                                @if ($coletaEhNfse)
                                                    {{ $doc->numero }}
                                                @else
                                                    {{ $doc->numero }}{{ $doc->serie ? '/'.$doc->serie : '' }}
                                                @endif
                                            </td>
                                            @unless ($coletaEhNfse)
                                                <td class="px-3 py-2">{{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelModelo($doc->modelo) ?? $doc->modelo }}</td>
                                            @endunless
                                            <td class="px-3 py-2">
                                                @if ($coletaEhNfse)
                                                    {{ $doc->nome_destinatario ?: $doc->nome_emitente ?: ($doc->cnpj_destinatario ?: $doc->cnpj_emitente) }}
                                                @else
                                                    {{ $doc->nome_destinatario ?: $doc->cnpj_destinatario }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right">{{ number_format((float)$doc->valor_total, 2, ',', '.') }}</td>
                                            @unless ($coletaEhNfse)
                                                <td class="px-3 py-2 text-right">{{ number_format((float)$doc->valor_icms, 2, ',', '.') }}</td>
                                                <td class="px-3 py-2">{{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelEntradaSaida($doc->entrada_saida) ?? $doc->entrada_saida }}</td>
                                            @endunless
                                            <td class="px-3 py-2">
                                                @if ($coletaEhNfse)
                                                    {{ \App\Services\AutomacaoFiscal\ExtratoNfseParser::labelSituacao($doc->situacao) ?? $doc->situacao }}
                                                @else
                                                    {{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelSituacao($doc->situacao) ?? $doc->situacao }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 font-mono">{{ $doc->chave_acesso }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="{{ $coletaEhNfse ? 6 : 9 }}" class="px-3 py-6 text-center text-gray-500">Nenhum documento listado.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if($documentos)
                            <div class="p-3">{{ $documentos->links() }}</div>
                        @endif
                    </div>
                @elseif ($aba === 'colunas')
                    <div class="bg-white shadow rounded-xl p-4">
                        <p class="text-sm text-gray-600 mb-3">
                            @if ($coletaEhNfse)
                                Campos presentes no extratonfse.txt (Portal Nacional da NFS-e):
                            @else
                                Campos presentes no extrato e-CAC RS (cabeçalho da NF-e):
                            @endif
                        </p>
                        <ol class="list-decimal list-inside text-sm space-y-1 columns-1 md:columns-2">
                            @foreach($labelsColunasArquivo as $col)
                                <li>{{ $col }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            @else
                <div class="bg-white shadow rounded-xl p-8 text-center text-gray-500">
                    Esta coleta não possui resumo disponível.
                </div>
            @endif
        @endif
    </div>
</div>
