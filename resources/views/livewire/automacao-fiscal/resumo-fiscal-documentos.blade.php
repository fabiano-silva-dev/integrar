<div
    class="p-4 sm:p-6 lg:p-8"
    @if (($xmlModalAberto && $xmlStatus === 'running') || $avisoFila) wire:poll.2s="atualizarProgressoXml" @endif
>
    <div class="w-full max-w-[1600px] mx-auto space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Análises fiscais</h1>
                <p class="text-sm text-gray-600">
                    @if ($emDetalhe)
                        Documentos da empresa no portal na competência selecionada.
                    @else
                        Uma análise por empresa, portal e competência (mês/ano).
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-4 text-sm">
                @if ($emDetalhe)
                    <a href="{{ route('automacao-fiscal.analises') }}" class="text-indigo-600 hover:underline">Voltar à listagem</a>
                @endif
                <a href="{{ route('automacao-fiscal.painel') }}" class="text-indigo-600 hover:underline">Painel da automação</a>
            </div>
        </div>

        @if (session()->has('message'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">{{ session('message') }}</div>
        @endif
        @if (session()->has('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">{{ session('error') }}</div>
        @endif

        <x-aviso-fila-automacoes :aviso="$avisoFila" />

        @if ($precisaSelecionarEscritorio)
            <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                Selecione um escritório no menu superior.
            </div>
        @elseif (!$emDetalhe)
            <div class="bg-white shadow-xl rounded-xl p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
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
                        <label class="block text-sm font-medium text-gray-700">Portal</label>
                        <select wire:model.live="filtro_portal_id" class="mt-1 w-full border-gray-300 rounded-md">
                            <option value="">Todos</option>
                            @foreach($portais as $portal)
                                <option value="{{ $portal->id }}">{{ $portal->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Competência</label>
                        <input type="month" wire:model.live="filtro_competencia" class="mt-1 w-full border-gray-300 rounded-md">
                    </div>
                </div>
                @if($filtro_empresa_id || $filtro_portal_id || $filtro_competencia !== '')
                    <div class="flex justify-end">
                        <button type="button" wire:click="limparFiltros" class="text-sm text-indigo-600 hover:underline">
                            Limpar filtros
                        </button>
                    </div>
                @endif
            </div>

            <div class="bg-white shadow-xl rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Empresa</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Portal</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Competência</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Docs</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700">Total</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Atualizado em</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($analises ?? [] as $analise)
                                <tr
                                    class="hover:bg-indigo-50 cursor-pointer transition-colors"
                                    onclick="window.location='{{ route('automacao-fiscal.analise', [
                                        'empresa' => $analise->empresa_id,
                                        'portal' => $analise->portal_integracao_id,
                                        'competencia' => $analise->competencia,
                                    ]) }}'"
                                >
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $analise->empresa_nome }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $analise->portal_nome }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $analise->competencia_label }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ $analise->quantidade_documentos }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">
                                        R$ {{ number_format((float) $analise->valor_total, 2, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-500">
                                        {{ $analise->atualizado_em ? \Illuminate\Support\Carbon::parse($analise->atualizado_em)->format('d/m/Y H:i') : '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                                        Nenhuma análise encontrada. Execute consultas no painel da automação.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($analises && $analises->hasPages())
                    <div class="p-3 border-t">{{ $analises->links() }}</div>
                @endif
            </div>
        @else
            <div class="bg-white shadow rounded-xl px-4 py-3 flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
                <div class="min-w-0">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wide">Empresa</p>
                    <p class="font-semibold text-gray-900 truncate" title="{{ $analiseEmpresaNome ?? '' }}">{{ $analiseEmpresaNome ?? '—' }}</p>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wide">Portal</p>
                    <p class="font-semibold text-gray-900 truncate">{{ $analisePortalNome ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-gray-500 uppercase tracking-wide">Competência</p>
                    <p class="font-semibold text-gray-900">{{ $analiseCompetenciaLabel ?? '—' }}</p>
                </div>
            </div>

            @if (!empty($resumo))
                <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-1">
                    @foreach (['resumo' => 'Resumo', 'grupos' => 'Agrupamentos', 'documentos' => 'Documentos', 'colunas' => 'Colunas do arquivo'] as $key => $label)
                        <button type="button" wire:click="setAba('{{ $key }}')"
                                class="px-4 py-2 text-sm font-medium rounded-t-lg {{ $aba === $key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @if ($aba === 'resumo')
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-white shadow rounded-xl p-4">
                            <p class="text-xs text-gray-500 uppercase">Documentos</p>
                            <p class="text-3xl font-bold mt-1">{{ $resumo['quantidade'] ?? 0 }}</p>
                        </div>
                        <div class="bg-white shadow rounded-xl p-4">
                            <p class="text-xs text-gray-500 uppercase">Competência</p>
                            <p class="text-sm font-semibold mt-2">{{ $analiseCompetenciaLabel ?? '—' }}</p>
                        </div>
                        <div class="bg-white shadow rounded-xl p-4 md:col-span-2">
                            <p class="text-xs text-gray-500 uppercase">{{ $analiseEhNfse ? 'Prestador' : 'Emitente' }}</p>
                            <p class="text-sm font-semibold mt-2">{{ $resumo['nome_emitente'] ?? $analiseEmpresaNome ?? '-' }}</p>
                            <p class="text-xs text-gray-500">{{ $resumo['emitente'] ?? '' }}</p>
                        </div>
                    </div>

                    @if (!$analiseEhNfse)
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
                                    <p class="text-sm text-gray-500 md:col-span-2">Sem classificação de operação nesta análise.</p>
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

                @elseif ($aba === 'grupos')
                    @php
                        if ($analiseEhNfse) {
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
                                                <th class="px-4 py-2 text-right">{{ $analiseEhNfse ? 'Total serviços' : 'Total NF-e' }}</th>
                                                @unless ($analiseEhNfse)
                                                    <th class="px-4 py-2 text-right">ICMS</th>
                                                    <th class="px-4 py-2 text-right">BC ICMS</th>
                                                @endunless
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            @forelse(($resumo[$chave] ?? []) as $linha)
                                                <tr>
                                                    <td class="px-4 py-2">
                                                        @if ($analiseEhNfse && $chave === 'por_tipo')
                                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfseParser::labelTipoListagem($linha['chave']) }}
                                                        @elseif ($analiseEhNfse && $chave === 'por_situacao')
                                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfseParser::labelSituacao($linha['chave']) ?? $linha['chave'] }}
                                                        @elseif ($analiseEhNfse)
                                                            {{ $linha['chave'] }}
                                                        @else
                                                            {{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelGrupo($chave, $linha['chave']) }}
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-2 text-right">{{ $linha['quantidade'] }}</td>
                                                    <td class="px-4 py-2 text-right">R$ {{ number_format($linha['valor_total'], 2, ',', '.') }}</td>
                                                    @unless ($analiseEhNfse)
                                                        <td class="px-4 py-2 text-right">R$ {{ number_format((float) ($linha['valor_icms'] ?? 0), 2, ',', '.') }}</td>
                                                        <td class="px-4 py-2 text-right">R$ {{ number_format((float) ($linha['valor_bc_icms'] ?? 0), 2, ',', '.') }}</td>
                                                    @endunless
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="{{ $analiseEhNfse ? 3 : 5 }}" class="px-4 py-6 text-center text-gray-500">Sem dados neste grupo.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @elseif ($aba === 'documentos')
                    <div class="bg-white shadow rounded-xl overflow-hidden border border-gray-100">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 sticky top-0 z-10">
                                    <tr class="text-xs uppercase tracking-wide text-gray-500">
                                        <th class="px-3 py-2.5 text-left font-semibold whitespace-nowrap">{{ $analiseEhNfse ? 'Geração' : 'Emissão' }}</th>
                                        <th class="px-3 py-2.5 text-left font-semibold whitespace-nowrap">Número</th>
                                        @unless ($analiseEhNfse)
                                            <th class="px-3 py-2.5 text-left font-semibold whitespace-nowrap">Modelo</th>
                                        @endunless
                                        <th class="px-3 py-2.5 text-left font-semibold min-w-[12rem]">{{ $analiseEhNfse ? 'Prestador' : 'Emitente' }}</th>
                                        <th class="px-3 py-2.5 text-left font-semibold min-w-[12rem]">{{ $analiseEhNfse ? 'Tomador' : 'Destinatário' }}</th>
                                        <th class="px-3 py-2.5 text-right font-semibold whitespace-nowrap">{{ $analiseEhNfse ? 'Serviço' : 'Total' }}</th>
                                        @unless ($analiseEhNfse)
                                            <th class="px-3 py-2.5 text-right font-semibold whitespace-nowrap">ICMS</th>
                                            <th class="px-3 py-2.5 text-left font-semibold whitespace-nowrap">E/S</th>
                                        @endunless
                                        <th class="px-3 py-2.5 text-left font-semibold whitespace-nowrap">Situação</th>
                                        <th class="px-3 py-2.5 text-left font-semibold whitespace-nowrap">Chave</th>
                                        @unless ($analiseEhNfse)
                                            <th class="px-3 py-2.5 text-center font-semibold whitespace-nowrap">Ação</th>
                                        @endunless
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse($documentos ?? [] as $doc)
                                        @php
                                            $nomeEmitente = $doc->nome_emitente ?: $doc->cnpj_emitente;
                                            $nomeDestinatario = $doc->nome_destinatario ?: $doc->cnpj_destinatario;
                                            $chaveDigits = \App\Services\AutomacaoFiscal\AnaliseFiscalService::normalizarChaveAcesso($doc->chave_acesso);
                                            $ehModelo55 = ! $analiseEhNfse && (
                                                trim((string) $doc->modelo) === '55'
                                                || ($chaveDigits && strlen($chaveDigits) === 44 && substr($chaveDigits, 20, 2) === '55')
                                            );
                                            $podeBaixarXml = $ehModelo55 && $chaveDigits && strlen($chaveDigits) === 44;
                                        @endphp
                                        <tr class="hover:bg-slate-50/80">
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $doc->data_emissao?->format('d/m/Y') }}</td>
                                            <td class="px-3 py-2 whitespace-nowrap font-medium text-gray-900">
                                                @if ($analiseEhNfse)
                                                    {{ $doc->numero }}
                                                @else
                                                    {{ $doc->numero }}{{ $doc->serie ? '/'.$doc->serie : '' }}
                                                @endif
                                            </td>
                                            @unless ($analiseEhNfse)
                                                <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelModelo($doc->modelo) ?? $doc->modelo }}</td>
                                            @endunless
                                            <td class="px-3 py-2 text-gray-800 max-w-[16rem] truncate" title="{{ $nomeEmitente }}">{{ $nomeEmitente }}</td>
                                            <td class="px-3 py-2 text-gray-800 max-w-[16rem] truncate" title="{{ $nomeDestinatario }}">{{ $nomeDestinatario }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums text-gray-900">{{ number_format((float)$doc->valor_total, 2, ',', '.') }}</td>
                                            @unless ($analiseEhNfse)
                                                <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums text-gray-700">{{ number_format((float)$doc->valor_icms, 2, ',', '.') }}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelEntradaSaida($doc->entrada_saida) ?? $doc->entrada_saida }}</td>
                                            @endunless
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-700">
                                                @if ($analiseEhNfse)
                                                    {{ \App\Services\AutomacaoFiscal\ExtratoNfseParser::labelSituacao($doc->situacao) ?? $doc->situacao }}
                                                @else
                                                    {{ \App\Services\AutomacaoFiscal\ExtratoNfeEcacRsParser::labelSituacao($doc->situacao) ?? $doc->situacao }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 font-mono text-xs text-gray-600 whitespace-nowrap" title="{{ $doc->chave_acesso }}">{{ $doc->chave_acesso }}</td>
                                            @unless ($analiseEhNfse)
                                                <td class="px-3 py-2 text-center whitespace-nowrap">
                                                    @if ($podeBaixarXml)
                                                        <button type="button"
                                                                wire:click="baixarXml({{ $doc->id }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="baixarXml({{ $doc->id }})"
                                                                class="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                                                title="Baixar XML da NF-e e visualizar o DANFE em PDF">
                                                            <span wire:loading.remove wire:target="baixarXml({{ $doc->id }})">XML/PDF</span>
                                                            <span wire:loading wire:target="baixarXml({{ $doc->id }})">…</span>
                                                        </button>
                                                    @else
                                                        <span class="text-gray-300">—</span>
                                                    @endif
                                                </td>
                                            @endunless
                                        </tr>
                                    @empty
                                        <tr><td colspan="{{ $analiseEhNfse ? 7 : 11 }}" class="px-3 py-10 text-center text-gray-500">Nenhum documento listado.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if($documentos)
                            <div class="p-3 border-t border-gray-100">{{ $documentos->links() }}</div>
                        @endif
                    </div>
                @elseif ($aba === 'colunas')
                    <div class="bg-white shadow rounded-xl p-4">
                        <p class="text-sm text-gray-600 mb-3">
                            @if ($analiseEhNfse)
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
                    Nenhum documento nesta análise.
                </div>
            @endif
        @endif
    </div>

    @if ($xmlModalAberto)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
             wire:click.self="fecharModalXml">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden"
                 wire:click.stop>
                <div class="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3 shrink-0">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-900">Download do XML</h2>
                        <p class="text-xs text-gray-500 mt-0.5 font-mono truncate" title="{{ $xmlChave }}">
                            {{ $xmlChave ?: '—' }}
                        </p>
                    </div>
                    <button type="button" wire:click="fecharModalXml" class="text-gray-400 hover:text-gray-600 text-xl leading-none" aria-label="Fechar">&times;</button>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-4 bg-slate-50">
                    @include('livewire.automacao-fiscal.partials.painel-progresso-avulso', [
                        'progresso' => $xmlProgresso,
                        'pipeline' => $xmlPipeline,
                        'logs' => $xmlLogs,
                        'status' => $xmlStatus,
                        'emAndamento' => $xmlStatus === 'running',
                        'token' => $xmlToken,
                        'erro' => $xmlErro,
                        'nomeArquivo' => $xmlNomeArquivo,
                        'fonte' => $xmlFonte,
                        'duracaoMs' => $xmlDuracaoMs,
                        'finishedAt' => $xmlFinishedAt,
                        'parametros' => $xmlParametros,
                        'contextoLabel' => 'NF-e · DistDFe / WS Contabilista',
                        'etapaAtual' => $xmlEtapaAtual,
                        'compact' => true,
                    ])
                </div>

                <div class="px-5 py-4 border-t border-gray-100 flex justify-end gap-2 bg-white shrink-0">
                    <button type="button" wire:click="fecharModalXml"
                            class="inline-flex items-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">
                        {{ $xmlStatus === 'running' ? 'Minimizar' : 'Fechar' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
