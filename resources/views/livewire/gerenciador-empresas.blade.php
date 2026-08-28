<div class="p-6">
    <div class="max-w-7xl mx-auto space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Empresas</h1>
                <p class="text-sm text-gray-500 mt-0.5">
                    Clique em uma empresa para abrir a ficha. Cadastre manualmente, por certificado ou por planilha.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button"
                        wire:click="abrirModalCadastro('certificado')"
                        @disabled($precisaSelecionarEscritorio)
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white border border-indigo-200 text-indigo-700 text-sm font-semibold hover:bg-indigo-50 disabled:opacity-50 disabled:cursor-not-allowed">
                    Por certificado
                </button>
                <a href="{{ route('empresas.importar') }}"
                   class="inline-flex items-center px-4 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50">
                    Importar
                </a>
                <button type="button"
                        wire:click="novaEmpresa"
                        @disabled($precisaSelecionarEscritorio)
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Nova empresa
                </button>
            </div>
        </div>

        @if (session()->has('message'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                {{ session('message') }}
            </div>
        @endif

        @if (session()->has('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                {{ session('error') }}
            </div>
        @endif

        @if ($precisaSelecionarEscritorio)
            <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                Selecione um escritório no menu superior para cadastrar ou editar empresas.
            </div>
        @endif

        @if ($modo_edicao)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                <div class="p-6">
                    <div class="mb-4">
                        <button type="button" wire:click="cancelarEdicao"
                                class="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800 mb-1">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                            Voltar à listagem
                        </button>
                        <h2 class="text-lg font-semibold text-gray-900">
                            {{ $nome_fantasia ?: $razao_social ?: $nome ?: 'Editar empresa' }}
                        </h2>
                        @if($cnpj)
                            <p class="text-sm text-gray-500">{{ $cnpj }}</p>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-200 pb-3">
                        @foreach ([
                            'cadastro' => 'Dados cadastrais',
                            'integracoes' => 'Integrações',
                            'certificados' => 'Certificados',
                            'agendamentos' => 'Agendamentos',
                            'historico' => 'Histórico de execuções',
                            'documentos' => 'Documentos',
                        ] as $key => $label)
                            <button type="button" wire:click="setAba('{{ $key }}')"
                                    class="px-3 py-2 text-sm rounded-lg {{ $aba === $key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    <div @if($precisaSelecionarEscritorio) class="opacity-50 pointer-events-none" @endif>
                        @if ($aba === 'cadastro')
                            <form wire:submit.prevent="salvar" class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Razão social</label>
                                        <input type="text" wire:model="razao_social"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                        @error('razao_social') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Nome fantasia</label>
                                        <input type="text" wire:model="nome_fantasia" autofocus
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                        @error('nome_fantasia') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                        @error('nome') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">CNPJ</label>
                                        <input type="text" wire:model="cnpj"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500"
                                               placeholder="00.000.000/0000-00">
                                        @error('cnpj') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Inscrição estadual</label>
                                        <input type="text" wire:model="inscricao_estadual"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Inscrição municipal</label>
                                        <input type="text" wire:model="inscricao_municipal"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">UF</label>
                                        <input type="text" wire:model="uf" maxlength="2"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500 uppercase">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Município</label>
                                        <input type="text" wire:model="municipio"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Código IBGE</label>
                                        <input type="text" wire:model="codigo_municipio_ibge"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Código do sistema contábil</label>
                                        <input type="text" wire:model="codigo_sistema"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Conta contábil do banco <span class="text-gray-500 font-normal">(opcional)</span></label>
                                        <input type="text" wire:model="codigo_conta_banco"
                                               class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                    </div>
                                    <div class="flex items-center gap-2 pt-6">
                                        <input id="ativo" type="checkbox" wire:model="ativo" class="rounded border-gray-300 text-indigo-600">
                                        <label for="ativo" class="text-sm font-medium text-gray-700">Empresa ativa</label>
                                    </div>
                                </div>

                                <div class="flex justify-end space-x-3">
                                    <button type="button" wire:click="cancelarEdicao"
                                            class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                        Cancelar
                                    </button>
                                    <button type="submit"
                                            class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">
                                        Atualizar
                                    </button>
                                </div>
                            </form>
                        @elseif ($aba === 'integracoes')
                            <form wire:submit.prevent="salvarIntegracoes" class="space-y-6">
                                @foreach($portais as $portal)
                                    @php $codigo = $portal->codigo; @endphp
                                    <div class="border border-gray-200 rounded-xl p-4">
                                        <label class="flex items-center gap-2 font-semibold text-gray-900">
                                            <input type="checkbox" wire:model.live="integracoesForm.{{ $codigo }}.ativo" class="rounded border-gray-300 text-indigo-600">
                                            {{ $portal->nome }}
                                        </label>

                                        <div @class([
                                            'mt-3',
                                            'opacity-50 pointer-events-none' => ! filter_var($integracoesForm[$codigo]['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN),
                                        ])>
                                            <label class="block text-sm text-gray-700 mb-1">Certificado</label>
                                            <select wire:model="integracoesForm.{{ $codigo }}.certificado_digital_id"
                                                    class="block w-full md:w-1/2 border-gray-300 rounded-md shadow-sm">
                                                <option value="">Selecione</option>
                                                @php
                                                    $certsEscritorio = $certificados->whereNull('empresa_id');
                                                    $certsCliente = $certificados->where('empresa_id', $empresa_id);
                                                    $certsOutros = $certificados
                                                        ->whereNotNull('empresa_id')
                                                        ->where('empresa_id', '!=', $empresa_id);
                                                @endphp
                                                @if($certsEscritorio->isNotEmpty())
                                                    <optgroup label="Escritório (CNPJ Empresa Contábil no e-CAC)">
                                                        @foreach($certsEscritorio as $cert)
                                                            <option value="{{ $cert->id }}">{{ $cert->nome }} @if($cert->valido_ate) (até {{ $cert->valido_ate->format('d/m/Y') }}) @endif</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                                @if($certsCliente->isNotEmpty())
                                                    <optgroup label="Esta empresa (CPF do Responsável no e-CAC)">
                                                        @foreach($certsCliente as $cert)
                                                            <option value="{{ $cert->id }}">{{ $cert->nome }} @if($cert->valido_ate) (até {{ $cert->valido_ate->format('d/m/Y') }}) @endif</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                                @if($certsOutros->isNotEmpty())
                                                    <optgroup label="Outras empresas do escritório">
                                                        @foreach($certsOutros as $cert)
                                                            <option value="{{ $cert->id }}">{{ $cert->nome }} — {{ $cert->empresa?->nome }} @if($cert->valido_ate) (até {{ $cert->valido_ate->format('d/m/Y') }}) @endif</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            </select>
                                            <p class="text-xs text-gray-500 mt-1">
                                                Escritório = entrada como contador; certificado da empresa = responsável legal.
                                            </p>

                                            <div class="mt-4 space-y-2">
                                                @foreach($portal->recursos->where('codigo', '!=', 'validar_acesso') as $recurso)
                                                    <div class="flex flex-wrap items-center gap-3 bg-gray-50 rounded-lg p-3">
                                                        <label class="flex items-center gap-2 min-w-[180px]">
                                                            <input type="checkbox"
                                                                   wire:model.live="integracoesForm.{{ $codigo }}.recursos.{{ $recurso->codigo }}.ativo"
                                                                   class="rounded border-gray-300 text-indigo-600">
                                                            <span class="text-sm text-gray-800">{{ $recurso->nome }}</span>
                                                        </label>
                                                        <select wire:model="integracoesForm.{{ $codigo }}.recursos.{{ $recurso->codigo }}.agenda_automacao_id"
                                                                class="border-gray-300 rounded-md text-sm">
                                                            <option value="">Sem agenda</option>
                                                            @foreach($agendas as $agenda)
                                                                <option value="{{ $agenda->id }}">{{ $agenda->nome }}</option>
                                                            @endforeach
                                                        </select>
                                                        @if(filter_var($integracoesForm[$codigo]['recursos'][$recurso->codigo]['ativo'] ?? false, FILTER_VALIDATE_BOOLEAN))
                                                            <button type="button"
                                                                    wire:click="executarAgora({{ $recurso->id }})"
                                                                    class="text-xs text-indigo-600 hover:underline">
                                                                Executar agora
                                                            </button>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                                <div class="flex justify-end">
                                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg">
                                        Salvar integrações
                                    </button>
                                </div>
                            </form>
                        @elseif ($aba === 'certificados')
                            <p class="text-sm text-gray-600 mb-4">
                                Cadastre ou gerencie certificados em
                                <a href="{{ route('configuracoes.automacao-fiscal', ['aba' => 'certificados']) }}" class="text-indigo-600 underline">Configurações → Certificados</a>
                                e vincule-os na aba Integrações.
                            </p>
                            <ul class="divide-y divide-gray-200 border rounded-lg">
                                @forelse($certificados->where('empresa_id', $empresa_id)->merge($certificados->whereNull('empresa_id')) as $cert)
                                    <li class="px-4 py-3 text-sm flex justify-between gap-3">
                                        <span>{{ $cert->nome }} — {{ $cert->titular ?: 'sem titular' }}</span>
                                        <span class="text-gray-500">{{ $cert->valido_ate?->format('d/m/Y') ?: '-' }}</span>
                                    </li>
                                @empty
                                    <li class="px-4 py-3 text-sm text-gray-500">Nenhum certificado disponível neste escritório.</li>
                                @endforelse
                            </ul>
                        @elseif ($aba === 'agendamentos')
                            <p class="text-sm text-gray-600 mb-4">
                                Agendas do escritório em
                                <a href="{{ route('configuracoes.automacao-fiscal', ['aba' => 'agendas']) }}" class="text-indigo-600 underline">Configurações → Agendas</a>.
                            </p>
                            <ul class="divide-y divide-gray-200 border rounded-lg">
                                @forelse($agendas as $agenda)
                                    <li class="px-4 py-3 text-sm">
                                        <span class="font-medium">{{ $agenda->nome }}</span>
                                        <span class="text-gray-500"> — {{ $agenda->frequencia }}</span>
                                    </li>
                                @empty
                                    <li class="px-4 py-3 text-sm text-gray-500">Nenhuma agenda cadastrada.</li>
                                @endforelse
                            </ul>
                        @elseif ($aba === 'historico')
                            <div class="overflow-x-auto border rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left">Portal / recurso</th>
                                            <th class="px-4 py-2 text-left">Período</th>
                                            <th class="px-4 py-2 text-left">Status</th>
                                            <th class="px-4 py-2 text-left">Mensagem</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse($execucoes as $execucao)
                                            <tr>
                                                <td class="px-4 py-2">
                                                    {{ $execucao->portalRecurso?->portal?->nome }} / {{ $execucao->portalRecurso?->nome }}
                                                </td>
                                                <td class="px-4 py-2">
                                                    {{ $execucao->periodo_inicio?->format('d/m/Y') }} – {{ $execucao->periodo_fim?->format('d/m/Y') }}
                                                </td>
                                                <td class="px-4 py-2">{{ $execucao->status }}</td>
                                                <td class="px-4 py-2">{{ $execucao->mensagem_usuario ?: '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-4 py-6 text-center text-gray-500">Nenhuma execução registrada.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @elseif ($aba === 'documentos')
                            <p class="text-sm text-gray-600 mb-4">
                                Pasta no Google Drive e grupos WhatsApp desta empresa.
                                Configure em
                                <a href="{{ route('documentos.drive') }}" class="text-indigo-600 underline">Configurações → Google Drive</a>
                                e
                                <a href="{{ route('documentos.grupos') }}" class="text-indigo-600 underline">Configurações → Grupos</a>.
                            </p>
                            <div class="mb-4 border rounded-lg p-4">
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Pasta raiz no Drive</h3>
                                @if ($pastaDriveRaiz)
                                    @if (auth()->user()?->podeAbrirGoogleDriveExterno())
                                    <a href="{{ $pastaDriveRaiz->urlDrive() }}" target="_blank" class="text-indigo-600 text-sm">
                                        {{ $pastaDriveRaiz->google_folder_nome ?: $pastaDriveRaiz->google_folder_id }}
                                    </a>
                                    @else
                                    <p class="text-sm text-gray-700">{{ $pastaDriveRaiz->google_folder_nome ?: $pastaDriveRaiz->google_folder_id }}</p>
                                    @endif
                                @else
                                    <p class="text-sm text-gray-500">Não definida.</p>
                                @endif
                            </div>
                            <h3 class="text-sm font-semibold text-gray-900 mb-2">Grupos WhatsApp</h3>
                            <ul class="divide-y divide-gray-200 border rounded-lg">
                                @forelse ($gruposWhatsapp as $grupo)
                                    <li class="px-4 py-3 text-sm flex justify-between gap-3">
                                        <span>{{ $grupo->nome ?: $grupo->jid }}</span>
                                        <span class="text-xs {{ $grupo->monitorar ? 'text-green-700' : 'text-gray-500' }}">
                                            {{ $grupo->monitorar ? 'Monitorando' : 'Parado' }}
                                        </span>
                                    </li>
                                @empty
                                    <li class="px-4 py-3 text-sm text-gray-500">Nenhum grupo vinculado a esta empresa.</li>
                                @endforelse
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if (!$modo_edicao)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                <div class="p-4 sm:p-6 space-y-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[14rem] max-w-sm">
                            <label for="busca-empresas" class="sr-only">Buscar empresas</label>
                            <input id="busca-empresas"
                                   type="text"
                                   wire:model.live.debounce.300ms="busca"
                                   placeholder="Buscar por nome ou CNPJ…"
                                   aria-label="Buscar empresas"
                                   class="w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                        </div>
                        <div>
                            <label for="filtro-ativo" class="sr-only">Status</label>
                            <select id="filtro-ativo"
                                    wire:model.live="filtroAtivo"
                                    class="rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">Todos os status</option>
                                <option value="1">Ativas</option>
                                <option value="0">Inativas</option>
                            </select>
                        </div>
                        @if($empresas->total() > 0)
                            <p class="text-sm text-gray-500 pb-2">
                                {{ $empresas->firstItem() }}–{{ $empresas->lastItem() }} de {{ $empresas->total() }}
                            </p>
                        @endif
                    </div>

                    <div class="rounded-lg border border-gray-200 overflow-hidden">
                        <div class="relative w-full overflow-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        @foreach ([
                                            'nome' => 'Nome',
                                            'cnpj' => 'CNPJ',
                                            'uf' => 'UF',
                                            'codigo_sistema' => 'Cód. sistema',
                                            'ativo' => 'Status',
                                        ] as $coluna => $label)
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                <button type="button"
                                                        wire:click="ordenarPor('{{ $coluna }}')"
                                                        class="-ml-1 inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-left font-medium hover:bg-gray-100 hover:text-gray-800 {{ $ordenar === $coluna ? 'text-gray-900' : '' }}">
                                                    <span>{{ $label }}</span>
                                                    <svg class="h-3.5 w-3.5 shrink-0 {{ $ordenar === $coluna ? '' : 'opacity-40' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        @if($ordenar === $coluna && $direcao === 'desc')
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                                        @else
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/>
                                                        @endif
                                                    </svg>
                                                </button>
                                            </th>
                                        @endforeach
                                        <th class="px-4 py-3 w-24"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @forelse($empresas as $empresa)
                                        <tr wire:key="empresa-{{ $empresa->id }}"
                                            wire:click="editar({{ $empresa->id }})"
                                            class="cursor-pointer hover:bg-gray-50 transition-colors">
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-gray-900">{{ $empresa->nome }}</div>
                                                @if($empresa->nome_fantasia && $empresa->nome_fantasia !== $empresa->nome)
                                                    <div class="text-xs text-gray-500 truncate max-w-xs">{{ $empresa->nome_fantasia }}</div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $empresa->cnpj }}</td>
                                            <td class="px-4 py-3 text-gray-600">{{ $empresa->uf ?: '—' }}</td>
                                            <td class="px-4 py-3 text-gray-600">{{ $empresa->codigo_sistema ?: '—' }}</td>
                                            <td class="px-4 py-3">
                                                <span @class([
                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
                                                    'bg-green-100 text-green-800' => $empresa->ativo,
                                                    'bg-gray-100 text-gray-600' => !$empresa->ativo,
                                                ])>
                                                    {{ $empresa->ativo ? 'Ativa' : 'Inativa' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex justify-end gap-1">
                                                    <button type="button"
                                                            wire:click.stop="editar({{ $empresa->id }})"
                                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-indigo-700"
                                                            aria-label="Editar {{ $empresa->nome }}">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/>
                                                        </svg>
                                                    </button>
                                                    <button type="button"
                                                            wire:click.stop="confirmarExclusao({{ $empresa->id }})"
                                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-red-50 hover:text-red-600"
                                                            aria-label="Excluir {{ $empresa->nome }}">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">
                                                @if(trim($busca) !== '' || $filtroAtivo !== '')
                                                    Nenhuma empresa encontrada para esta busca.
                                                @else
                                                    Nenhuma empresa cadastrada.
                                                @endif
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    @if($empresas->hasPages())
                        <div class="pt-1">{{ $empresas->links() }}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    @if($confirmando_exclusao)
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3 text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Confirmar exclusão</h3>
                    <p class="text-sm text-gray-500 mb-4">Esta ação não pode ser desfeita. Confirma a exclusão desta empresa?</p>
                    <div class="flex justify-center space-x-3">
                        <button wire:click="cancelarExclusao" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">Cancelar</button>
                        <button wire:click="excluir" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">Excluir</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($modalCadastroAberto)
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
             wire:click.self="fecharModalCadastro">
            <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-3 mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Cadastrar empresa</h3>
                    <button type="button" wire:click="fecharModalCadastro" class="text-gray-400 hover:text-gray-600" aria-label="Fechar">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-200 pb-3">
                    @foreach ([
                        'dados' => 'Dados da empresa',
                        'certificado' => 'Por certificado',
                        'excel' => 'Por Excel',
                    ] as $key => $label)
                        <button type="button" wire:click="setModalAba('{{ $key }}')"
                                class="px-3 py-2 text-sm rounded-lg {{ $modalAba === $key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @if($modalAba === 'dados')
                    <form wire:submit.prevent="salvar" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Razão social</label>
                                <input type="text" wire:model="razao_social"
                                       class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                @error('razao_social') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Nome fantasia</label>
                                <input type="text" wire:model="nome_fantasia"
                                       class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                                @error('nome_fantasia') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                @error('nome') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">CNPJ</label>
                                <input type="text" wire:model="cnpj"
                                       class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500"
                                       placeholder="00.000.000/0000-00">
                                @error('cnpj') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">UF</label>
                                <input type="text" wire:model="uf" maxlength="2"
                                       class="mt-1 block w-full border border-gray-400 bg-white rounded-md shadow-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-500 uppercase">
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" wire:click="fecharModalCadastro"
                                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                Cancelar
                            </button>
                            <button type="submit"
                                    class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                Salvar
                            </button>
                        </div>
                    </form>
                @elseif($modalAba === 'certificado')
                    <div class="space-y-4">
                        <x-zona-upload
                            input-id="certificado-upload-modal"
                            wire:model="certificadoArquivo"
                            accept=".pfx,.p12"
                            formato="PFX ou P12"
                            :nome-arquivo="$certificadoArquivo?->getClientOriginalName()"
                        />
                        @error('certificadoArquivo') <p class="text-red-500 text-sm">{{ $message }}</p> @enderror

                        @if($certificadoArquivo)
                            <div>
                                <label for="senha-certificado-modal" class="block text-sm font-medium text-gray-700 mb-1">Senha do certificado</label>
                                <div class="relative" x-data="{ show: false }">
                                    <input
                                        id="senha-certificado-modal"
                                        :type="show ? 'text' : 'password'"
                                        wire:model="certificadoSenha"
                                        name="integrar_pfx_password_field"
                                        autocomplete="off"
                                        autocapitalize="off"
                                        autocorrect="off"
                                        spellcheck="false"
                                        data-lpignore="true"
                                        data-1p-ignore="true"
                                        data-bwignore="true"
                                        data-form-type="other"
                                        readonly
                                        onfocus="this.removeAttribute('readonly')"
                                        placeholder="Digite a senha"
                                        @disabled($enviandoCertificado)
                                        class="block w-full rounded-md border-gray-300 pr-10 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-50"
                                    >
                                    <button
                                        type="button"
                                        tabindex="-1"
                                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-gray-400 hover:text-indigo-600"
                                        @click="show = !show"
                                        :aria-label="show ? 'Ocultar senha' : 'Mostrar senha'"
                                    >
                                        <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .638C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                        </svg>
                                        <svg x-show="show" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 2.036 11.683a1.012 1.012 0 0 0 0 .639C3.423 16.49 7.36 19.5 12 19.5c.993 0 1.953-.138 2.863-.395m3.228-1.014a10.451 10.451 0 0 0 3.872-5.775 1.012 1.012 0 0 0 0-.639C20.577 7.51 16.64 4.5 12 4.5c-1.496 0-2.919.313-4.207.877M15 12a3 3 0 0 1-3 3m0-6a3 3 0 0 1 3 3M3 3l18 18" />
                                        </svg>
                                    </button>
                                </div>
                                @error('certificadoSenha') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
                            </div>

                            @if($certificadoMensagem !== '' && $certificadoMensagemTipo === 'erro')
                                <p class="text-sm text-red-600">{{ $certificadoMensagem }}</p>
                            @endif

                            <div class="flex justify-end gap-3 pt-1">
                                <button type="button"
                                        wire:click="limparCertificado"
                                        @disabled($enviandoCertificado)
                                        class="px-4 py-2 text-sm rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 disabled:opacity-50">
                                    Limpar
                                </button>
                                <button type="button"
                                        wire:click="cadastrarPorCertificado"
                                        wire:loading.attr="disabled"
                                        wire:target="cadastrarPorCertificado,certificadoArquivo"
                                        @disabled($enviandoCertificado)
                                        class="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="cadastrarPorCertificado">Cadastrar empresa</span>
                                    <span wire:loading wire:target="cadastrarPorCertificado">Cadastrando...</span>
                                </button>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="space-y-4 text-center py-4">
                        <p class="text-sm text-gray-600">Importe várias empresas de uma planilha CSV ou Excel.</p>
                        <a href="{{ route('empresas.importar') }}"
                           class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                            Abrir importação por Excel
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
