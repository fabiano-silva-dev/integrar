<div class="flex items-center gap-2 shrink-0">
    <label class="shrink-0 text-xs text-gray-500 whitespace-nowrap">Empresa:</label>
    <select
        onchange="if(this.value){window.location.href='{{ route('trocar-empresa', ['id' => '__ID__']) }}'.replace('__ID__', this.value)+'?redirect='+encodeURIComponent(window.location.href);}"
        class="text-sm rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 py-1.5 w-[280px] max-w-[40vw]"
        title="{{ $empresaAtual ? ($empresaAtual->codigo_sistema ?? '—') . ' - ' . $empresaAtual->nome . ' - ' . $empresaAtual->cnpj : '' }}"
    >
        <option value="">Selecione...</option>
        @foreach($empresas as $empresa)
            <option value="{{ $empresa->id }}" {{ session('empresa_selecionada_id') == $empresa->id ? 'selected' : '' }}>
                {{ $empresa->codigo_sistema ?? '—' }} - {{ $empresa->nome }} - {{ $empresa->cnpj }}
            </option>
        @endforeach
    </select>
</div>
