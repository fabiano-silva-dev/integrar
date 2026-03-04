<div class="max-w-5xl mx-auto py-10">
    <h1 class="text-3xl font-bold mb-8 flex items-center gap-2">🏠 Home do Sistema</h1>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Cadastros -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">📋 Cadastros</h2>
            <ul class="space-y-2">
                <li><a href="{{ route('empresas') }}" class="flex items-center gap-2 hover:underline">🏢 Empresas</a></li>
                <li><a href="{{ route('usuarios') }}" class="flex items-center gap-2 hover:underline">👥 Usuários</a></li>
                <li><a href="{{ route('terceiros') }}" class="flex items-center gap-2 hover:underline">🤝 Terceiros</a></li>
            </ul>
        </div>
        <!-- Importação -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">📥 Importação</h2>
            <ul class="space-y-2">
                <li><a href="{{ route('importador-avancado') }}" class="flex items-center gap-2 hover:underline">📄 Importação de Extratos</a></li>
                <li><a href="{{ route('importador-personalizado') }}" title="Importação personalizada de CSV, XLS ou XLSX" class="flex items-center gap-2 hover:underline">🎯 Importação Personalizada</a></li>
                <li><a href="{{ route('importacoes') }}" class="flex items-center gap-2 hover:underline">🕑 Importações anteriores</a></li>
            </ul>
        </div>
        <!-- Lançamentos -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">📊 Lançamentos</h2>
            <ul class="space-y-2">
                <li><a href="{{ route('tabela') }}" class="flex items-center gap-2 hover:underline">📋 Tabela de lançamentos</a></li>
                <li><a href="{{ route('regras-amarracao') }}" class="flex items-center gap-2 hover:underline">⚙️ Regras de Amarração</a></li>
            </ul>
        </div>
        <!-- Exportação -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">📤 Exportação</h2>
            <ul class="space-y-2">
                <li><a href="{{ route('exportador') }}" class="flex items-center gap-2 hover:underline">📤 Exportador</a></li>
            </ul>
        </div>
        <!-- Administração -->
        <div class="bg-white rounded-lg shadow p-6 col-span-1 md:col-span-2">
            <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">⚙️ Administração</h2>
            <ul class="flex flex-wrap gap-6">
                <li class="flex items-center gap-2 text-gray-400">🛠️ Configurações <span class="text-xs">(em breve)</span></li>
                <li class="flex items-center gap-2 text-gray-400">📜 Logs <span class="text-xs">(em breve)</span></li>
                <li class="flex items-center gap-2 text-gray-400">🔑 Acessos <span class="text-xs">(em breve)</span></li>
            </ul>
        </div>
    </div>
    <div class="mt-10">
        <h3 class="text-lg font-semibold mb-2">✔️ Fluxo do sistema</h3>
        <div class="flex items-center gap-4">
            <span class="px-3 py-1 rounded bg-blue-100 text-blue-800">Importado</span>
            <span class="text-xl">→</span>
            <span class="px-3 py-1 rounded bg-green-100 text-green-800">Amarrado</span>
            <span class="text-xl">→</span>
            <span class="px-3 py-1 rounded bg-purple-100 text-purple-800">Exportado</span>
        </div>
        <p class="text-gray-500 mt-2 text-sm">Siga o fluxo para garantir o processamento correto dos dados.</p>
    </div>
</div>
