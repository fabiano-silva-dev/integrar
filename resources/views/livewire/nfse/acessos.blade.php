<div class="max-w-5xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h1 class="text-2xl font-bold">Acesso para emissão de NFS-e</h1>
        <p class="text-gray-600 mt-2">Configure e teste o acesso do prestador ao Portal Nacional da NFS-e.</p>
        @if(session('nfse_acesso_mensagem'))<p role="status" class="mt-3">{{ session('nfse_acesso_mensagem') }}</p>@endif
        @if($errors->any())<div role="alert" class="mt-3 text-red-700">@foreach($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach</div>@endif
        @if(!$empresa)
            <p class="mt-4">Selecione a empresa prestadora no cabeçalho.</p>
        @else
            <p class="mt-4 bg-gray-50 rounded-md p-3"><strong>{{ $empresa->razao_social ?: $empresa->nome }}</strong> · {{ $empresa->cnpj }}</p>
            @if($status)<p class="mt-3">Configuração registrada: {{ $status->status_validacao === 'portal_confirmado' ? 'Acesso ao portal confirmado no último teste' : 'Aguardando validação' }}</p>@endif
            <form x-data="{}" x-on:submit.prevent="const u=$refs.login?.value || ''; const p=$refs.senha?.value || ''; if($refs.login) $refs.login.value=''; if($refs.senha) $refs.senha.value=''; $wire.salvar(u,p)" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4" autocomplete="off">
                <label class="block text-sm">Modalidade de acesso<select wire:model.live="modo" class="w-full rounded-md border-gray-300"><option value="certificado_proprio">Certificado A1 da empresa</option><option value="certificado_representante">Certificado A1 do contador/escritório (representação)</option><option value="senha_propria">Usuário e senha do prestador</option><option value="senha_representante">Usuário e senha do representante</option></select></label>
                <label class="block text-sm">Canal de acesso<select wire:model="canal" class="w-full rounded-md border-gray-300">@if(str_starts_with($modo,'certificado_'))<option value="api">API nacional</option>@endif<option value="portal">Emissor Web</option></select></label>
                @if(str_starts_with($modo,'certificado_'))
                    <label class="block text-sm md:col-span-2">Certificado já cadastrado<select wire:model="certificadoId" class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($certificados as $certificado)<option value="{{ $certificado->id }}">{{ $certificado->nome }} — válido até {{ $certificado->valido_ate?->format('d/m/Y') }}</option>@endforeach</select></label>
                    <p class="text-sm text-gray-600 md:col-span-2">A1 próprio: certificado vinculado ao prestador. Representação: certificado cadastrado no escritório, sem vínculo a cliente. A mesma referência pode ser escolhida em várias empresas.</p>
                @else
                    <label class="block text-sm">CPF/CNPJ do login<input x-ref="login" type="text" inputmode="numeric" maxlength="32" autocomplete="off" class="w-full rounded-md border-gray-300"></label>
                    <label class="block text-sm">Senha do Emissor Web<input x-ref="senha" type="password" maxlength="256" autocomplete="new-password" class="w-full rounded-md border-gray-300"></label>
                    <p class="text-sm text-gray-600 md:col-span-2">Credenciais criptografadas. Se já cadastradas nesta modalidade, deixe ambos os campos vazios para manter; para trocar, informe os dois. Não utilize a senha da conta GOV.BR.</p>
                @endif
                @if(str_ends_with($modo,'_representante'))
                    <p class="md:col-span-2">Representado: <strong>{{ $empresa->cnpj }}</strong>. O documento é obtido do cadastro do prestador, não digitado novamente.</p>
                    <label class="block text-sm">Vencimento informado da procuração (opcional)<input type="date" wire:model="procuracaoAte" class="w-full rounded-md border-gray-300"></label>
                    <p class="text-sm text-gray-600">Data declarada pelo escritório; os poderes deverão ser confirmados no serviço oficial antes da transmissão.</p>
                @endif
                <div class="md:col-span-2"><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Salvar configuração</button></div>
            </form>
            <div class="mt-6 border-t pt-4" @if($validacoesPortal->contains(fn($v) => in_array($v->status, ['pendente', 'iniciada']))) wire:poll.5s @endif>
                <h2 class="text-lg font-bold">Teste de acesso ao Emissor Web</h2>
                <p class="text-sm text-gray-600">Usa a configuração salva para entrar no portal e conferir o CPF/CNPJ ativo. Na representação, também seleciona a empresa por procuração. O teste não emite notas nem comprova poderes para usar a API.</p>
                <button type="button" wire:click="testarPortal" wire:loading.attr="disabled" class="mt-3 bg-blue-600 text-white px-4 py-2 rounded-lg">Testar acesso ao Emissor Web</button>
                <h3 class="font-bold mt-4">Histórico de testes de acesso</h3>
                <p class="text-sm text-gray-600">Cada resultado corresponde às credenciais e permissões conferidas naquele momento.</p>
                @forelse($validacoesPortal as $v)
                    <p role="status" class="text-sm mt-2">{{ $v->created_at->format('d/m/Y H:i') }} · {{ $v->mensagem() }}</p>
                @empty<p class="text-sm mt-2">Nenhum teste de acesso registrado.</p>@endforelse
            </div>
            <div class="mt-6 border-t pt-4">
                <h2 class="text-lg font-bold">Diagnóstico de acesso à API (somente consulta)</h2>
                <p class="text-sm text-gray-600">Usa a configuração já salva. Informe uma chave de NFS-e conhecida, emitida pelo prestador selecionado, no ambiente correspondente. O teste não emite, cancela ou importa documentos; não comprova poderes de emissão.</p>
                @if(config('automacao_fiscal.nfse_diagnostico_api_enabled', false))
                    <form x-data="{}" x-on:submit.prevent="const a=$refs.ambiente.value; const k=$refs.chave.value; if(confirm('Consultar uma NFS-e existente no ambiente selecionado, sem emitir documentos?')) { $refs.chave.value=''; $wire.diagnosticar(a,k) }" class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <label class="text-sm">Ambiente<select x-ref="ambiente" class="w-full rounded-md border-gray-300"><option value="restrita">Produção restrita (testes)</option><option value="producao">Produção (somente leitura)</option></select></label>
                        <label class="text-sm">Chave de acesso<input x-ref="chave" required pattern="[0-9]{50}" maxlength="50" inputmode="numeric" class="w-full rounded-md border-gray-300"></label>
                        <div><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Consultar acesso</button></div>
                    </form>
                @else
                    <p class="mt-3 text-sm">Diagnóstico externo desabilitado. Solicite a habilitação no ambiente de homologação.</p>
                @endif
                <h3 class="font-bold mt-4">Histórico de consultas</h3>
                <p class="text-sm text-gray-600">Resultados históricos; podem corresponder a uma configuração anterior. Não alteram a situação de autorização fiscal.</p>
                @forelse($diagnosticos as $d)
                    <p class="text-sm mt-2">{{ $d->created_at->format('d/m/Y H:i') }} · {{ $d->ambiente }} · certificado #{{ $d->certificado_id }} · {{ match($d->status) {'consulta_confirmada'=>'Consulta confirmada','identidade_divergente'=>'Identidade/ambiente divergente','config_alterada'=>'Configuração alterada','iniciada'=>'Iniciada (sem conclusão registrada)',default=>'Consulta não confirmada'} }}</p>
                @empty<p class="text-sm mt-2">Nenhuma consulta registrada.</p>@endforelse
            </div>
        @endif
    </div>
</div>
