<div>
    @if(auth()->user()?->isSuperAdmin() && $operadoras->isNotEmpty())
        <div class="flex items-center gap-2">
            <label for="operadora-global" class="text-xs text-gray-500 whitespace-nowrap hidden lg:inline">Escritório:</label>
            <select
                id="operadora-global"
                wire:model.live="operadoraSelecionada"
                class="text-sm border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 max-w-[180px] lg:max-w-[220px]"
            >
                <option value="">Todos os escritórios</option>
                @foreach($operadoras as $operadora)
                    <option value="{{ $operadora->id }}">
                        {{ $operadora->nome_fantasia ?: $operadora->razao_social }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
</div>
