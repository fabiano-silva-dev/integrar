<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex flex-wrap justify-between gap-3">
            <h1 class="text-2xl font-bold">Fila de homologação NFS-e</h1>
            <a href="{{ route('nfse.homologacao') }}" class="text-blue-600">Preparar e revisar DPS</a>
        </div>
        <p class="mt-3 border border-amber-300 bg-amber-50 rounded-md p-3 font-semibold text-amber-900">PRODUÇÃO RESTRITA — SEM VALOR FISCAL. Somente prestador piloto e DPS já assinadas. Nenhum documento de produção é emitido.</p>
        @if(session('nfse_fila_mensagem'))<p role="status" class="mt-3">{{ session('nfse_fila_mensagem') }}</p>@endif
        @if($errors->any())<div role="alert" class="mt-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
        @if(!$empresa)
            <p class="mt-4">Selecione a empresa no cabeçalho.</p>
        @else
            <p class="mt-4"><strong>{{ $empresa->razao_social ?: $empresa->nome }}</strong> · {{ $empresa->cnpj }}</p>
            @if(!$liberada)<p class="mt-3 text-gray-600">Fila externa bloqueada. O histórico continua disponível.</p>@endif
            <label class="block mt-4 text-sm">Competência<input type="month" wire:model.live="mes" class="block rounded-md border-gray-300"></label>
            @if(!$mesValido)
                <p class="mt-3 text-red-700">Informe a competência.</p>
            @else
                <h2 class="mt-5 font-bold">DPS disponíveis para revisão</h2>
                <p class="text-sm text-gray-600">Baixe e revise cada XML antes de selecionar. Até 20 por fila. A aprovação e reserva ocorrem em conjunto; o envio individual será processado pelo worker.</p>
                @forelse($candidatas as $dps)
                    <div wire:key="fila-dps-{{ $dps->id }}" class="flex flex-wrap items-center justify-between gap-3 p-3 border-b text-sm">
                        <div><strong>DPS {{ $dps->numero }} · nota #{{ $dps->nota_id }}</strong><p class="break-all">{{ $dps->id_dps }}</p><p>Versão {{ $dps->versao_nota }}</p></div>
                        <a href="{{ route('nfse.homologacao.dps',['empresa'=>$empresa->id,'dps'=>$dps->id]) }}" class="text-blue-600">Baixar XML</a>
                        @if($liberada)<button type="button" wire:click="selecionar({{ $dps->id }})" wire:loading.attr="disabled" class="border rounded-md px-3 py-2 {{ isset($selecionadas[$dps->id]) ? 'bg-blue-600 text-white' : 'text-blue-600' }}">{{ isset($selecionadas[$dps->id]) ? 'Selecionada — remover' : 'Selecionar após revisão' }}</button>@endif
                    </div>
                @empty<p class="mt-3 text-gray-600">Nenhuma DPS disponível.</p>@endforelse
                {{ $candidatas->links() }}
                @if($liberada && count($selecionadas))
                    <form wire:submit="aprovar" class="mt-4 border rounded-md p-4 space-y-3">
                        <p class="font-bold">{{ count($selecionadas) }} DPS selecionadas (incluindo outras páginas)</p>
                        <label class="block text-sm"><input type="checkbox" wire:model="confirmacao" required> Revisei todos os XML selecionados, confirmei os poderes do certificado e autorizo uma tentativa externa por DPS exclusivamente em produção restrita.</label>
                        <div class="flex flex-wrap gap-3"><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-md">Aprovar e enfileirar</button><button type="button" wire:click="limpar" class="text-blue-600">Limpar seleção</button></div>
                    </form>
                @endif
                <div wire:poll.10s class="mt-6">
                    <h2 class="font-bold">Histórico de filas</h2>
                    @forelse($filas as $fila)
                        @php($itens = $resultados->get($fila->id, collect()))
                        <section wire:key="fila-hist-{{ $fila->id }}" class="border rounded-md p-4 mt-3">
                            <div class="flex flex-wrap justify-between gap-3"><strong>Fila #{{ $fila->id }} · {{ $fila->quantidade }} DPS · {{ $fila->created_at->format('d/m/Y H:i') }}</strong>
                                @if($liberada && $itens->contains('fila_status','pendente'))<button type="button" wire:click="reenfileirar({{ $fila->id }})" wire:confirm="Despachar apenas os itens ainda pendentes e sem tentativa?" wire:loading.attr="disabled" class="text-blue-600">Despachar pendentes</button>@endif
                            </div>
                            @foreach($itens as $item)
                                <div class="p-2 border-b text-sm"><strong>Nota #{{ $item->nota_id }} · DPS interna #{{ $item->dps_local_id }}</strong>
                                    <p>Fila: {{ $item->fila_status }} · resultado fiscal de teste: {{ $item->status }}</p>
                                    @if($item->fila_status==='bloqueado' || $item->fila_status==='processando')<p class="text-amber-900">Se interrompido ou bloqueado, revise o histórico individual. Não reenfileire este item automaticamente.</p>@endif
                                    @if($item->envio_iniciado_em && in_array($item->status,['envio_iniciado','incerta','rejeitada_teste'],true))<p><a href="{{ route('nfse.homologacao',['dps'=>$item->dps_local_id]) }}" class="text-blue-600">Consultar resultado individual sem reenviar</a></p>@endif
                                    @if($item->status==='confirmada_teste')<p class="flex flex-wrap gap-3"><a href="{{ route('nfse.homologacao.retorno',['empresa'=>$empresa->id,'transmissao'=>$item->id,'formato'=>'xml']) }}" class="text-blue-600">XML teste</a><a href="{{ route('nfse.homologacao.retorno',['empresa'=>$empresa->id,'transmissao'=>$item->id,'formato'=>'pdf']) }}" class="text-blue-600">DANFSe teste</a></p>@endif
                                </div>
                            @endforeach
                        </section>
                    @empty<p class="mt-3 text-gray-600">Nenhuma fila nesta competência.</p>@endforelse
                    {{ $filas->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
