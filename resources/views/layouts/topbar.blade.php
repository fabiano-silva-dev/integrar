{{-- Cabeçalho: marca + seletores + avatar --}}
<header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-4 border-b border-gray-200 bg-white px-4 sm:px-6">
    <button type="button"
       @click="toggleSidebar()"
       class="flex shrink-0 items-center min-w-0 rounded-lg p-1 hover:bg-gray-100 lg:hidden"
       aria-label="Abrir menu">
        <img src="{{ asset('images/brand/logo.png') }}"
             srcset="{{ asset('images/brand/logo@2x.png') }} 2x, {{ asset('images/brand/logo@3x.png') }} 3x"
             alt="IntegraExpert"
             class="h-8 w-auto max-w-[140px] sm:max-w-[180px]">
    </button>

    <a href="{{ route('home') }}"
       class="hidden shrink-0 items-center min-w-0 lg:flex"
       aria-label="IntegraExpert">
        <img src="{{ asset('images/brand/logo.png') }}"
             srcset="{{ asset('images/brand/logo@2x.png') }} 2x, {{ asset('images/brand/logo@3x.png') }} 3x"
             alt="IntegraExpert"
             class="h-8 w-auto max-w-[180px]">
    </a>

    <div class="flex-1 min-w-0">
        @include('partials.seletor-contexto-pill', [
            'empresas' => $empresas ?? collect(),
            'empresaAtual' => $empresaAtual ?? null,
            'operadoras' => $operadoras ?? collect(),
        ])
    </div>

    <div class="relative group shrink-0">
        <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 font-bold text-base hover:bg-indigo-200 transition-colors">
            {{ $userData['initial'] }}
        </button>
        <div class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-xl shadow-lg z-50 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200">
            <div class="px-4 py-3 border-b border-gray-100">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $userData['name'] }}</p>
                <p class="text-xs text-gray-500 truncate">{{ $userData['email'] }}</p>
            </div>
            <a href="{{ route('profile.edit') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">Configurações</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="block w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">Sair</button>
            </form>
        </div>
    </div>
</header>
