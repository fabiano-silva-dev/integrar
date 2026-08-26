@props(['aviso' => null])

@php
    $aviso = $aviso ?? app(\App\Services\AutomacaoFiscal\FilaAutomacoesStatus::class)->avisoDesenvolvimento();
@endphp

@if ($aviso)
    <div {{ $attributes->merge(['class' => 'rounded-xl border border-amber-400 bg-amber-50 px-4 py-3 text-amber-950']) }}>
        <p class="text-sm font-semibold">{{ $aviso['titulo'] }}</p>
        <p class="mt-1 text-sm">{{ $aviso['texto'] }}</p>
        <p class="mt-2 text-xs font-medium text-amber-900">No terminal do projeto:</p>
        <pre class="mt-1 overflow-x-auto rounded-lg bg-amber-100/80 px-3 py-2 text-xs text-amber-950">{{ $aviso['comando'] }}</pre>
        <p class="mt-2 text-xs text-amber-800">O aviso some sozinho quando o worker iniciar. Recarregue se continuar aparecendo.</p>
    </div>
@endif
