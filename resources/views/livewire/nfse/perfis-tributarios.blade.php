<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex flex-wrap justify-between gap-3">
            <h1 class="text-2xl font-bold">Perfis tributários de NFS-e</h1>
            <a href="{{ route('nfse.homologacao') }}" class="text-blue-600">Homologação</a>
        </div>
        <p class="mt-2 text-gray-600">Cadastre a regra geral da empresa e, quando necessário, uma regra completa por serviço. A versão vigente na competência da nota é utilizada na preparação da DPS, independentemente de sua origem.</p>
        <p class="mt-3 bg-amber-50 border border-amber-300 rounded-md p-3 text-sm text-amber-900">Cadastro declaratório. Não há cálculo automático, validação oficial de CST/cClassTrib ou emissão de produção. Casos fora do gerador simplificado permanecem bloqueados.</p>
        @if(session('nfse_perfil_mensagem'))<p role="status" class="mt-3">{{ session('nfse_perfil_mensagem') }}</p>@endif
        @if($errors->any())<div role="alert" class="mt-3 border border-red-300 rounded-md p-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
        @if(!$empresa)
            <p class="mt-4">Selecione o prestador no cabeçalho.</p>
        @else
            <p class="mt-4"><strong>{{ $empresa->razao_social ?: $empresa->nome }}</strong> · {{ $empresa->cnpj }}</p>
            <form wire:submit="salvar" class="mt-4 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="text-sm">Aplicação<select wire:model="servicoId" class="block w-full rounded-md border-gray-300"><option value="">Padrão da empresa</option>@foreach($servicos->where('ativo',true) as $s)<option value="{{ $s->id }}">{{ $s->codigo }} — {{ $s->nome }}</option>@endforeach</select></label>
                    <label class="text-sm">Vigência a partir de<input type="month" wire:model.live="vigencia" required class="block w-full rounded-md border-gray-300"></label>
                    <label class="text-sm">Regime tributário<select wire:model.live="form.regime" required class="block w-full rounded-md border-gray-300"><option value="">Selecione</option><option value="mei">MEI</option><option value="simples_nacional">Simples Nacional</option><option value="normal">Regime normal</option></select></label>
                    @if($form['regime']==='simples_nacional')<label class="text-sm">Regime de apuração SN (código oficial)<select wire:model="form.regApTribSN" required class="block w-full rounded-md border-gray-300"><option value="">Selecione conforme documentação</option><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></label>@endif
                    <label class="text-sm">Município da prestação (IBGE)<input wire:model="form.municipio_prestacao" required maxlength="7" pattern="[0-9]{7}" class="block w-full rounded-md border-gray-300"></label>
                </div>
                <h2 class="font-bold border-t pt-4">ISS e retenções</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="text-sm">Tratamento do ISS<select wire:model="form.iss" required class="block w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach(['tributado'=>'Tributado','imune'=>'Imune','isento'=>'Isento','nao_incidente'=>'Não incidente','exportacao'=>'Exportação','suspenso'=>'Suspenso','outro'=>'Outro / revisar'] as $v=>$l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></label>
                    <label class="text-sm">Retenção de ISS<select wire:model="form.iss_retido" required class="block w-full rounded-md border-gray-300"><option value="">Selecione</option><option value="nao">Não retido</option><option value="tomador">Tomador</option><option value="intermediario">Intermediário</option><option value="indefinido">A definir</option></select></label>
                    <label class="text-sm">Alíquota ISS (%) — se aplicável<input wire:model="form.aliquota_iss" placeholder="Ex.: 2.00" pattern="[0-9]{1,3}\.[0-9]{2}" class="block w-full rounded-md border-gray-300"></label>
                    @foreach(['regime_especial'=>'Regime especial','deducoes_beneficios'=>'Deduções ou benefícios'] as $campo=>$label)
                        <label class="text-sm">{{ $label }}<select wire:model="form.{{ $campo }}" required class="block w-full rounded-md border-gray-300"><option value="">Selecione</option><option value="nao">Não</option><option value="sim">Sim</option><option value="indefinido">A definir</option></select></label>
                    @endforeach
                    @foreach(\App\Services\Nfse\PerfisTributariosNfse::RETENCOES as $campo)
                        <label class="text-sm">{{ strtoupper($campo) }} retido<select wire:model="form.retencoes.{{ $campo }}" required class="block w-full rounded-md border-gray-300"><option value="">Selecione</option><option value="nao">Não</option><option value="sim">Sim</option><option value="indefinido">A definir</option></select></label>
                    @endforeach
                </div>
                <h2 class="font-bold border-t pt-4">Reforma Tributária — IBS/CBS</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="text-sm">Preenchimento do grupo IBS/CBS<select wire:model.live="form.ibscbs" required class="block w-full rounded-md border-gray-300"><option value="">Selecione</option><option value="dispensado">Dispensado conforme regra aplicável</option><option value="exigido">Exigido</option><option value="indefinido">A definir</option></select></label>
                    <label class="text-sm">CST<input wire:model.live.debounce.400ms="form.cst" maxlength="3" pattern="[0-9]{3}" @required($form['ibscbs']==='exigido') class="block w-full rounded-md border-gray-300"></label>
                    <label class="text-sm">cClassTrib<input wire:model.live.debounce.400ms="form.cclass_trib" maxlength="6" pattern="[0-9]{6}" @required($form['ibscbs']==='exigido') class="block w-full rounded-md border-gray-300"></label>
                    <label class="text-sm">Indicador de operação (cIndOp)<input wire:model="form.ind_op" maxlength="6" pattern="[0-9]{6}" class="block w-full rounded-md border-gray-300"></label>
                </div>
                <div class="border rounded-md p-4 space-y-3">
                    <div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">Consultar CST e cClassTrib</h3><a href="https://dfe-portal.svrs.rs.gov.br/DFE/ClassificacaoTributaria" target="_blank" rel="noopener noreferrer" class="text-blue-600 text-sm">Tabela no portal SVRS</a></div>
                    @if($versaoCatalogo)
                        <p class="text-sm text-gray-600">Versão {{ $versaoCatalogo->versao }} · {{ $versaoCatalogo->fonte }} · publicação informada {{ \Carbon\CarbonImmutable::parse($versaoCatalogo->publicada_em)->format('d/m/Y') }} · {{ $versaoCatalogo->quantidade }} registros. Origem conferida pelo administrador; não autenticada automaticamente.</p>
                        <label class="block text-sm">Buscar código ou descrição<input type="search" wire:model.live.debounce.350ms="buscaClassificacao" maxlength="100" placeholder="CST, cClassTrib ou descrição" class="block w-full rounded-md border-gray-300"></label>
                        <p class="text-sm text-gray-600">Até 25 resultados permitidos para NFS-e durante todo o mês selecionado. Refine a busca para localizar outros códigos.</p>
                        @forelse($classificacoes as $c)
                            <div wire:key="cat-class-{{ $c->id }}" class="flex flex-wrap items-start justify-between gap-2 border-b pb-2 text-sm">
                                <div class="flex-1"><p class="font-semibold">{{ $c->cst }} / {{ $c->cclass_trib }} — {{ $c->descricao_cst }}</p><p>{{ $c->descricao }}</p><p class="text-gray-600">Vigência: {{ $c->inicio }} a {{ $c->fim ?? 'sem fim informado' }} · ind_gIBSCBS: {{ $c->ind_gibscbs === null ? 'não informado' : $c->ind_gibscbs }}</p></div>
                                <button type="button" wire:click="selecionarClassificacao({{ $c->id }})" class="text-blue-600">Selecionar</button>
                            </div>
                        @empty<p class="text-sm">Nenhuma classificação encontrada para a busca e competência.</p>@endforelse
                        @if($classificacaoSelecionada)
                            <p class="text-sm"><strong>Classificação informada:</strong> {{ $classificacaoSelecionada->descricao }}. O código consta no catálogo carregado; confirme a aplicabilidade legal.</p>
                        @elseif(filled($form['cclass_trib']) || filled($form['cst']))
                            <p class="text-sm text-red-700">O par informado não consta como permitido e vigente para todo o mês no catálogo carregado.</p>
                        @endif
                    @else
                        <p class="text-sm text-amber-900">Catálogo ainda não carregado. O cadastro manual continua declaratório e não será conferido contra a tabela. Solicite a importação ao administrador do servidor.</p>
                    @endif
                    @if(filled($form['cst']) || filled($form['cclass_trib']))<button type="button" wire:click="limparClassificacao" class="text-blue-600 text-sm">Limpar CST/cClassTrib</button>@endif
                    <p class="text-sm text-gray-600">A seleção não determina dispensa, incidência ou enquadramento. O indicador do grupo gIBSCBS não substitui a análise do preenchimento dos demais campos RTC.</p>
                </div>
                <h2 class="font-bold border-t pt-4">Percentuais aproximados de tributos (não são alíquotas de retenção)</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach(['pTotTribFed'=>'Federal','pTotTribEst'=>'Estadual','pTotTribMun'=>'Municipal'] as $campo=>$label)
                        <label class="text-sm">{{ $label }} (%)<input wire:model="form.percentuais_tributos.{{ $campo }}" required placeholder="0.00" pattern="[0-9]{1,3}\.[0-9]{2}" class="block w-full rounded-md border-gray-300"></label>
                    @endforeach
                </div>
                <label class="block text-sm">Fundamento e orientações do responsável fiscal<textarea wire:model="form.fundamento" required rows="3" maxlength="2000" class="block w-full rounded-md border-gray-300" placeholder="Regra, referência normativa, aplicabilidade e ressalvas."></textarea></label>
                <label class="block text-sm"><input type="checkbox" wire:model="confirmado" required> Confirmei os dados e a vigência. Sei que o cadastro não constitui validação fiscal e que uma nova versão não sobrescreve o histórico.</label>
                <button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-md">Registrar versão tributária</button>
            </form>
            <h2 class="font-bold mt-7 border-t pt-4">Histórico de versões</h2>
            <p class="text-sm text-gray-600">A última versão do serviço na competência prevalece sobre o padrão da empresa. Copie uma versão para registrar outra vigência.</p>
            @forelse($perfis as $p)
                @php($impedimentos = app(\App\Services\Nfse\PerfisTributariosNfse::class)->impedimentos($p,$cfg))
                <div wire:key="perfil-{{ $p->id }}" class="border-b p-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-2"><strong>#{{ $p->id }} · {{ $p->servico_id ? ($servicos->firstWhere('id',$p->servico_id)?->nome ?? 'Serviço #'.$p->servico_id) : 'Padrão da empresa' }} · {{ $p->vigencia->format('m/Y') }}</strong><button type="button" wire:click="copiar({{ $p->id }})" class="text-blue-600">Copiar para nova vigência</button></div>
                    <p>{{ $p->dados['regime'] }} · ISS {{ $p->dados['iss'] }} · IBS/CBS {{ $p->dados['ibscbs'] }} @if($p->dados['cst']) · CST {{ $p->dados['cst'] }} / {{ $p->dados['cclass_trib'] }} @endif</p>
                    @if($ref = $referencias->get($p->classificacao_catalogo_id))
                        <p class="text-gray-600">Referência registrada: {{ $ref->versao }} ({{ $ref->fonte }}, {{ $ref->publicada_em }}) — {{ $ref->descricao }}</p>
                    @elseif(filled($p->dados['cclass_trib'] ?? null))
                        <p class="text-amber-900">Classificação declarada sem vínculo a versão de catálogo.</p>
                    @endif
                    <p class="text-gray-600 whitespace-pre-wrap">{{ $p->dados['fundamento'] }}</p>
                    @if($impedimentos)<p class="text-red-700">Impedimentos atuais: {{ implode(' ', $impedimentos) }}</p>@else<p class="text-gray-600">Compatível com o recorte local, sujeito à conferência e homologação.</p>@endif
                </div>
            @empty<p class="mt-3 text-gray-600">Nenhum perfil cadastrado.</p>@endforelse
            {{ $perfis->links() }}
        @endif
    </div>
</div>
