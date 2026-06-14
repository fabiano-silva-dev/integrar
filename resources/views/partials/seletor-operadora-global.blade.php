@if(auth()->user()?->isSuperAdmin() && $operadoras->isNotEmpty())
    <div class="flex items-center gap-2 shrink-0">
        <label for="operadora-global" class="text-xs text-gray-500 whitespace-nowrap">Escritório:</label>
        <select
            id="operadora-global"
            onchange="window.location.href='{{ route('trocar-operadora', ['id' => '__ID__']) }}'.replace('__ID__', this.value || '0')+'?redirect='+encodeURIComponent(window.location.href);"
            class="text-sm border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 w-[200px]"
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
