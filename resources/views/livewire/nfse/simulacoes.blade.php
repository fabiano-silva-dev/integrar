<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h1 class="text-2xl font-bold">Processamento simulado de NFS-e</h1>
        <p class="mt-3 bg-gray-50 border border-gray-200 rounded-md p-3">Ambiente de simulação interna. Não acessa o portal, não utiliza credenciais, não gera DPS e não autoriza NFS-e. Os lotes e notas fiscais permanecem inalterados.</p>
        @if(session('nfse_sim_mensagem'))<p role="status" class="mt-3">{{ session('nfse_sim_mensagem') }}</p>@endif
        @if($errors->any())<div role="alert" class="mt-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
        @if(!$empresa)<p class="mt-4">Selecione a empresa prestadora no cabeçalho.</p>@else
            <p class="mt-4"><strong>{{ $empresa->razao_social ?: $empresa->nome }}</strong> · {{ $empresa->cnpj }}</p>
            <div class="flex flex-wrap gap-4 mt-4"><label class="block text-sm">Competência<input type="month" wire:model.live="mes" class="w-full rounded-md border-gray-300"></label><label class="block text-sm">Cenário de teste<select wire:model="cenario" class="w-full rounded-md border-gray-300"><option value="sucesso">Sucesso simulado</option><option value="misto">Misto (1ª rejeitada, 2ª incerta, demais sucesso)</option><option value="incerto">Resultado incerto para todos</option></select></label></div>
            @if(!$mesValido)<p class="mt-3 text-red-700">Informe a competência.</p>@else
                <h2 class="text-lg font-bold mt-6">Lotes disponíveis para aprovação simulada</h2>
                @forelse($lotes as $lote)<div wire:key="sim-lote-{{ $lote->id }}" class="flex flex-wrap justify-between gap-2 p-3 border-b border-gray-200"><p>Lote #{{ $lote->id }} · {{ $lote->quantidade }} notas · R$ {{ \App\Services\Nfse\ConferenciaNfse::formatarValor($lote->valor_total) }}</p><button type="button" wire:click="aprovar({{ $lote->id }})" wire:confirm="Aprovar exclusivamente para simulação? Nenhum documento será emitido." wire:loading.attr="disabled" class="text-blue-600">Aprovar e simular</button></div>@empty<p class="mt-3 text-gray-600">Nenhum lote preparado sem simulação.</p>@endforelse
                {{ $lotes->links() }}
                <div wire:poll.10s>
                    <h2 class="text-lg font-bold mt-6">Histórico de simulações (últimas 50)</h2>
                    @forelse($simulacoes as $sim)<div wire:key="sim-{{ $sim->id }}" class="flex flex-wrap justify-between gap-2 p-3 border-b border-gray-200"><p>Simulação #{{ $sim->id }} · lote #{{ $sim->lote_id }} · {{ $sim->cenario }} · {{ $sim->status }}</p><div><button type="button" wire:click="abrir({{ $sim->id }})" class="text-blue-600">Resultados</button>@if($sim->status==='pendente') <button type="button" wire:click="reenfileirar({{ $sim->id }})" wire:loading.attr="disabled" class="text-blue-600">Reenfileirar</button>@endif</div></div>@empty<p class="text-gray-600 mt-3">Nenhuma simulação.</p>@endforelse
                    @if($detalheId)<h3 class="font-bold mt-4">Itens da simulação #{{ $detalheId }}</h3>@forelse($itens as $item)<div wire:key="sim-item-{{ $item->id }}" class="flex flex-wrap justify-between gap-2 p-3 border-b border-gray-200"><p>Nota interna #{{ $item->nota_id }} · {{ $item->status }}<br><span class="text-sm text-gray-600">{{ $item->mensagem }}</span></p>@if($item->status==='simulada_incerta')<button type="button" wire:click="reconciliar({{ $item->id }})" wire:loading.attr="disabled" class="text-blue-600">Simular consulta do resultado</button>@endif</div>@empty<p class="text-gray-600 mt-3">Sem resultados; acompanhe o status da fila.</p>@endforelse @endif
                </div>
            @endif
        @endif
    </div>
</div>
