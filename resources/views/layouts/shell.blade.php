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
        sidebarMobileOpen: false,
        fecharMenu() {
            this.sidebarMobileOpen = false;
        },
        toggleSidebar() {
            this.sidebarMobileOpen = !this.sidebarMobileOpen;
        }
    }"
    class="min-h-screen bg-gray-100"
>
    @include('layouts.sidebar', compact('menuItems', 'userData', 'operadoraAtual'))

    <div class="flex min-h-screen flex-col lg:pl-24">
        <div class="sticky top-0 z-20">
            @include('layouts.topbar', compact('userData', 'empresas', 'empresaAtual', 'operadoras'))
            @include('partials.aviso-servicos-desenvolvimento')
        </div>

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
