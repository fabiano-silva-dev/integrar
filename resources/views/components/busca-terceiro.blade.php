@props([
    'terceiroId' => null,
    'terceiroNome' => '',
    'buscaModel' => '',
    'buscaValor' => '',
    'resultados' => [],
    'selecionarMethod' => '',
    'limparMethod' => '',
])

@php
    $selecionado = filled($terceiroId) || filled($terceiroNome);
    $rotulo = $terceiroNome ?: 'Terceiro selecionado';
    $semResultados = !$selecionado && mb_strlen(trim($buscaValor)) >= 2 && count($resultados) === 0;
@endphp

<div
    x-data="{ aberto: false, autofillBloqueado: true }"
    @click.outside="aberto = false"
    class="relative"
>
    @if($selecionado)
        <div class="flex min-h-[42px] items-center">
            <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 py-1 pl-3 pr-1 text-sm font-medium text-indigo-900">
                <span class="truncate" title="{{ $rotulo }}">{{ $rotulo }}</span>
                <button
                    type="button"
                    wire:click="{{ $limparMethod }}"
                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-indigo-600 transition hover:bg-indigo-200 hover:text-indigo-900"
                    title="Remover terceiro"
                    aria-label="Remover terceiro"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </span>
        </div>
    @else
        <input
            type="search"
            name="busca_terceiro_integrar"
            wire:model.live.debounce.300ms="{{ $buscaModel }}"
            :readonly="autofillBloqueado"
            @focus="autofillBloqueado = false; aberto = true"
            @input="aberto = true"
            class="w-full rounded-md border-gray-300 shadow-sm"
            placeholder="Digite para buscar terceiro..."
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
                @foreach($resultados as $terceiro)
                    <li>
                        <button
                            type="button"
                            wire:click="{{ $selecionarMethod }}({{ $terceiro['id'] }})"
                            @click="aberto = false"
                            class="flex w-full flex-col px-3 py-2 text-left hover:bg-indigo-50"
                        >
                            <span class="text-sm font-medium text-gray-900">{{ $terceiro['nome'] }}</span>
                            @if(!empty($terceiro['cnpj_cpf']))
                                <span class="text-xs text-gray-500">{{ $terceiro['cnpj_cpf'] }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif($semResultados)
            <p x-show="aberto" x-cloak class="absolute z-20 mt-1 w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow-lg">
                Nenhum terceiro encontrado.
            </p>
        @endif
    @endif
</div>
