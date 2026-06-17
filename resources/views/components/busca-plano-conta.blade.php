@props([
    'valorModel' => '',
    'valor' => '',
    'resultados' => [],
    'selecionarMethod' => '',
    'pesquisaHabilitada' => false,
    'placeholder' => 'Ex: 254',
    'inputClass' => 'w-full rounded-md border-gray-300 shadow-sm font-mono text-sm',
])

@php
    $termo = trim($valor);
    $semResultados = $pesquisaHabilitada && mb_strlen($termo) >= 2 && count($resultados) === 0;
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
            @focus="aberto = true"
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
                class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg"
            >
                @foreach($resultados as $conta)
                    <li>
                        <button
                            type="button"
                            wire:click="{{ $selecionarMethod }}('{{ $conta['codigo'] }}')"
                            @click="aberto = false"
                            class="flex w-full flex-col px-3 py-2 text-left hover:bg-indigo-50"
                        >
                            <span class="text-sm font-medium font-mono text-gray-900">{{ $conta['codigo'] }}</span>
                            <span class="text-xs text-gray-500">{{ $conta['descricao'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif($semResultados)
            <p x-show="aberto" x-cloak class="absolute z-20 mt-1 w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow-lg">
                Nenhuma conta encontrada no plano.
            </p>
        @endif
    </div>
@endif
