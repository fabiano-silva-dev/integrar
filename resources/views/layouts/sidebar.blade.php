{{-- Sidebar rail (padrão EfiConnect): ícone acima do label; labels no hover do rail --}}
<aside
    class="group/sidebar fixed inset-y-0 left-0 z-40 flex h-screen w-24 flex-col border-r border-gray-700 bg-gray-900 text-white shadow-xl transition-transform duration-300 ease-in-out lg:translate-x-0"
    style="background-color: #111827;"
    :class="{
        '-translate-x-full': !sidebarMobileOpen,
        'translate-x-0': sidebarMobileOpen
    }"
    @click.stop
    @keydown.escape.window="fecharMenu()"
>
    {{-- Cabeçalho: logo --}}
    <div class="flex h-16 shrink-0 items-center justify-center border-b border-gray-700 px-1.5">
        <a href="{{ route('home') }}"
           class="flex h-10 w-10 items-center justify-center rounded-lg hover:bg-gray-800"
           title="{{ ($operadoraAtual ?? null)?->nome_fantasia ?: 'IntegraExpert' }}"
           @click="fecharMenu()">
            @if(($operadoraAtual ?? null)?->logo)
                <img src="{{ Storage::url($operadoraAtual->logo) }}" alt="" class="h-8 w-8 rounded object-cover">
            @else
                <img src="{{ asset('images/brand/icon.png') }}" srcset="{{ asset('images/brand/icon@2x.png') }} 2x, {{ asset('images/brand/icon@3x.png') }} 3x" alt="IntegraExpert" class="h-8 w-8">
            @endif
        </a>
    </div>

    <nav class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overflow-x-hidden px-1.5 py-3"
         @click="if ($event.target.closest('a[href]')) fecharMenu()">
        {{-- Início --}}
        <a href="{{ route('home') }}"
           aria-label="Início"
           title="Início"
           class="flex flex-col items-center justify-center gap-0 rounded-lg px-1 py-2 text-sm font-medium transition-colors {{ request()->routeIs('home') ? 'bg-indigo-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' }}">
            <span class="relative flex shrink-0 items-center justify-center">
                <x-menu-icon name="home" class="h-6 w-6 shrink-0" />
            </span>
            <span class="mt-0.5 min-h-[2rem] w-full px-0.5 text-center text-[10px] font-medium leading-tight opacity-0 transition-opacity duration-150 group-hover/sidebar:opacity-100 group-focus-within/sidebar:opacity-100"
                  aria-hidden="true">Início</span>
        </a>

        @foreach($menuItems as $menuIndex => $menu)
            @php
                $groupActive = collect($menu['items'])->contains(fn ($i) => $i['active'] ?? false);
            @endphp
            <div class="relative"
                 x-data="{ open: false }"
                 @click.outside="open = false"
                 @keydown.escape.window="open = false">
                <button type="button"
                        @click="open = !open"
                        aria-label="{{ $menu['name'] }}"
                        :aria-expanded="open.toString()"
                        title="{{ $menu['name'] }}"
                        class="flex w-full flex-col items-center justify-center gap-0 rounded-lg px-1 py-2 text-sm font-medium transition-colors outline-none {{ $groupActive ? 'bg-gray-800 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' }}"
                        :class="open ? 'bg-gray-800 text-white' : ''">
                    <span class="relative flex shrink-0 items-center justify-center">
                        <x-menu-icon :name="$menu['icon']" class="h-6 w-6 shrink-0" />
                    </span>
                    <span class="mt-0.5 min-h-[2rem] w-full px-0.5 text-center text-[10px] font-medium leading-tight opacity-0 transition-opacity duration-150 group-hover/sidebar:opacity-100 group-focus-within/sidebar:opacity-100"
                          aria-hidden="true">{{ $menu['name'] }}</span>
                </button>

                <div x-show="open"
                     x-transition.opacity.duration.150ms
                     x-cloak
                     class="absolute left-full top-0 z-50 ml-2 min-w-44 rounded-lg border border-gray-700 bg-gray-900 py-1 shadow-xl"
                     style="background-color: #111827;"
                     @click.stop>
                    <div class="border-b border-gray-700 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
                        {{ $menu['name'] }}
                    </div>
                    @foreach($menu['items'] as $item)
                        @if($item['disabled'] ?? false)
                            <span class="flex cursor-not-allowed items-center gap-2 px-3 py-2 text-sm text-gray-500"
                                  title="{{ $item['title'] ?? $item['name'] }}">
                                <x-menu-icon :name="$item['icon'] ?? 'document'" class="h-4 w-4 shrink-0 opacity-50" />
                                <span class="truncate">{{ $item['name'] }}</span>
                            </span>
                        @else
                            <a href="{{ $item['url'] }}"
                               {{ isset($item['title']) ? 'title="' . e($item['title']) . '"' : '' }}
                               class="flex items-center gap-2 px-3 py-2 text-sm transition-colors {{ ($item['active'] ?? false) ? 'bg-indigo-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' }}"
                               @click="open = false; fecharMenu()">
                                <x-menu-icon :name="$item['icon'] ?? 'document'" class="h-4 w-4 shrink-0" />
                                <span class="truncate">{{ $item['name'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="shrink-0 border-t border-gray-700 px-1.5 py-3">
        <a href="{{ route('profile.edit') }}"
           aria-label="Configurações"
           title="Configurações"
           class="flex flex-col items-center justify-center gap-0 rounded-lg px-1 py-2 text-sm font-medium text-gray-300 transition-colors hover:bg-gray-800 hover:text-white"
           @click="fecharMenu()">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold text-white">{{ $userData['initial'] }}</span>
            <span class="mt-0.5 min-h-[2rem] w-full px-0.5 text-center text-[10px] font-medium leading-tight opacity-0 transition-opacity duration-150 group-hover/sidebar:opacity-100 group-focus-within/sidebar:opacity-100"
                  aria-hidden="true">{{ $userData['name'] }}</span>
        </a>
    </div>
</aside>

<div x-show="sidebarMobileOpen" x-transition.opacity
     @click="fecharMenu()"
     class="fixed inset-0 z-30 bg-black/50 lg:hidden"
     x-cloak></div>
