{{--
  Painel de progresso alinhado a Executar consulta (Etapas + Atividade + Resultado).
  Variáveis esperadas:
  - $progresso (ExecucaoProgressoPresenter)
  - $pipeline, $logs
  - $status, $emAndamento, $token, $erro, $nomeArquivo
  - $duracaoMs, $finishedAt, $parametros, $contextoLabel, $etapaAtual
  - $compact (bool, modal)
--}}
@php
    $compact = $compact ?? false;
    $parametros = $parametros ?? [];
    $pipeline = $pipeline ?? [];
    $logs = $logs ?? [];
    $contextoLabel = $contextoLabel ?? null;
    $etapaAtual = $etapaAtual ?? null;
    $fonte = $fonte ?? null;
    $fonteLabel = \App\Services\AutomacaoFiscal\ExecucaoProgressoPresenter::labelFonteDownload($fonte);
    $mostrarDownload = ($status ?? '') === 'succeeded' && ! empty($token);
    $mostrarDanfe = $mostrarDownload && is_string($nomeArquivo ?? null) && str_ends_with((string) $nomeArquivo, '-nfe.xml');
    $mostrarDanfse = $mostrarDownload && is_string($nomeArquivo ?? null) && str_ends_with((string) $nomeArquivo, '-nfse.xml');
@endphp

<div class="space-y-4">
    @if (! $emAndamento && in_array($status, ['succeeded', 'failed'], true))
        <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 flex flex-wrap items-start justify-between gap-3 border-b border-gray-100">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-gray-900">Resultado</h2>
                        <span @class([
                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-emerald-100 text-emerald-800' => $status === 'succeeded',
                            'bg-red-100 text-red-800' => $status === 'failed',
                        ])>
                            {{ $progresso->labelStatusAvulso($status) }}
                        </span>
                    </div>
                    @if ($contextoLabel)
                        <p class="mt-0.5 text-sm text-gray-500 truncate">{{ $contextoLabel }}</p>
                    @endif
                </div>
                @if ($finishedAt)
                    <p class="text-xs text-gray-400 shrink-0">{{ $finishedAt }}</p>
                @endif
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-gray-100">
                <div class="px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">Status</p>
                    <p class="mt-0.5 text-lg font-semibold text-gray-900">{{ $progresso->labelStatusAvulso($status) }}</p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">Duração</p>
                    <p class="mt-0.5 text-lg font-semibold text-gray-900">
                        @if ($duracaoMs)
                            {{ number_format($duracaoMs / 1000, 1, ',', '.') }}s
                        @else
                            —
                        @endif
                    </p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">Arquivo</p>
                    <p class="mt-0.5 text-sm font-semibold text-gray-900 truncate" title="{{ $nomeArquivo ?: '—' }}">
                        {{ $nomeArquivo ?: '—' }}
                    </p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wide text-gray-500">Etapa</p>
                    <p class="mt-0.5 text-sm font-semibold text-gray-900 truncate" title="{{ $etapaAtual ?: '—' }}">
                        {{ $etapaAtual ?: '—' }}
                    </p>
                </div>
            </div>

            @if ($status === 'succeeded' && $nomeArquivo)
                <div class="px-5 py-3 border-t border-gray-100 text-sm text-gray-800">
                    XML disponível: {{ $nomeArquivo }}
                    @if ($fonteLabel)
                        <p class="mt-1 text-xs text-gray-500">
                            Baixado via <span class="font-medium text-gray-700">{{ $fonteLabel }}</span>
                        </p>
                    @endif
                </div>
            @endif

            @if ($status === 'failed' && $erro)
                <div class="mx-5 mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-red-700 mb-2">Erros</p>
                    <p class="text-sm text-red-800">{{ $erro }}</p>
                </div>
            @endif

            @if (! empty($parametros))
                <details class="border-t border-gray-100 px-5 py-3 group">
                    <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500 list-none flex items-center justify-between">
                        <span>Parâmetros usados</span>
                        <span class="text-gray-400 group-open:rotate-180 transition-transform">▾</span>
                    </summary>
                    <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                        @foreach ($parametros as $k => $v)
                            <div>
                                <dt class="text-gray-500">{{ str_replace('_', ' ', (string) $k) }}</dt>
                                <dd class="font-medium text-gray-900 break-all">
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

            @if ($mostrarDownload)
                <div class="px-5 py-4 border-t border-gray-100 flex justify-end gap-2">
                    @if ($mostrarDanfe)
                        <a href="{{ route('automacao-fiscal.documento.xml.danfe', $token) }}"
                           target="_blank"
                           class="inline-flex items-center rounded-xl bg-white border border-indigo-200 px-4 py-2.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                            Visualizar DANFE (PDF)
                        </a>
                    @endif
                    @if ($mostrarDanfse)
                        <a href="{{ route('automacao-fiscal.documento.xml.danfse', $token) }}"
                           target="_blank"
                           class="inline-flex items-center rounded-xl bg-white border border-indigo-200 px-4 py-2.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                            Visualizar DANFSe (PDF)
                        </a>
                    @endif
                    <a href="{{ route('automacao-fiscal.documento.xml.arquivo', $token) }}"
                       class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">
                        Baixar XML
                    </a>
                </div>
            @endif
        </div>
    @endif

    @if ($token || $emAndamento || in_array($status, ['running', 'succeeded', 'failed'], true))
        <div @class([
            'overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 text-slate-100 shadow-xl flex flex-col',
            'h-[min(28rem,55vh)]' => $compact,
            'h-[calc(100dvh-10rem)] max-h-[calc(100dvh-10rem)] xl:sticky xl:top-4' => ! $compact && $emAndamento,
            'h-[calc(100dvh-14rem)] max-h-[28rem]' => ! $compact && ! $emAndamento,
        ])>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 px-4 py-2.5 shrink-0">
                <div class="flex items-center gap-3">
                    <span @class([
                        'inline-flex h-2.5 w-2.5 rounded-full',
                        'bg-sky-400 animate-pulse' => $emAndamento,
                        'bg-emerald-400' => ! $emAndamento && $status === 'succeeded',
                        'bg-red-400' => ! $emAndamento && $status === 'failed',
                        'bg-slate-500' => ! $emAndamento && ! in_array($status, ['succeeded', 'failed'], true),
                    ])></span>
                    <div>
                        <p class="text-sm font-semibold tracking-tight">
                            @if ($emAndamento)
                                Processando execução…
                            @else
                                Histórico da execução
                            @endif
                        </p>
                        <p class="text-xs text-slate-400">
                            {{ $progresso->labelStatusAvulso($status) }}
                            @if ($emAndamento)
                                · atualiza a cada 1,5s
                            @elseif ($finishedAt)
                                · {{ $finishedAt }}
                            @endif
                        </p>
                    </div>
                </div>
                @if ($token)
                    <div class="text-xs text-slate-500 font-mono">#{{ substr($token, -8) }}</div>
                @endif
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
                                    @if (! empty($step['detail']))
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
                        class="flex-1 space-y-2 overflow-y-auto px-3 py-3 min-h-0"
                        wire:key="feed-avulso-{{ count($logs) }}-{{ $status }}"
                        x-data="{
                            scrollBottom() {
                                this.$nextTick(() => { this.$el.scrollTop = this.$el.scrollHeight; });
                            }
                        }"
                        x-init="
                            scrollBottom();
                            new MutationObserver(() => scrollBottom()).observe($el, { childList: true, subtree: true });
                            Livewire.hook('morph.updated', ({ el }) => {
                                if ($el === el || $el.contains(el)) scrollBottom();
                            });
                        "
                    >
                        @forelse ($logs as $log)
                            @php
                                $etapa = (string) ($log['eventType'] ?? '');
                                $nivel = (string) ($log['level'] ?? 'info');
                                if ($nivel === 'warn') {
                                    $nivel = 'warning';
                                }
                                $mensagem = (string) ($log['message'] ?? '');
                            @endphp
                            <article @class([
                                'rounded-lg border px-3 py-2',
                                'border-red-500/40 bg-red-950/40' => $nivel === 'error' || in_array($etapa, ['RUN_FAILED', 'JOB_FAILED', 'erro'], true),
                                'border-amber-500/40 bg-amber-950/30' => $nivel === 'warning' || in_array($etapa, ['MANUAL_CONFIRMATION_DETECTED', 'ROLE_SELECTION_DETECTED'], true),
                                'border-emerald-500/30 bg-emerald-950/20' => in_array($etapa, ['AUTHENTICATION_CONFIRMED', 'RUN_FINISHED', 'JOB_FINISHED'], true),
                                'border-slate-700 bg-slate-900/60' => ! ($nivel === 'error' || $nivel === 'warning' || in_array($etapa, ['RUN_FAILED', 'JOB_FAILED', 'erro', 'MANUAL_CONFIRMATION_DETECTED', 'ROLE_SELECTION_DETECTED', 'AUTHENTICATION_CONFIRMED', 'RUN_FINISHED', 'JOB_FINISHED'], true)),
                            ])>
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-100">
                                        {{ $progresso->labelEvento($etapa, $mensagem) }}
                                    </p>
                                    <span class="shrink-0 font-mono text-[10px] text-slate-500">{{ $log['at'] ?? '' }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-slate-300">{{ $mensagem }}</p>
                                @if ($etapa !== '')
                                    <p class="mt-1 font-mono text-[10px] text-slate-500">{{ $etapa }}</p>
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
                Ao executar, o andamento, as etapas, os erros e o resultado aparecem neste painel.
            </p>
        </div>
    @endif
</div>
