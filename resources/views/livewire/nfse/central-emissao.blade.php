<div class="max-w-7xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex flex-wrap justify-between items-center gap-2"><h1 class="text-2xl font-bold text-gray-800">Emissão de NFS-e</h1><a href="{{ route('nfse.painel') }}" class="text-blue-600">Visão geral do escritório</a></div>
        <p class="text-gray-600 mt-1">Preparação fiscal — transmissão indisponível até homologação.</p>
        @if (!$empresa)
            <p class="mt-4 border border-gray-200 rounded-md bg-gray-50 p-3">Selecione o escritório e a empresa prestadora no cabeçalho.</p>
        @else
            <div class="mt-4 border border-gray-200 rounded-md bg-gray-50 p-3">
                <strong>{{ $empresa->razao_social ?: $empresa->nome }}</strong> · {{ $empresa->cnpj }}
            </div>
            <div class="flex flex-wrap gap-2 mt-4 border-b border-gray-200 pb-3">
                @foreach (['notas' => 'Central de emissão', 'tomadores' => 'Tomadores', 'servicos' => 'Serviços', 'importar' => 'Importar planilha', 'conferencia' => 'Conferência e lotes', 'recorrencias' => 'Recorrências', 'configuracao' => 'Configurações'] as $chave => $rotulo)
                    @if ($chave !== 'configuracao' || app(\App\Services\Nfse\PreparacaoNfse::class)->podeConfigurar())
                        <button type="button" wire:click="mudarAba('{{ $chave }}')" class="px-4 py-2 rounded-lg {{ $aba === $chave ? 'bg-blue-600 text-white' : 'bg-gray-50 text-gray-700' }}">{{ $rotulo }}</button>
                    @endif
                @endforeach
            </div>
            @if (session('nfse_mensagem'))
                <p role="status" class="mt-4 text-sm text-gray-800">{{ session('nfse_mensagem') }}</p>
            @endif
            @if ($errors->any())
                <div role="alert" class="mt-4 border border-red-300 rounded-md bg-red-50 p-3 text-red-700">
                    @foreach ($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach
                </div>
            @endif
            @if ($aba === 'notas')
                <h2 class="text-xl font-bold text-gray-800 mt-6">Nova nota avulsa</h2>
                @unless ($configuracao?->habilitado_preparo)
                    <p class="mt-3 text-gray-600">Solicite ao administrador ou gerente a habilitação do preparo nas configurações.</p>
                @else
                    <form wire:submit="salvarNota" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="block text-sm">Tomador
                            <select wire:model="nota.tomador_id" required class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($tomadores as $tomador)<option value="{{ $tomador->id }}">{{ $tomador->nome }} — {{ $tomador->documento }}</option>@endforeach</select>
                        </label>
                        <label class="block text-sm">Serviço
                            <select wire:model.live="nota.servico_id" required class="w-full rounded-md border-gray-300"><option value="">Selecione</option>@foreach($servicos as $servico)<option value="{{ $servico->id }}">{{ $servico->nome }}</option>@endforeach</select>
                        </label>
                        <label class="block text-sm">Competência fiscal<input type="date" wire:model="nota.competencia" required class="w-full rounded-md border-gray-300"></label>
                        <label class="block text-sm">Valor (R$)<input type="number" min="0.01" step="0.01" wire:model="nota.valor" required class="w-full rounded-md border-gray-300"></label>
                        <label class="block text-sm md:col-span-2">Descrição<textarea wire:model="nota.descricao" rows="3" required class="w-full rounded-md border-gray-300"></textarea></label>
                        <div class="flex gap-2 md:col-span-2">
                            <button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Salvar rascunho</button>
                            <button type="button" wire:click="novaNota" class="px-4 py-2 rounded-lg bg-gray-50">Limpar</button>
                        </div>
                    </form>
                @endunless
                <div class="flex flex-wrap justify-between items-center mt-6 gap-4"><h2 class="text-xl font-bold text-gray-800">Pré-conferência</h2><label class="text-sm">Competência <input type="month" wire:model.live="competencia" class="rounded-md border-gray-300"></label></div>
                <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Tomador</th><th class="text-left p-3">Serviço</th><th class="text-left p-3">Competência</th><th class="text-right p-3">Valor</th><th class="text-left p-3">Situação</th></tr></thead><tbody>
                    @forelse ($registros as $registro)
                        <tr wire:key="nota-{{ $registro->id }}"><td class="p-3">{{ data_get($registro->snapshot, 'tomador.nome') }}<p class="text-gray-600">{{ data_get($registro->snapshot, 'tomador.documento') }}</p></td><td class="p-3">{{ data_get($registro->snapshot, 'servico.nome') }}<p class="text-gray-600">{{ $registro->descricao }}</p></td><td class="p-3">{{ $registro->competencia->format('d/m/Y') }}</td><td class="p-3 text-right">R$ {{ number_format($registro->valor, 2, ',', '.') }}</td><td class="p-3">{{ match($registro->status) {'rascunho'=>'Rascunho — não transmitida','em_lote'=>'Lote #'.$registro->lote_id.' preparado','descartada'=>'Descartada',default=>$registro->status} }} @if ($registro->recorrencia_id)<p class="text-gray-600">Recorrência #{{ $registro->recorrencia_id }}</p>@endif @if ($registro->importacao_id)<p class="text-gray-600">Planilha #{{ $registro->importacao_id }} · linha {{ $registro->linha_importacao }}</p>@endif</td></tr>
                    @empty <tr><td colspan="5" class="p-3 text-gray-600">Nenhum rascunho nesta competência.</td></tr> @endforelse
                </tbody></table></div>
                {{ $registros->links() }}
            @elseif ($aba === 'conferencia')
                <livewire:nfse.conferencia :empresa-id="$empresa->id" :mes-inicial="$competencia" :situacao-inicial="$situacaoInicial" :key="'nfse-conferencia-'.$empresa->id" />
            @elseif ($aba === 'recorrencias')
                @if ($configuracao?->habilitado_preparo)
                    <livewire:nfse.recorrencias :empresa-id="$empresa->id" :mes-inicial="$competencia" :key="'nfse-recorrencias-'.$empresa->id" />
                @else
                    <p class="mt-3 text-gray-600">Habilite a preparação de NFS-e nas configurações da empresa.</p>
                @endif
            @elseif ($aba === 'importar')
                @if ($configuracao?->habilitado_preparo)
                    <livewire:nfse.importar-planilha :empresa-id="$empresa->id" :mes-inicial="$competencia" :key="'nfse-importar-'.$empresa->id" />
                @else
                    <p class="mt-3 text-gray-600">Habilite a preparação de NFS-e nas configurações da empresa.</p>
                @endif
            @elseif ($aba === 'configuracao')
                <h2 class="text-xl font-bold text-gray-800 mt-6">Configurações da prestadora</h2>
                <form wire:submit="salvarCadastro" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block text-sm md:col-span-2"><input type="checkbox" wire:model="form.habilitado_preparo"> Habilitar preparação de rascunhos (não autoriza transmissão)</label>
                    <label class="block text-sm">Regime tributário<select wire:model="form.regime_tributario" class="w-full rounded-md border-gray-300"><option value="">Não configurado</option><option value="mei">MEI</option><option value="simples_nacional">Simples Nacional</option><option value="normal">Regime normal</option></select></label>
                    <label class="block text-sm">Série DPS proposta (pendente de validação)<input type="text" inputmode="numeric" maxlength="5" wire:model="form.serie_dps" class="w-full rounded-md border-gray-300"></label>
                    <p class="text-sm text-gray-600 md:col-span-2">O certificado A1 continuará sendo gerenciado em Automação Fiscal. Os parâmetros cadastrados ainda não foram homologados para transmissão.</p>
                    <div><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Salvar configurações</button></div>
                </form>
            @else
                @php
                    $campos = $aba === 'tomadores' ? ['documento'=>'CPF/CNPJ','nome'=>'Nome / Razão social','email'=>'E-mail','telefone'=>'Telefone','codigo_externo'=>'Código externo','cep'=>'CEP','logradouro'=>'Logradouro','numero'=>'Número','complemento'=>'Complemento','bairro'=>'Bairro','codigo_municipio_ibge'=>'Município (IBGE)','uf'=>'UF','inscricao_municipal'=>'Inscrição municipal'] : ['codigo'=>'Código interno','nome'=>'Nome do serviço','descricao'=>'Descrição padrão','codigo_tributacao_nacional'=>'Código tributário nacional (6 dígitos)','codigo_tributacao_municipal'=>'Código tributário municipal','nbs'=>'NBS','valor_padrao'=>'Valor padrão (R$)'];
                    $obrigatorios = $aba === 'tomadores' ? ['documento','nome'] : ['codigo','nome','descricao','codigo_tributacao_nacional'];
                @endphp
                <h2 class="text-xl font-bold text-gray-800 mt-6">{{ $editandoId ? 'Editar' : 'Cadastrar' }} {{ $aba === 'tomadores' ? 'tomador' : 'serviço' }}</h2>
                <form wire:submit="salvarCadastro" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach ($campos as $campo => $rotulo)
                        <label wire:key="{{ $aba }}-{{ $campo }}" class="block text-sm {{ $campo === 'descricao' ? 'md:col-span-3' : '' }}">{{ $rotulo }}
                            @if ($campo === 'descricao')<textarea wire:model="form.{{ $campo }}" rows="3" required class="w-full rounded-md border-gray-300"></textarea>
                            @else<input type="{{ $campo === 'email' ? 'email' : ($campo === 'valor_padrao' ? 'number' : 'text') }}" @if($campo === 'valor_padrao') min="0" step="0.01" @endif wire:model="form.{{ $campo }}" @required(in_array($campo, $obrigatorios)) class="w-full rounded-md border-gray-300">@endif
                        </label>
                    @endforeach
                    <label class="block text-sm"><input type="checkbox" wire:model="form.ativo"> Ativo</label>
                    <div class="flex gap-2 md:col-span-3"><button type="submit" wire:loading.attr="disabled" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Salvar</button><button type="button" wire:click="novoCadastro" class="bg-gray-50 px-4 py-2 rounded-lg">Novo cadastro</button></div>
                </form>
                <div class="mt-6"><input type="search" wire:model.live.debounce.300ms="busca" placeholder="Pesquisar por nome ou código/documento" class="w-full rounded-md border-gray-300"></div>
                <div class="overflow-x-auto mt-3"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr><th class="text-left p-3">Nome</th><th class="text-left p-3">Documento / código</th><th class="text-left p-3">Situação</th><th class="text-left p-3">Ação</th></tr></thead><tbody>
                    @forelse ($registros as $registro)
                        <tr wire:key="{{ $aba }}-{{ $registro->id }}"><td class="p-3">{{ $registro->nome }}</td><td class="p-3">{{ $registro->documento ?? $registro->codigo }}</td><td class="p-3">{{ $registro->ativo ? 'Ativo' : 'Inativo' }}</td><td class="p-3"><button type="button" wire:click="editar({{ $registro->id }})" class="text-blue-600">Editar</button></td></tr>
                    @empty <tr><td colspan="4" class="p-3 text-gray-600">Nenhum cadastro encontrado.</td></tr> @endforelse
                </tbody></table></div>
                {{ $registros->links() }}
            @endif
        @endif
    </div>
</div>
