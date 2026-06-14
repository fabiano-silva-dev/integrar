{{-- Sidebar colapsável --}}
<aside
    class="fixed inset-y-0 left-0 z-40 flex h-screen flex-col bg-gray-900 text-white shadow-xl transition-[width,transform] duration-300 ease-in-out lg:translate-x-0"
    style="background-color: #111827;"
    :class="{
        'w-64': sidebarExpanded,
        'w-[4.5rem]': !sidebarExpanded,
        '-translate-x-full': !sidebarMobileOpen,
        'translate-x-0': sidebarMobileOpen
    }"
    @keydown.escape.window="sidebarMobileOpen = false"
>
    <div class="flex h-16 shrink-0 items-center border-b border-gray-700"
         :class="sidebarExpanded ? 'justify-between px-4' : 'justify-center px-2'">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-2 overflow-hidden" x-show="sidebarExpanded" @click="fecharMenu()">
            @if(($operadoraAtual ?? null)?->logo)
                <img src="{{ Storage::url($operadoraAtual->logo) }}" alt="" class="h-8 w-8 shrink-0 rounded object-cover">
            @else
                <img src="{{ asset('images/brand/icon.png') }}" srcset="{{ asset('images/brand/icon@2x.png') }} 2x, {{ asset('images/brand/icon@3x.png') }} 3x" alt="IntegraExpert" class="h-8 w-8 shrink-0">
            @endif
            <span class="truncate text-sm font-semibold">{{ ($operadoraAtual ?? null)?->nome_fantasia ?: 'IntegraExpert' }}</span>
        </a>
        <a href="{{ route('home') }}" class="flex shrink-0 items-center justify-center" x-show="!sidebarExpanded" @click="fecharMenu()" title="IntegraExpert">
            @if(($operadoraAtual ?? null)?->logo)
                <img src="{{ Storage::url($operadoraAtual->logo) }}" alt="" class="h-8 w-8 rounded object-cover">
            @else
                <img src="{{ asset('images/brand/icon.png') }}" srcset="{{ asset('images/brand/icon@2x.png') }} 2x, {{ asset('images/brand/icon@3x.png') }} 3x" alt="IntegraExpert" class="h-8 w-8">
            @endif
        </a>
        <button type="button"
                x-show="sidebarExpanded"
                @click="sidebarExpanded = !sidebarExpanded; localStorage.setItem('sidebarExpanded', sidebarExpanded ? '1' : '0')"
                class="hidden h-10 w-10 items-center justify-center rounded-lg text-gray-300 hover:bg-gray-800 hover:text-white lg:flex"
                :title="sidebarExpanded ? 'Recolher menu' : 'Expandir menu'">
            <svg class="h-5 w-5 transition-transform" :class="sidebarExpanded ? '' : 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
            </svg>
        </button>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto overflow-x-hidden px-2 py-4"
         @click="if ($event.target.closest('a[href]')) fecharMenu()">
        <a href="{{ route('home') }}"
           class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium {{ request()->routeIs('home') ? 'bg-indigo-600 text-white' : 'text-gray-200 hover:bg-gray-800 hover:text-white' }}"
           title="Início">
            <x-menu-icon name="home" class="h-6 w-6 shrink-0" />
            <span class="truncate" x-show="sidebarExpanded">Início</span>
        </a>

        @foreach($menuItems as $menuIndex => $menu)
            @php
                $groupActive = collect($menu['items'])->contains(fn ($i) => $i['active'] ?? false);
            @endphp
            <div>
                <button type="button"
                        @click="if (sidebarExpanded) { menuOpen[{{ $menuIndex }}] = !menuOpen[{{ $menuIndex }}] } else { sidebarExpanded = true; menuOpen[{{ $menuIndex }}] = true; localStorage.setItem('sidebarExpanded', '1'); }"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium {{ $groupActive ? 'bg-gray-800 text-white' : 'text-gray-200 hover:bg-gray-800 hover:text-white' }}"
                        title="{{ $menu['name'] }}">
                    <x-menu-icon :name="$menu['icon']" class="h-6 w-6 shrink-0" />
                    <span class="flex-1 truncate text-left" x-show="sidebarExpanded">{{ $menu['name'] }}</span>
                    <svg x-show="sidebarExpanded" class="h-4 w-4 shrink-0 transition-transform" :class="menuOpen[{{ $menuIndex }}] ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="sidebarExpanded && menuOpen[{{ $menuIndex }}]" class="ml-3 mt-0.5 space-y-0.5 border-l border-gray-700 pl-3">
                    @foreach($menu['items'] as $item)
                        @if($item['disabled'] ?? false)
                            <span class="flex cursor-not-allowed items-center gap-2 rounded-lg px-2 py-2 text-xs text-gray-500">
                                <x-menu-icon :name="$item['icon'] ?? 'document'" class="h-4 w-4 shrink-0 opacity-50" />
                                <span class="truncate">{{ $item['name'] }}</span>
                            </span>
                        @else
                            <a href="{{ $item['url'] }}"
                               {{ isset($item['title']) ? 'title="' . e($item['title']) . '"' : '' }}
                               class="flex items-center gap-2 rounded-lg px-2 py-2 text-sm {{ ($item['active'] ?? false) ? 'bg-indigo-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' }}">
                                <x-menu-icon :name="$item['icon'] ?? 'document'" class="h-4 w-4 shrink-0" />
                                <span class="truncate">{{ $item['name'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="shrink-0 border-t border-gray-700 p-3">
        <div class="flex items-center gap-3 rounded-lg px-2 py-2" :class="sidebarExpanded ? '' : 'justify-center'">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold">{{ $userData['initial'] }}</span>
            <div class="min-w-0 flex-1" x-show="sidebarExpanded">
                <p class="truncate text-sm font-medium text-white">{{ $userData['name'] }}</p>
                <a href="{{ route('profile.edit') }}" class="text-xs text-gray-400 hover:text-white" @click="fecharMenu()">Configurações</a>
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}" x-show="sidebarExpanded" class="mt-1 px-2">
            @csrf
            <button type="submit" class="text-xs text-gray-400 hover:text-white">Sair</button>
        </form>
    </div>
</aside>

<div x-show="sidebarMobileOpen" x-transition.opacity
     @click="sidebarMobileOpen = false"
     class="fixed inset-0 z-30 bg-black/50 lg:hidden"></div>
