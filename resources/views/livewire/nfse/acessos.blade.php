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
            <div class="mt-4 border rounded-md p-3 text-sm space-y-2">
                @if($status)
                    <p><strong>Configuração salva:</strong> {{ match($status->modo) {'senha_propria'=>'Usuário e senha do prestador','senha_representante'=>'Usuário e senha do representante','certificado_proprio'=>'Certificado da empresa','certificado_representante'=>'Certificado do escritório',default=>'Modalidade desconhecida'} }} · {{ $status->canal === 'portal' ? 'Emissor Web' : 'API nacional' }}</p>
                    <p><strong>Validação:</strong> {{ $status->status_validacao === 'portal_confirmado' ? 'Acesso ao portal confirmado' : 'Acesso ainda não confirmado' }}.</p>
                    @if($alterado)<p class="text-amber-800 font-medium">Há alterações na configuração que ainda não foram salvas.</p>@endif
                @else
                    <p class="text-amber-800 font-medium">Nenhuma configuração cadastrada para este prestador.</p>
                @endif
            </div>
            <form wire:key="acesso-form-{{ $modo }}-{{ (int)$credenciaisSalvas }}" x-data="{ trocar: false }" x-on:submit.prevent="const u=$refs.login?.disabled ? '' : ($refs.login?.value || ''); const p=$refs.senha?.disabled ? '' : ($refs.senha?.value || ''); if($refs.login) $refs.login.value=''; if($refs.senha) $refs.senha.value=''; $wire.salvar(u,p).then(ok => { if(ok === true) trocar=false })" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4" autocomplete="off">
                <label class="block text-sm">Modalidade de acesso<select wire:model.live="modo" class="w-full rounded-md border-gray-300"><option value="certificado_proprio">Certificado A1 da empresa</option><option value="certificado_representante">Certificado A1 do contador/escritório (representação)</option><option value="senha_propria">Usuário e senha do prestador</option><option value="senha_representante">Usuário e senha do representante</option></select></label>
                <label class="block text-sm">Canal de acesso<select wire:model.live="canal" class="w-full rounded-md border-gray-300">@if(str_starts_with($modo,'certificado_'))<option value="api">API nacional</option>@endif<option value="portal">Emissor Web</option></select></label>
                @if(str_starts_with($modo,'certificado_'))
                    <label class="block text-sm md:col-span-2">Certificado já cadastrado<select wire:model.live="certificadoId" class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($certificados as $certificado)<option value="{{ $certificado->id }}">{{ $certificado->nome }} — válido até {{ $certificado->valido_ate?->format('d/m/Y') }}</option>@endforeach</select></label>
                    @if($certificadoDisponivel)
                        <p class="text-sm text-green-700 md:col-span-2 font-medium">Certificado cadastrado e disponível.</p>
                    @else
                        <p class="text-sm text-amber-800 md:col-span-2">Nenhum certificado vigente cadastrado nesta modalidade ou o certificado salvo não está disponível.</p>
                    @endif
                    <p class="text-sm text-gray-600 md:col-span-2">A1 próprio: certificado vinculado ao prestador. Representação: certificado cadastrado no escritório, sem vínculo a cliente. A mesma referência pode ser escolhida em várias empresas.</p>
                @else
                    @if($credenciaisSalvas)
                        <div class="md:col-span-2 rounded-md border border-green-200 bg-green-50 p-3 text-sm">
                            <p class="font-semibold text-green-800">Usuário e senha cadastrados</p>
                            <p class="mt-1">CPF/CNPJ do login: <strong>{{ $loginMascarado }}</strong></p>
                            <p>Senha: <strong>Cadastrada e protegida</strong></p>
                            <button type="button" x-show="!trocar" x-on:click="trocar=true" class="mt-3 text-blue-700 font-medium">Alterar usuário e senha</button>
                            <button type="button" x-show="trocar" x-cloak x-on:click="trocar=false; $refs.login.value=''; $refs.senha.value=''" class="mt-3 text-blue-700 font-medium">Cancelar alteração</button>
                        </div>
                    @else
                        <p class="md:col-span-2 text-sm text-amber-800 font-medium">Usuário e senha não cadastrados ou indisponíveis nesta modalidade. Informe os dois campos.</p>
                    @endif
                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4" @if($credenciaisSalvas) x-show="trocar" x-cloak @endif>
                        <label class="block text-sm">CPF/CNPJ do login<input x-ref="login" type="text" inputmode="numeric" maxlength="32" autocomplete="off" @if($credenciaisSalvas) x-bind:disabled="!trocar" x-bind:required="trocar" @else required @endif class="w-full rounded-md border-gray-300"></label>
                        <label class="block text-sm">Senha do Emissor Web<input x-ref="senha" type="password" maxlength="256" autocomplete="new-password" @if($credenciaisSalvas) x-bind:disabled="!trocar" x-bind:required="trocar" @else required @endif class="w-full rounded-md border-gray-300"></label>
                    </div>
                    <p class="text-sm text-gray-600 md:col-span-2">A senha é armazenada criptografada e nunca é exibida. Ao alterar, informe o novo login e a senha. Não utilize a senha da conta GOV.BR.</p>
                @endif
                @if(str_ends_with($modo,'_representante'))
                    <p class="md:col-span-2">Representado: <strong>{{ $empresa->cnpj }}</strong>. O documento é obtido do cadastro do prestador, não digitado novamente.</p>
                    <label class="block text-sm">Vencimento informado da procuração (opcional)<input type="date" wire:model.live="procuracaoAte" class="w-full rounded-md border-gray-300"></label>
                    <p class="text-sm text-gray-600">Data declarada pelo escritório; os poderes deverão ser confirmados no serviço oficial antes da transmissão.</p>
                @endif
                <div class="md:col-span-2"><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Salvar configuração</button></div>
            </form>
            <div class="mt-6 border-t pt-4" @if($validacoesPortal->contains(fn($v) => in_array($v->status, ['pendente', 'iniciada']))) wire:poll.5s @endif>
                <h2 class="text-lg font-bold">Teste de acesso ao Emissor Web</h2>
                <p class="text-sm text-gray-600">Usa a configuração salva para entrar no portal e conferir o CPF/CNPJ ativo. Na representação, também seleciona a empresa por procuração. O teste não emite notas nem comprova poderes para usar a API.</p>
                <button type="button" wire:click="testarPortal" wire:loading.attr="disabled" @disabled(!$acessoPronto || $status?->canal !== 'portal' || $validacoesPortal->contains(fn($v) => in_array($v->status, ['pendente','iniciada']) && $v->created_at->gt(now()->subMinutes(5)))) class="mt-3 bg-blue-600 text-white px-4 py-2 rounded-lg disabled:opacity-50">Testar acesso ao Emissor Web</button>
                @if(!$acessoPronto || $status?->canal !== 'portal')<p class="mt-2 text-sm text-gray-600">Salve uma configuração válida do Emissor Web antes de testar.</p>@endif
                <h3 class="font-bold mt-4">Histórico de testes de acesso</h3>
                <p class="text-sm text-gray-600">Cada resultado corresponde às credenciais e permissões conferidas naquele momento.</p>
                @forelse($validacoesPortal as $v)
                    <div wire:key="teste-portal-{{ $v->id }}" class="mt-3 border rounded-md p-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <strong>Teste #{{ $v->id }} · {{ $v->created_at->format('d/m/Y H:i') }}</strong>
                            <button type="button" wire:click="baixarLog('portal',{{ $v->id }})" wire:loading.attr="disabled" class="text-blue-700 font-medium">Baixar log (.json)</button>
                        </div>
                        <p role="status" class="mt-1 {{ $v->status === 'confirmada' ? 'text-green-700' : (in_array($v->status,['pendente','iniciada']) ? 'text-gray-700' : 'text-red-700') }}">{{ $v->mensagem() }}</p>
                        @if($v->concluida_em)<p class="text-gray-600">Concluído: {{ $v->concluida_em->format('d/m/Y H:i:s') }}</p>@endif
                        @if(in_array($v->status,['pendente','iniciada']) && $v->created_at->lte(now()->subMinutes(5)))<p class="text-amber-800">Sem conclusão há mais de 5 minutos. Verifique a fila de processamento e solicite novo teste.</p>@endif
                        @include('livewire.nfse.partials.log-teste-acesso',['log'=>$v->diagnostico_tecnico,'codigo'=>$v->codigo])
                    </div>
                @empty<p class="text-sm mt-2">Nenhum teste de acesso registrado.</p>@endforelse
            </div>
            <div class="mt-6 border-t pt-4">
                <h2 class="text-lg font-bold">Diagnóstico de acesso à API (somente consulta)</h2>
                <p class="text-sm text-gray-600">Usa a configuração já salva. Informe uma chave de NFS-e conhecida, emitida pelo prestador selecionado, no ambiente correspondente. O teste não emite, cancela ou importa documentos; não comprova poderes de emissão.</p>
                @if(config('automacao_fiscal.nfse_diagnostico_api_enabled', false) && $acessoPronto && $status?->canal === 'api')
                    <form x-data="{}" x-on:submit.prevent="const a=$refs.ambiente.value; const k=$refs.chave.value; if(confirm('Consultar uma NFS-e existente no ambiente selecionado, sem emitir documentos?')) { $refs.chave.value=''; $wire.diagnosticar(a,k) }" class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <label class="text-sm">Ambiente<select x-ref="ambiente" class="w-full rounded-md border-gray-300"><option value="restrita">Produção restrita (testes)</option><option value="producao">Produção (somente leitura)</option></select></label>
                        <label class="text-sm">Chave de acesso<input x-ref="chave" required pattern="[0-9]{50}" maxlength="50" inputmode="numeric" class="w-full rounded-md border-gray-300"></label>
                        <div><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Consultar acesso</button></div>
                    </form>
                @elseif(!config('automacao_fiscal.nfse_diagnostico_api_enabled', false))
                    <p class="mt-3 text-sm">Diagnóstico externo desabilitado. Solicite a habilitação no ambiente de homologação.</p>
                @else
                    <p class="mt-3 text-sm">Salve uma configuração válida de acesso à API antes de testar.</p>
                @endif
                <h3 class="font-bold mt-4">Histórico de consultas</h3>
                <p class="text-sm text-gray-600">Resultados históricos; podem corresponder a uma configuração anterior. Não alteram a situação de autorização fiscal.</p>
                @forelse($diagnosticos as $d)
                    <div wire:key="teste-api-{{ $d->id }}" class="mt-3 border rounded-md p-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <strong>Consulta #{{ $d->id }} · {{ $d->created_at->format('d/m/Y H:i') }} · {{ $d->ambiente }}</strong>
                            <button type="button" wire:click="baixarLog('api',{{ $d->id }})" wire:loading.attr="disabled" class="text-blue-700 font-medium">Baixar log (.json)</button>
                        </div>
                        <p class="mt-1 {{ $d->status === 'consulta_confirmada' ? 'text-green-700' : 'text-red-700' }}">{{ $d->mensagem() }}</p>
                        <p class="text-gray-600">Certificado #{{ $d->certificado_id }}</p>
                        @include('livewire.nfse.partials.log-teste-acesso',['log'=>$d->diagnostico_tecnico,'codigo'=>null])
                    </div>
                @empty<p class="text-sm mt-2">Nenhuma consulta registrada.</p>@endforelse
            </div>
        @endif
    </div>
</div>
