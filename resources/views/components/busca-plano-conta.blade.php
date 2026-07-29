@props([
    'valorModel' => '',
    'valor' => '',
    'descricao' => null,
    'resultados' => [],
    'selecionarMethod' => '',
    'buscarMethod' => null,
    'pesquisaHabilitada' => false,
    'placeholder' => 'Ex: 254',
    'inputClass' => 'w-full rounded-md border-gray-300 shadow-sm font-mono text-sm',
])

@php
    $termo = trim((string) $valor);
    $temDescricao = filled($descricao);
    $semResultados = $pesquisaHabilitada
        && mb_strlen($termo) >= 1
        && count($resultados) === 0
        && !$temDescricao;
@endphp

@if(!$pesquisaHabilitada)
    <input
        type="text"
        wire:model.live="{{ $valorModel }}"
        class="{{ $inputClass }}"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
    >
@else
    <div
        x-data="{ aberto: false }"
        @click.outside="aberto = false"
        class="relative"
    >
        <input
            type="search"
            wire:model.live.debounce.300ms="{{ $valorModel }}"
            @focus="aberto = true; @if($buscarMethod) $wire.{{ $buscarMethod }}() @endif"
            @input="aberto = true"
            class="{{ $inputClass }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
            role="combobox"
            aria-autocomplete="list"
            data-form-type="other"
            data-lpignore="true"
            data-1p-ignore
        >

        @if(count($resultados) > 0)
            <ul
                x-show="aberto"
                x-cloak
                class="absolute z-50 mt-1 max-h-56 w-72 overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg"
            >
                @foreach($resultados as $conta)
                    <li>
                        <button
                            type="button"
                            wire:click="{{ $selecionarMethod }}('{{ $conta['codigo'] }}')"
                            @mousedown.prevent
                            @click="aberto = false"
                            class="flex w-full flex-col px-3 py-2 text-left hover:bg-indigo-50"
                        >
                            <span class="text-sm font-medium font-mono text-gray-900">{{ $conta['codigo'] }}</span>
                            <span class="text-xs leading-snug text-gray-500">{{ $conta['descricao'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif($semResultados)
            <p x-show="aberto" x-cloak class="absolute z-50 mt-1 w-72 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow-lg">
                Nenhuma conta encontrada no plano.
            </p>
        @endif
    </div>

    @if($temDescricao)
        <div
            class="mt-0.5 text-[10px] leading-tight text-gray-500 truncate"
            title="{{ $descricao }}"
        >{{ $descricao }}</div>
    @endif
@endif
