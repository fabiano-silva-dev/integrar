<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h1 class="text-2xl font-bold">Homologação de NFS-e</h1>
        <p class="mt-3 border border-amber-300 bg-amber-50 rounded-md p-3 text-sm font-semibold text-amber-900">AMBIENTE DE TESTES — SEM VALOR FISCAL. Nenhuma operação desta tela emite em produção ou altera o acervo fiscal real. Use dados autorizados para o piloto.</p>
        @if(session('nfse_homologacao_mensagem'))<p role="status" class="mt-3 text-gray-800">{{ session('nfse_homologacao_mensagem') }}</p>@endif
        @if($errors->any())<div role="alert" class="mt-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
        @if(!$empresa)
            <p class="mt-4">Selecione a empresa prestadora no cabeçalho.</p>
        @else
            <div class="flex flex-wrap justify-between gap-3 mt-4">
                <strong>{{ $empresa->razao_social ?: $empresa->nome }} · {{ $empresa->cnpj }}</strong>
                <div class="flex gap-4"><a href="{{ route('nfse.acessos') }}" class="text-blue-600">Configurar acesso</a><a href="{{ route('nfse.central', ['aba'=>'conferencia','mes'=>$mes]) }}" class="text-blue-600">Conferência</a></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3 text-sm">
                <div class="border rounded-md p-3"><strong>Preparação local</strong><p>{{ $local ? 'Liberada para o piloto' : 'Desabilitada ou prestador fora do piloto' }}</p></div>
                <div class="border rounded-md p-3"><strong>Comunicação restrita</strong><p>{{ $rede ? 'Habilitada na configuração' : 'Bloqueada' }}</p></div>
                <div class="border rounded-md p-3"><strong>XSD</strong><p>{{ $xsd ? 'Arquivo configurado (não comprova homologação)' : 'Pacote oficial não configurado' }}</p></div>
                <div class="border rounded-md p-3"><strong>Cadastro fiscal</strong><p>{{ $cfg?->habilitado_preparo ? 'Preparo habilitado' : 'Preparo desabilitado' }} · {{ $cfg?->regime_tributario ?: 'Regime pendente' }}</p></div>
                <div class="border rounded-md p-3"><strong>Acesso salvo</strong><p>{{ $acesso?->modo ?? 'Não configurado' }} · {{ $acesso?->canal ?? '—' }}</p><p>Certificado #{{ $acesso?->certificado_digital_id ?? '—' }}</p></div>
                <div class="border rounded-md p-3"><strong>Sequência de teste</strong><p>Série {{ $cfg?->serie_dps ?: '—' }} · {{ $seq ? 'Último número: '.$seq->ultimo_numero : 'Não inicializada' }}</p></div>
            </div>
            @if($local && $cfg?->habilitado_preparo && !$seq)
                <form wire:submit="inicializar" class="mt-4 border rounded-md p-4 space-y-3">
                    <h2 class="font-bold">Inicializar sequência</h2>
                    <p class="text-sm">Informe o último número utilizado nesta série exclusivamente no ambiente restrito. Zero somente se confirmou que nunca foi usada. Não é possível reiniciar.</p>
                    <label class="block text-sm">Último número<input wire:model="ultimoNumero" inputmode="numeric" pattern="[0-9]{1,15}" maxlength="15" required class="block rounded-md border-gray-300"></label>
                    <label class="block"><input type="checkbox" wire:model="confirmouSequencia" required> Confirmei a série e o último número de teste.</label>
                    <button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-md">Inicializar</button>
                </form>
            @endif
            <label class="block mt-6 text-sm">Competência<input type="month" wire:model.live="mes" class="block rounded-md border-gray-300"></label>
            @if(!$mesValido)<p class="text-red-700 mt-2">Informe uma competência válida.</p>@else
                <h2 class="font-bold mt-5">Notas em lotes preparados</h2>
                @forelse($notas as $n)
                    <div wire:key="hom-nota-{{ $n->id }}" class="flex flex-wrap gap-2 justify-between border-b p-3 text-sm">
                        <div><strong>#{{ $n->id }} · {{ data_get($n->snapshot, 'tomador.nome') }}</strong><p>Lote #{{ $n->lote_id }} · {{ $n->competencia->format('d/m/Y') }} · R$ {{ $n->valor }}</p><p>{{ $n->descricao }}</p></div>
                        @if($local && $seq && $xsd)<button type="button" wire:click="selecionarNota({{ $n->id }})" wire:loading.attr="disabled" class="text-blue-600">Preparar DPS de teste</button>@endif
                    </div>
                @empty<p class="mt-2 text-sm text-gray-600">Nenhuma nota em lote preparado nesta competência.</p>@endforelse
                {{ $notas->links() }}
                @if($nota && $local)
                    <form wire:submit="preparar" class="mt-4 border rounded-md p-4 space-y-3">
                        <h3 class="font-bold">Dados fiscais explícitos — nota #{{ $nota->id }}</h3>
                        @if($perfil)
                            <p class="text-sm">Perfil #{{ $perfil->id }} · {{ $perfil->servico_id ? 'Serviço específico' : 'Padrão da empresa' }} · vigência {{ $perfil->vigencia->format('m/Y') }}. Valores recuperados automaticamente; confirme o caso de teste.</p>
                            @foreach($impedimentosPerfil as $impedimento)<p class="text-sm text-red-700">{{ $impedimento }}</p>@endforeach
                            <a href="{{ route('nfse.perfis-tributarios') }}" class="text-blue-600 text-sm">Consultar perfis tributários</a>
                        @else
                            <p class="text-sm text-gray-600">Sem perfil vigente. Preenchimento manual de homologação.</p>
                        @endif
                        <p class="text-sm">Escopo inicial: nota regular de serviço nacional comum, com destinatário igual ao tomador, ISS tributado, sem retenções, deduções, benefícios ou regime especial. Não use em outros casos. Não há cálculo automático de tributos.</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <label class="text-sm">Município da prestação (IBGE)<input wire:model="fiscal.municipio_prestacao" required maxlength="7" pattern="[0-9]{7}" class="block w-full rounded-md border-gray-300"></label>
                            @if($cfg?->regime_tributario==='simples_nacional')<label class="text-sm">Regime de apuração do SN (código oficial)<select wire:model="fiscal.regApTribSN" required class="block w-full rounded-md border-gray-300"><option value="">Selecione conforme regra fiscal vigente</option><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></label>@endif
                            @foreach(['pTotTribFed'=>'Percentual federal','pTotTribEst'=>'Percentual estadual','pTotTribMun'=>'Percentual municipal'] as $campo=>$label)
                                <label class="text-sm">{{ $label }} (%)<input wire:model="fiscal.percentuais_tributos.{{ $campo }}" placeholder="0.00" required pattern="[0-9]{1,3}\.[0-9]{2}" class="block w-full rounded-md border-gray-300"></label>
                            @endforeach
                        </div>
                        <label class="block text-sm"><input type="checkbox" wire:model="fiscal.escopo_confirmado" required> Confirmei que este caso de teste se enquadra no escopo limitado acima.</label>
                        <label class="block text-sm"><input type="checkbox" wire:model="fiscal.ibscbs_nao_exigido" required> Confirmei, pelas regras fiscais vigentes, que o grupo IBS/CBS não é exigido para este caso de teste.</label>
                        <button type="submit" wire:loading.attr="disabled" @disabled(count($impedimentosPerfil)>0) class="bg-blue-600 text-white px-4 py-2 rounded-md disabled:opacity-50">Gerar e validar DPS local</button>
                    </form>
                @endif
                <h2 class="font-bold mt-6">DPS locais (histórico da competência)</h2>
                @forelse($historico as $h)
                    <div wire:key="hom-dps-{{ $h->id }}" class="flex flex-wrap justify-between gap-2 p-3 border-b text-sm"><p>Nota #{{ $h->nota_id }} · versão {{ $h->versao_nota }} · DPS {{ $h->numero }} · {{ $h->created_at->format('d/m/Y H:i') }}</p><button type="button" wire:click="abrirDps({{ $h->id }})" class="text-blue-600">Revisar / histórico</button></div>
                @empty<p class="text-sm text-gray-600 mt-2">Nenhuma DPS local registrada.</p>@endforelse
                {{ $historico->links() }}
            @endif
            @if($dps)
                <section class="border rounded-md p-4 mt-6 space-y-3">
                    <h2 class="font-bold">DPS #{{ $dps->numero }} · {{ $this->rotulo($transmissao?->status ?? 'preparada_local') }}</h2>
                    <p class="break-all text-sm">ID: {{ $dps->id_dps }}</p>
                    <p class="break-all text-sm">SHA-256: {{ $dps->xml_hash }}</p>
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">@foreach($resumo as $label=>$valor)<div><dt class="text-gray-600">{{ $label }}</dt><dd class="font-medium break-words">{{ $valor !== '' ? $valor : '—' }}</dd></div>@endforeach</dl>
                    <a href="{{ route('nfse.homologacao.dps', ['empresa'=>$empresa->id,'dps'=>$dps->id]) }}" class="text-blue-600">Baixar XML integral da DPS de teste para revisão</a>
                    @if(!$transmissao && $rede && $xsd)
                        <form wire:submit="aprovar" class="space-y-2">
                            <label class="block text-sm"><input type="checkbox" wire:model="revisado" required> Revisei o XML integral e confirmei os dados e poderes do certificado para o teste.</label>
                            <button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-md">Aprovar para homologação</button>
                        </form>
                    @elseif($transmissao?->status==='aprovada_teste' && !$transmissao->fila_homologacao_id && $rede && $xsd)
                        <form wire:submit="enviar" class="space-y-2">
                            <label class="block text-sm"><input type="checkbox" wire:model="confirmouEnvio" required> Autorizo uma tentativa de transmissão externa em produção restrita, sem valor fiscal.</label>
                            <button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-md">Transmitir uma vez à SEFIN de testes</button>
                        </form>
                    @elseif($transmissao && in_array($transmissao->status,['envio_iniciado','incerta','rejeitada_teste'],true) && $rede)
                        <p class="text-sm text-amber-900">Não reenvie a DPS. Consulte o resultado após o intervalo mínimo de 90 segundos.</p>
                        <button type="button" wire:click="reconciliar" wire:loading.attr="disabled" class="bg-gray-100 px-4 py-2 rounded-md">Consultar resultado (sem novo envio)</button>
                    @endif
                    @if($transmissao?->fila_homologacao_id)
                        <p class="text-sm">Reservada à fila #{{ $transmissao->fila_homologacao_id }} · {{ $transmissao->fila_status }}. <a href="{{ route('nfse.fila-homologacao') }}" class="text-blue-600">Acompanhar fila</a></p>
                    @endif
                    @if($transmissao?->status==='confirmada_teste')
                        <p class="text-sm break-all">Chave de teste: {{ $transmissao->chave_acesso }}</p>
                        <div class="flex flex-wrap gap-4">
                            <a href="{{ route('nfse.homologacao.retorno', ['empresa'=>$empresa->id,'transmissao'=>$transmissao->id,'formato'=>'xml']) }}" class="text-blue-600">Baixar XML de homologação</a>
                            <a href="{{ route('nfse.homologacao.retorno', ['empresa'=>$empresa->id,'transmissao'=>$transmissao->id,'formato'=>'pdf']) }}" class="text-blue-600">Baixar DANFSe sem valor fiscal</a>
                        </div>
                    @endif
                    @if($transmissao)
                        <h3 class="font-bold">Histórico da transmissão</h3>
                        @forelse($eventos as $evento)<p class="text-sm">{{ $evento->created_at->format('d/m/Y H:i:s') }} · operador #{{ $evento->usuario_id }} · {{ $evento->acao }} · {{ $evento->resultado }} @if($evento->http) · HTTP {{ $evento->http }} @endif @if($evento->codigos) · {{ implode(', ', $evento->codigos) }} @endif</p>@empty<p class="text-sm">Sem eventos.</p>@endforelse
                    @endif
                </section>
            @endif
        @endif
        <p wire:loading class="text-sm text-gray-600 mt-3" role="status">Processando. Não repita a operação.</p>
    </div>
</div>
