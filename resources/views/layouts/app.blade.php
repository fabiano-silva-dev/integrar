<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>IntegraExpert</title>

        <x-favicons />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        @php
            $menuData = \App\Http\Controllers\MenuController::getMenuData();
            extract($menuData);
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
            <div class="flex min-h-screen flex-col transition-all duration-300 lg:pl-[4.5rem]" :class="sidebarExpanded ? 'lg:pl-64' : 'lg:pl-[4.5rem]'">
                @include('layouts.topbar', compact('userData', 'empresas', 'empresaAtual', 'operadoras'))
                <main class="flex-1">@yield('content')</main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
