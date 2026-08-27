@php
    $avisoFilaDev = app(\App\Services\AutomacaoFiscal\FilaAutomacoesStatus::class)->avisoCabecalhoDesenvolvimento();
@endphp
@if ($avisoFilaDev)
    <div class="border-t border-amber-300 bg-amber-50 px-4 sm:px-6 py-2.5" role="status">
        <p class="text-sm font-semibold text-amber-950">{{ $avisoFilaDev['titulo'] }}</p>
        <p class="mt-0.5 text-xs text-amber-900">{{ $avisoFilaDev['texto'] }} Recarregue a página depois de iniciar.</p>
        <pre class="mt-1 overflow-x-auto rounded-md bg-amber-100 px-2 py-1.5 text-[11px] leading-snug text-amber-950">{{ $avisoFilaDev['comando'] }}</pre>
    </div>
@endif
