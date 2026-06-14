@php
    $menuData = \App\Http\Controllers\MenuController::getMenuData();
    $menuItems = $menuData['menuItems'];
    $userData = $menuData['userData'];
    $empresas = $menuData['empresas'];
    $empresaAtual = $menuData['empresaAtual'];
    $operadoras = $menuData['operadoras'];
    $operadoraAtual = $menuData['operadoraAtual'];
@endphp

<div
    x-data="{
        sidebarExpanded: localStorage.getItem('sidebarExpanded') !== '0',
        sidebarMobileOpen: false,
        menuOpen: {
            @foreach($menuItems as $menuIndex => $menu)
                {{ $menuIndex }}: {{ collect($menu['items'])->contains(fn ($i) => $i['active'] ?? false) ? 'true' : 'false' }},
            @endforeach
        },
        fecharMenu() {
            this.sidebarExpanded = false;
            this.sidebarMobileOpen = false;
            localStorage.setItem('sidebarExpanded', '0');
        }
    }"
    class="min-h-screen bg-gray-100"
>
    @include('layouts.sidebar', compact('menuItems', 'userData', 'operadoraAtual'))

    <div class="flex min-h-screen flex-col transition-all duration-300 lg:pl-[4.5rem]"
         :class="sidebarExpanded ? 'lg:pl-64' : 'lg:pl-[4.5rem]'">
        @include('layouts.topbar', compact('userData', 'empresas', 'empresaAtual', 'operadoras'))

        @isset($header)
            <div class="bg-white border-b border-gray-200 px-4 sm:px-6 py-4">
                <div class="max-w-7xl mx-auto">{{ $header }}</div>
            </div>
        @endisset

        <main class="flex-1">
            @hasSection('content')
                @yield('content')
            @else
                {{ $slot ?? '' }}
            @endif
        </main>
    </div>
</div>
