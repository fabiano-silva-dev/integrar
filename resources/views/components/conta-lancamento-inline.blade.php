@props([
    'lancamentoId',
    'campo',
    'valor' => '',
    'nome' => null,
    'temPlano' => false,
    'aberto' => false,
    'busca' => '',
    'resultados' => [],
    'inputClass' => '',
    'placeholder' => '',
])

@php
    $semResultados = $aberto && mb_strlen(trim((string) $busca)) >= 1 && count($resultados) === 0;
@endphp

<div class="relative w-full" onclick="event.stopPropagation()">
    @if($temPlano && $aberto)
        <div
            x-data
            @click.outside="$wire.confirmarContaInline()"
            class="relative"
        >
            <input
                type="search"
                wire:model.live.debounce.300ms="contaInlineBusca"
                wire:keydown.enter.prevent="confirmarContaInline"
                wire:keydown.escape.prevent="fecharBuscaContaInline"
                class="w-full rounded border-2 border-blue-500 text-xs bg-white focus:border-blue-600 {{ $inputClass }}"
                placeholder="Código ou nome"
                autocomplete="off"
                autocorrect="off"
                autocapitalize="off"
                spellcheck="false"
                data-lancamento-id="{{ $lancamentoId }}"
                data-campo="{{ $campo }}"
                autofocus
            >

            @if(count($resultados) > 0)
                <ul class="absolute left-0 z-50 mt-1 max-h-48 w-72 overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                    @foreach($resultados as $conta)
                        <li>
                            <button
                                type="button"
                                wire:click="selecionarContaInline('{{ $conta['codigo'] }}')"
                                @mousedown.prevent
                                class="flex w-full flex-col px-2 py-1.5 text-left hover:bg-indigo-50"
                            >
                                <span class="text-xs font-mono font-medium text-gray-900">{{ $conta['codigo'] }}</span>
                                <span class="text-[10px] leading-snug text-gray-500">{{ $conta['descricao'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif($semResultados)
                <p class="absolute left-0 z-50 mt-1 w-72 rounded-md border border-gray-200 bg-white px-2 py-1.5 text-[10px] text-gray-500 shadow-lg">
                    Nenhuma conta encontrada.
                </p>
            @endif
        </div>
    @elseif($temPlano)
        <input
            type="text"
            value="{{ $valor }}"
            readonly
            wire:click="abrirBuscaContaInline({{ $lancamentoId }}, '{{ $campo }}')"
            wire:focus="abrirBuscaContaInline({{ $lancamentoId }}, '{{ $campo }}')"
            class="w-full rounded border-2 border-black text-xs bg-white focus:border-blue-500 cursor-pointer {{ $inputClass }}"
            placeholder="{{ $placeholder }}"
            data-lancamento-id="{{ $lancamentoId }}"
            data-campo="{{ $campo }}"
            onkeydown="handleKeyDown(event, this)"
        >
    @else
        <input
            type="text"
            value="{{ $valor }}"
            wire:blur="iniciarEdicao({{ $lancamentoId }}, '{{ $campo }}', $event.target.value)"
            class="w-full rounded border-2 border-black text-xs bg-white focus:border-blue-500 {{ $inputClass }}"
            placeholder="{{ $placeholder }}"
            data-lancamento-id="{{ $lancamentoId }}"
            data-campo="{{ $campo }}"
            onkeydown="handleKeyDown(event, this)"
        >
    @endif

    @if($temPlano && filled($nome))
        <div
            class="mt-0.5 text-[10px] leading-tight text-gray-500 truncate"
            title="{{ $nome }}"
        >{{ $nome }}</div>
    @endif
</div>
