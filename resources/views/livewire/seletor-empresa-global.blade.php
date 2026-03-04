<div class="flex items-center gap-2 min-w-0">
    <label class="shrink-0 text-xs text-gray-500 hidden sm:block">Empresa</label>
    <select
        onchange="if(this.value){window.location.href='{{ route('trocar-empresa', ['id' => '__ID__']) }}'.replace('__ID__', this.value)+'?redirect='+encodeURIComponent(window.location.href);}"
        class="w-full min-w-[175px] max-w-[280px] text-xs sm:text-sm rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 py-1.5"
        title="{{ $empresaAtual ? ($empresaAtual->codigo_sistema ?? '—') . ' - ' . $empresaAtual->nome . ' - ' . $empresaAtual->cnpj : '' }}"
    >
        <option value="">Selecione...</option>
        @foreach($empresas as $empresa)
            <option value="{{ $empresa->id }}" {{ $empresaSelecionada == $empresa->id ? 'selected' : '' }}>
                {{ $empresa->codigo_sistema ?? '—' }} - {{ $empresa->nome }} - {{ $empresa->cnpj }}
            </option>
        @endforeach
    </select>
</div>

