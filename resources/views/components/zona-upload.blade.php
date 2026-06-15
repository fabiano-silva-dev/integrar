@props([
    'inputId' => 'arquivo-upload',
    'accept' => '',
    'formato' => '',
    'nomeArquivo' => null,
    'bloqueado' => false,
])

<div
    x-data="{
        dragging: false,
        bloqueado: @js($bloqueado),
        interceptar(e) {
            e?.preventDefault();
            e?.stopPropagation();
            window.dispatchEvent(new CustomEvent('destacar-seletor-empresa'));
            if (this.$wire?.solicitarSelecaoEmpresa) {
                this.$wire.solicitarSelecaoEmpresa();
            }
        },
        onDrop(e) {
            if (this.bloqueado) {
                this.interceptar(e);
                return;
            }
            this.dragging = false;
            const files = e.dataTransfer?.files;
            if (!files?.length) return;
            const input = this.$refs.fileInput;
            input.files = files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }"
    x-on:dragenter.prevent="bloqueado ? null : dragging = true"
    x-on:dragover.prevent="bloqueado ? null : dragging = true"
    x-on:dragleave.prevent="dragging = false"
    x-on:drop.prevent="onDrop($event)"
    {{ $attributes->only('class')->merge(['class' => '']) }}
>
    @if($bloqueado)
        <div
            role="button"
            tabindex="0"
            @click="interceptar($event)"
            @keydown.enter.prevent="interceptar($event)"
            @class([
                'flex flex-col items-center justify-center w-full min-h-[10rem] px-6 py-8 border-2 border-dashed rounded-lg cursor-pointer transition-colors select-none',
                'border-amber-300 bg-amber-50 hover:bg-amber-100/70',
            ])
        >
            <svg class="h-10 w-10 text-amber-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <p class="text-sm font-medium text-amber-900">Selecione a empresa no cabeçalho</p>
            <p class="text-xs text-amber-700 mt-1 text-center">Clique aqui para ir ao seletor de empresa</p>
        </div>
    @else
        <label
            for="{{ $inputId }}"
            @class([
                'flex flex-col items-center justify-center w-full min-h-[10rem] px-6 py-8 border-2 border-dashed rounded-lg cursor-pointer transition-colors select-none',
                'border-green-300 bg-green-50 hover:bg-green-100/70' => $nomeArquivo,
                'border-gray-300 bg-gray-50 hover:border-indigo-400 hover:bg-indigo-50/50' => !$nomeArquivo,
            ])
            :class="dragging ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-200' : ''"
        >
            <input
                id="{{ $inputId }}"
                x-ref="fileInput"
                type="file"
                class="sr-only"
                accept="{{ $accept }}"
                {{ $attributes->whereStartsWith('wire:') }}
            >

            @if($nomeArquivo)
                <svg class="h-10 w-10 text-green-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-medium text-green-800">{{ $nomeArquivo }}</p>
                <p class="text-xs text-indigo-600 mt-2">Clique em qualquer lugar aqui para trocar o arquivo</p>
            @else
                <svg class="h-10 w-10 text-gray-400 mb-2" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <p class="text-sm font-medium text-gray-700">Clique aqui ou arraste o arquivo</p>
                @if($formato)
                    <p class="text-xs text-gray-500 mt-1">{{ $formato }}</p>
                @endif
            @endif
        </label>
    @endif
</div>
