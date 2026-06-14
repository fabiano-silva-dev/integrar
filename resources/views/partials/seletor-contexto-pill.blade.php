{{-- Seletores de escritório e empresa em formato pill (cabeçalho) --}}
<div class="flex flex-wrap items-center gap-3 min-w-0">
    @if(auth()->user()?->isSuperAdmin() && ($operadoras ?? collect())->isNotEmpty())
        <div class="inline-flex items-center gap-2.5 rounded-full bg-gray-100 border border-gray-200 pl-3 pr-1 py-1.5 min-w-0 max-w-full">
            <x-menu-icon name="briefcase" class="w-5 h-5 shrink-0 text-gray-600" />
            <select
                onchange="window.location.href='{{ route('trocar-operadora', ['id' => '__ID__']) }}'.replace('__ID__', this.value || '0')+'?redirect='+encodeURIComponent(window.location.href);"
                class="border-0 bg-transparent text-base font-medium text-gray-800 focus:ring-0 py-1 pr-8 min-w-[8rem] max-w-[14rem] truncate cursor-pointer"
                title="Escritório"
            >
                <option value="">Todos os escritórios</option>
                @foreach($operadoras as $operadora)
                    <option value="{{ $operadora->id }}" {{ session('operadora_context_id') == $operadora->id ? 'selected' : '' }}>
                        {{ $operadora->nome_fantasia ?: $operadora->razao_social }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="inline-flex items-center gap-2.5 rounded-full bg-gray-100 border border-gray-200 pl-3 pr-1 py-1.5 min-w-0 flex-1 sm:flex-initial max-w-full">
        <x-menu-icon name="building" class="w-5 h-5 shrink-0 text-indigo-600" />
        <select
            onchange="if(this.value){window.location.href='{{ route('trocar-empresa', ['id' => '__ID__']) }}'.replace('__ID__', this.value)+'?redirect='+encodeURIComponent(window.location.href);}"
            class="border-0 bg-transparent text-base font-medium text-gray-800 focus:ring-0 py-1 pr-8 min-w-[10rem] max-w-[22rem] truncate cursor-pointer w-full sm:w-auto"
            title="{{ $empresaAtual ? ($empresaAtual->codigo_sistema ?? '—') . ' - ' . $empresaAtual->nome . ' - ' . $empresaAtual->cnpj : 'Selecione a empresa' }}"
        >
            <option value="">Selecione a empresa...</option>
            @foreach($empresas ?? [] as $empresa)
                <option value="{{ $empresa->id }}" {{ session('empresa_selecionada_id') == $empresa->id ? 'selected' : '' }}>
                    {{ $empresa->codigo_sistema ?? '—' }} - {{ $empresa->nome }} - {{ $empresa->cnpj }}
                </option>
            @endforeach
        </select>
    </div>
</div>
