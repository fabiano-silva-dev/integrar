<div class="p-6">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white shadow-xl rounded-xl overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Configurações — Automação Fiscal</h1>
                <p class="text-sm text-gray-600 mt-1">Portais, certificados, agendas e execuções do escritório.</p>
            </div>

            <div class="px-6 pt-4 flex flex-wrap gap-2 border-b border-gray-100">
                @foreach ([
                    'geral' => 'Geral',
                    'portais' => 'Portais',
                    'certificados' => 'Certificados',
                    'agendas' => 'Agendas',
                    'execucoes' => 'Execuções',
                ] as $key => $label)
                    <button type="button" wire:click="setAba('{{ $key }}')"
                            class="px-3 py-2 text-sm rounded-t-lg {{ $aba === $key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
                @if ($podeVerLogs)
                    <button type="button" wire:click="setAba('logs')"
                            class="px-3 py-2 text-sm rounded-t-lg {{ $aba === 'logs' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                        Logs técnicos
                    </button>
                @endif
            </div>

            <div class="p-6">
                @if (session()->has('message'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('message') }}</div>
                @endif
                @if (session()->has('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>
                @endif

                <x-aviso-fila-automacoes class="mb-4" />

                @if ($precisaSelecionarEscritorio)
                    <div class="bg-amber-100 border border-amber-400 text-amber-800 px-4 py-3 rounded">
                        Selecione um escritório no menu superior para configurar a automação fiscal.
                    </div>
                @elseif ($aba === 'geral')
                    <form wire:submit.prevent="salvarGeral" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Timezone</label>
                            <input type="text" wire:model="timezone" class="mt-1 w-full border-gray-300 rounded-md">
                            @error('timezone') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Período padrão (dias)</label>
                            <input type="number" wire:model="periodo_padrao_dias" class="mt-1 w-full border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Execuções simultâneas</label>
                            <input type="number" wire:model="max_execucoes_simultaneas" class="mt-1 w-full border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tentativas</label>
                            <input type="number" wire:model="politica_tentativas" class="mt-1 w-full border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Retenção logs (dias)</label>
                            <input type="number" wire:model="retencao_logs_dias" class="mt-1 w-full border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Retenção artefatos (dias)</label>
                            <input type="number" wire:model="retencao_artefatos_dias" class="mt-1 w-full border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Aviso certificado (dias)</label>
                            <input type="number" wire:model="aviso_certificado_dias" class="mt-1 w-full border-gray-300 rounded-md">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg">Salvar</button>
                        </div>
                    </form>
                @elseif ($aba === 'portais')
                    @if (auth()->user()?->isSuperAdmin() || auth()->user()?->isEscritorioAdmin())
                        <div class="mb-4 flex justify-end">
                            <a href="{{ route('automacao-fiscal.executar') }}" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                                Executar consulta com parâmetros
                            </a>
                        </div>
                    @endif
                    <div class="space-y-4">
                        @foreach($portais as $portal)
                            <div class="border rounded-xl p-4">
                                <div class="flex justify-between gap-3">
                                    <div>
                                        <h3 class="font-semibold text-gray-900">{{ $portal->nome }}</h3>
                                        <p class="text-sm text-gray-500">Driver: {{ $portal->driver }} · Código: {{ $portal->codigo }}</p>
                                    </div>
                                    <span class="text-xs px-2 py-1 rounded-full {{ $portal->ativo ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $portal->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </div>
                                <ul class="mt-3 text-sm text-gray-700 list-disc list-inside">
                                    @foreach($portal->recursos as $recurso)
                                        <li>{{ $recurso->nome }} ({{ $recurso->codigo }})</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @elseif ($aba === 'certificados')
                    <form wire:submit.prevent="uploadCertificado" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl mb-8 border rounded-xl p-4">
                        <div class="md:col-span-2">
                            <h3 class="font-semibold text-gray-900">Novo certificado A1</h3>
                            <p class="text-xs text-gray-500 mt-1">O arquivo não fica disponível para download após o envio.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nome</label>
                            <input type="text" wire:model="certificadoNome" class="mt-1 w-full border-gray-300 rounded-md">
                            @error('certificadoNome') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Vínculo</label>
                            <select wire:model="certificadoEmpresaId" class="mt-1 w-full border-gray-300 rounded-md">
                                <option value="">Escritório / contador (CNPJ Empresa Contábil no e-CAC)</option>
                                @foreach($empresas as $empresa)
                                    <option value="{{ $empresa->id }}">Empresa cliente: {{ $empresa->nome }} (CPF do Responsável)</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Arquivo PFX/P12</label>
                            <input type="file" wire:model="certificadoArquivo" accept=".pfx,.p12" class="mt-1 w-full text-sm">
                            @error('certificadoArquivo') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Senha do certificado</label>
                            <input type="password" wire:model="certificadoSenha" autocomplete="new-password" class="mt-1 w-full border-gray-300 rounded-md">
                            @error('certificadoSenha') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg">Enviar certificado</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full text-sm divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left">Nome</th>
                                    <th class="px-4 py-2 text-left">Titular</th>
                                    <th class="px-4 py-2 text-left">Validade</th>
                                    <th class="px-4 py-2 text-left">Escopo</th>
                                    <th class="px-4 py-2 text-left">Status</th>
                                    <th class="px-4 py-2 text-left">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($certificados as $cert)
                                    <tr>
                                        <td class="px-4 py-2">{{ $cert->nome }}</td>
                                        <td class="px-4 py-2">{{ $cert->titular ?: '-' }}</td>
                                        <td class="px-4 py-2">{{ $cert->valido_ate?->format('d/m/Y') ?: '-' }}</td>
                                        <td class="px-4 py-2">{{ $cert->empresa?->nome ?: 'Escritório' }}</td>
                                        <td class="px-4 py-2">{{ $cert->ativo ? 'Ativo' : 'Inativo' }}</td>
                                        <td class="px-4 py-2">
                                            @if($cert->ativo)
                                                <button type="button" wire:click="confirmarDesativarCertificado({{ $cert->id }})" class="text-red-600 hover:text-red-800">Desativar</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Nenhum certificado cadastrado.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $certificados instanceof \Illuminate\Contracts\Pagination\Paginator ? $certificados->links() : '' }}</div>
                @elseif ($aba === 'agendas')
                    <form wire:submit.prevent="salvarAgenda" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl mb-8 border rounded-xl p-4">
                        <div class="md:col-span-2 font-semibold text-gray-900">{{ $agendaId ? 'Editar agenda' : 'Nova agenda' }}</div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nome</label>
                            <input type="text" wire:model="agendaNome" class="mt-1 w-full border-gray-300 rounded-md">
                            @error('agendaNome') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Frequência</label>
                            <select wire:model.live="agendaFrequencia" class="mt-1 w-full border-gray-300 rounded-md">
                                <option value="diaria">Diária</option>
                                <option value="semanal">Semanal</option>
                                <option value="mensal">Mensal</option>
                                <option value="intervalo">Intervalo</option>
                                <option value="manual">Manual</option>
                            </select>
                        </div>
                        @if ($agendaFrequencia === 'semanal')
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Dias da semana</label>
                                <div class="mt-1 flex flex-wrap gap-3 text-sm">
                                    @foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'] as $dia => $label)
                                        <label class="inline-flex items-center gap-1">
                                            <input type="checkbox" wire:model="agendaDiasSemana" value="{{ $dia }}" class="rounded border-gray-300 text-indigo-600">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                                @error('agendaDiasSemana') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif
                        @if ($agendaFrequencia === 'mensal')
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Dias do mês</label>
                                <input type="text" wire:model="agendaDiasMes" placeholder="1, 15, 31" class="mt-1 w-full border-gray-300 rounded-md">
                                @error('agendaDiasMes') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif
                        @if ($agendaFrequencia === 'intervalo')
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Intervalo (minutos)</label>
                                <input type="number" wire:model="agendaIntervalo" min="5" class="mt-1 w-full border-gray-300 rounded-md">
                                @error('agendaIntervalo') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Horário</label>
                            <input type="time" wire:model="agendaHorario" class="mt-1 w-full border-gray-300 rounded-md">
                        </div>
                        <div class="flex items-center gap-2 pt-6">
                            <input id="agendaAtiva" type="checkbox" wire:model="agendaAtiva" class="rounded border-gray-300 text-indigo-600">
                            <label for="agendaAtiva" class="text-sm">Agenda ativa</label>
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg">
                                {{ $agendaId ? 'Atualizar agenda' : 'Criar agenda' }}
                            </button>
                        </div>
                    </form>

                    <ul class="divide-y border rounded-lg">
                        @forelse($agendas as $agenda)
                            <li class="px-4 py-3 flex flex-wrap justify-between gap-3 text-sm">
                                <div>
                                    <span class="font-medium">{{ $agenda->nome }}</span>
                                    <span class="text-gray-500"> — {{ $agenda->frequencia }} · {{ implode(', ', $agenda->horarios ?? []) }}</span>
                                    @unless($agenda->ativo)<span class="text-amber-600"> (inativa)</span>@endunless
                                </div>
                                <div class="space-x-3">
                                    <button type="button" wire:click="editarAgenda({{ $agenda->id }})" class="text-indigo-600">Editar</button>
                                    <button type="button" wire:click="duplicarAgenda({{ $agenda->id }})" class="text-gray-700">Duplicar</button>
                                    <button type="button" wire:click="confirmarExcluirAgenda({{ $agenda->id }})" class="text-red-600">Excluir</button>
                                </div>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-gray-500 text-sm">Nenhuma agenda cadastrada.</li>
                        @endforelse
                    </ul>
                @elseif ($aba === 'execucoes')
                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full text-sm divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left">Empresa</th>
                                    <th class="px-4 py-2 text-left">Portal / recurso</th>
                                    <th class="px-4 py-2 text-left">Período</th>
                                    <th class="px-4 py-2 text-left">Status</th>
                                    <th class="px-4 py-2 text-left">Mensagem</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($execucoes as $execucao)
                                    <tr>
                                        <td class="px-4 py-2">{{ $execucao->empresa?->nome }}</td>
                                        <td class="px-4 py-2">{{ $execucao->portalRecurso?->portal?->nome }} / {{ $execucao->portalRecurso?->nome }}</td>
                                        <td class="px-4 py-2">{{ $execucao->periodo_inicio?->format('d/m/Y') }} – {{ $execucao->periodo_fim?->format('d/m/Y') }}</td>
                                        <td class="px-4 py-2">{{ $execucao->status }}</td>
                                        <td class="px-4 py-2">{{ $execucao->mensagem_usuario ?: '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Nenhuma execução ainda.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $execucoes instanceof \Illuminate\Contracts\Pagination\Paginator ? $execucoes->links() : '' }}</div>
                @elseif ($aba === 'logs' && $podeVerLogs)
                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full text-sm divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left">UUID</th>
                                    <th class="px-4 py-2 text-left">Nível</th>
                                    <th class="px-4 py-2 text-left">Etapa</th>
                                    <th class="px-4 py-2 text-left">Mensagem</th>
                                    <th class="px-4 py-2 text-left">Quando</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($logs as $log)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-xs">{{ $log->execucao?->uuid }}</td>
                                        <td class="px-4 py-2">{{ $log->nivel }}</td>
                                        <td class="px-4 py-2">{{ $log->etapa ?: '-' }}</td>
                                        <td class="px-4 py-2">{{ $log->mensagem }}</td>
                                        <td class="px-4 py-2">{{ $log->ocorrido_em?->format('d/m/Y H:i:s') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Nenhum log técnico.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $logs instanceof \Illuminate\Contracts\Pagination\Paginator ? $logs->links() : '' }}</div>
                @endif
            </div>
        </div>
    </div>

    @if($confirmandoExclusaoCertificado)
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 z-50 flex items-start justify-center pt-24">
            <div class="bg-white rounded-lg shadow-lg p-6 w-96">
                <p class="text-sm text-gray-700 mb-4">Desativar este certificado? Ele deixará de poder ser usado nas integrações.</p>
                <div class="flex justify-end gap-3">
                    <button type="button" wire:click="$set('confirmandoExclusaoCertificado', null)" class="px-4 py-2 bg-gray-500 text-white rounded">Cancelar</button>
                    <button type="button" wire:click="desativarCertificado" class="px-4 py-2 bg-red-600 text-white rounded">Desativar</button>
                </div>
            </div>
        </div>
    @endif

    @if($confirmandoExclusaoAgenda)
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 z-50 flex items-start justify-center pt-24">
            <div class="bg-white rounded-lg shadow-lg p-6 w-96">
                <p class="text-sm text-gray-700 mb-4">Excluir esta agenda?</p>
                <div class="flex justify-end gap-3">
                    <button type="button" wire:click="$set('confirmandoExclusaoAgenda', null)" class="px-4 py-2 bg-gray-500 text-white rounded">Cancelar</button>
                    <button type="button" wire:click="excluirAgenda" class="px-4 py-2 bg-red-600 text-white rounded">Excluir</button>
                </div>
            </div>
        </div>
    @endif
</div>
