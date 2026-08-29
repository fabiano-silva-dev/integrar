<div class="p-4 sm:p-6 lg:p-8 max-w-6xl mx-auto">
    <div class="mb-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Olá, {{ auth()->user()?->name ?? 'usuário' }}</h1>
        <p class="mt-1 text-gray-600">Escolha uma operação para começar.</p>
    </div>

    @if ($podeVerEstatisticas)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-10">
            <a href="{{ route('importacoes') }}"
               aria-label="Importações recentes neste mês: {{ $precisaSelecionarEscritorio ? 'selecione o escritório' : $totalImportacoesRecentes }}"
               class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-indigo-200 transition">
                <div class="flex items-start justify-between">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                        <x-menu-icon name="import" class="w-5 h-5" />
                    </div>
                    <span class="text-2xl font-bold text-gray-900">
                        {{ $precisaSelecionarEscritorio ? '—' : $totalImportacoesRecentes }}
                    </span>
                </div>
                <p class="mt-4 font-semibold text-gray-900">Importações recentes</p>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $precisaSelecionarEscritorio ? 'Selecione o escritório' : 'Neste mês' }}
                </p>
            </a>

            <a href="{{ route('exportador') }}"
               aria-label="Exportações do mês: {{ $precisaSelecionarEscritorio ? 'selecione o escritório' : $totalExportacoesMes }}"
               class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-indigo-200 transition">
                <div class="flex items-start justify-between">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-50 text-purple-600">
                        <x-menu-icon name="export" class="w-5 h-5" />
                    </div>
                    <span class="text-2xl font-bold text-gray-900">
                        {{ $precisaSelecionarEscritorio ? '—' : $totalExportacoesMes }}
                    </span>
                </div>
                <p class="mt-4 font-semibold text-gray-900">Exportações do mês</p>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $precisaSelecionarEscritorio ? 'Selecione o escritório' : 'Neste mês' }}
                </p>
            </a>

            <a href="{{ route('conversoes-extrato') }}"
               aria-label="Conversões recentes neste mês: {{ $precisaSelecionarEscritorio ? 'selecione o escritório' : $totalConversoesRecentes }}"
               class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-indigo-200 transition">
                <div class="flex items-start justify-between">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-green-50 text-green-600">
                        <x-menu-icon name="convert" class="w-5 h-5" />
                    </div>
                    <span class="text-2xl font-bold text-gray-900">
                        {{ $precisaSelecionarEscritorio ? '—' : $totalConversoesRecentes }}
                    </span>
                </div>
                <p class="mt-4 font-semibold text-gray-900">Conversões recentes</p>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $precisaSelecionarEscritorio ? 'Selecione o escritório' : 'Neste mês' }}
                </p>
            </a>
        </div>
    @endif

    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Operações principais</h2>
        <span class="text-xs text-gray-400">Extratos, notas fiscais e arquivos</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <a href="{{ route('importador-avancado') }}"
           class="group relative overflow-hidden rounded-2xl bg-indigo-600 p-6 text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl hover:scale-[1.01] transition-all duration-200 min-h-[220px] flex flex-col"
           style="background-color: #4f46e5;">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                <x-menu-icon name="import" class="w-7 h-7 text-white" />
            </div>
            <h3 class="mt-5 text-xl font-bold">Importar extrato</h3>
            <p class="mt-2 text-sm text-indigo-100 leading-relaxed flex-1">Importe extratos bancários para lançamentos contábeis.</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 group-hover:gap-2 transition-all">
                Iniciar
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </a>

        <a href="{{ route('importador-personalizado') }}"
           class="group relative overflow-hidden rounded-2xl bg-indigo-600 p-6 text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl hover:scale-[1.01] transition-all duration-200 min-h-[220px] flex flex-col"
           style="background-color: #4f46e5;">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                <x-menu-icon name="sliders" class="w-7 h-7 text-white" />
            </div>
            <h3 class="mt-5 text-xl font-bold">Importação personalizada</h3>
            <p class="mt-2 text-sm text-indigo-100 leading-relaxed flex-1">Crie e use layouts de importação customizados.</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 group-hover:gap-2 transition-all">
                Iniciar
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </a>

        <a href="{{ route('tabela') }}"
           class="group relative overflow-hidden rounded-2xl bg-indigo-600 p-6 text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl hover:scale-[1.01] transition-all duration-200 min-h-[220px] flex flex-col"
           style="background-color: #4f46e5;">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                <x-menu-icon name="table" class="w-7 h-7 text-white" />
            </div>
            <h3 class="mt-5 text-xl font-bold">Lançamentos</h3>
            <p class="mt-2 text-sm text-indigo-100 leading-relaxed flex-1">Confira e ajuste os lançamentos importados.</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 group-hover:gap-2 transition-all">
                Iniciar
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </a>

        <a href="{{ route('exportador') }}"
           class="group relative overflow-hidden rounded-2xl bg-indigo-600 p-6 text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl hover:scale-[1.01] transition-all duration-200 min-h-[220px] flex flex-col"
           style="background-color: #4f46e5;">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                <x-menu-icon name="export" class="w-7 h-7 text-white" />
            </div>
            <h3 class="mt-5 text-xl font-bold">Exportar lançamentos</h3>
            <p class="mt-2 text-sm text-indigo-100 leading-relaxed flex-1">Exporte os lançamentos para o sistema contábil.</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 group-hover:gap-2 transition-all">
                Iniciar
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </a>

        <a href="{{ route('conversao-pdf-ofx') }}"
           class="group relative overflow-hidden rounded-2xl bg-indigo-600 p-6 text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl hover:scale-[1.01] transition-all duration-200 min-h-[220px] flex flex-col"
           style="background-color: #4f46e5;">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                <x-menu-icon name="convert" class="w-7 h-7 text-white" />
            </div>
            <h3 class="mt-5 text-xl font-bold">Converter PDF para OFX</h3>
            <p class="mt-2 text-sm text-indigo-100 leading-relaxed flex-1">Transforme extratos em PDF em OFX ou planilha para importação.</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 group-hover:gap-2 transition-all">
                Iniciar
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </a>

        <a href="{{ route('automacao-fiscal.analises') }}"
           class="group relative overflow-hidden rounded-2xl bg-indigo-600 p-6 text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl hover:scale-[1.01] transition-all duration-200 min-h-[220px] flex flex-col"
           style="background-color: #4f46e5;">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                <x-menu-icon name="document" class="w-7 h-7 text-white" />
            </div>
            <h3 class="mt-5 text-xl font-bold">Notas fiscais</h3>
            <p class="mt-2 text-sm text-indigo-100 leading-relaxed flex-1">Consulte NF-e e NFS-e por competência — emitidas e recebidas.</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 group-hover:gap-2 transition-all">
                Abrir
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </a>

        <a href="{{ route('documentos') }}"
           class="group relative overflow-hidden rounded-2xl bg-indigo-600 p-6 text-white shadow-lg hover:bg-indigo-700 hover:shadow-xl hover:scale-[1.01] transition-all duration-200 min-h-[220px] flex flex-col"
           style="background-color: #4f46e5;">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                <x-menu-icon name="folder" class="w-7 h-7 text-white" />
            </div>
            <h3 class="mt-5 text-xl font-bold">Drive contábil</h3>
            <p class="mt-2 text-sm text-indigo-100 leading-relaxed flex-1">Arquivos das empresas recebidos pelo WhatsApp — abra e baixe quando precisar.</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-white/90 group-hover:gap-2 transition-all">
                Abrir
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </a>
    </div>
</div>
