<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

trait MenuTrait
{
    public function getMenuOptions()
    {
        $user = Auth::user();
        
        $menuItems = [
            [
                'id' => 'cadastros',
                'name' => '📋 Cadastros',
                'icon' => 'fa-database',
                'items' => [
                    [
                        'name' => '🏢 Empresas',
                        'url' => route('empresas'),
                        'active' => request()->routeIs('empresas*'),
                    ],
                    [
                        'name' => '👥 Usuários',
                        'url' => route('usuarios'),
                        'active' => request()->routeIs('usuarios*'),
                    ],
                    [
                        'name' => '🤝 Terceiros',
                        'url' => route('terceiros'),
                        'active' => request()->routeIs('terceiros*'),
                    ],
                ],
            ],
            [
                'id' => 'importacao',
                'name' => '📥 Importação',
                'icon' => 'fa-upload',
                'items' => [
                    [
                        'name' => '📄 Importação de Extratos',
                        'url' => route('importador-avancado'),
                        'active' => request()->routeIs('importador-avancado*'),
                    ],
                    [
                        'name' => '🎯 Importação Personalizada',
                        'url' => route('importador-personalizado'),
                        'active' => request()->routeIs('importador-personalizado*'),
                        'title' => 'Importação personalizada de CSV, XLS ou XLSX',
                    ],
                    [
                        'name' => '🕑 Importações anteriores',
                        'url' => route('importacoes'),
                        'active' => request()->routeIs('importacoes*'),
                    ],
                ],
            ],
            [
                'id' => 'lancamentos',
                'name' => '📊 Lançamentos',
                'icon' => 'fa-chart-bar',
                'items' => [
                    [
                        'name' => '📋 Tabela de lançamentos',
                        'url' => route('tabela'),
                        'active' => request()->routeIs('tabela*'),
                    ],
                    [
                        'name' => '⚙️ Regras de Amarração',
                        'url' => route('regras-amarracao'),
                        'active' => request()->routeIs('regras-amarracao*'),
                    ],
                ],
            ],
            [
                'id' => 'exportacao',
                'name' => '📤 Exportação',
                'icon' => 'fa-download',
                'items' => [
                    [
                        'name' => '📤 Exportador',
                        'url' => route('exportador'),
                        'active' => request()->routeIs('exportador*'),
                    ],
                ],
            ],
            [
                'id' => 'administracao',
                'name' => '⚙️ Administração',
                'icon' => 'fa-cog',
                'items' => [
                    [
                        'name' => '📋 Históricos padrão por layout',
                        'url' => route('historicos-padrao-layout'),
                        'active' => request()->routeIs('historicos-padrao-layout*'),
                    ],
                    [
                        'name' => '🛠️ Configurações',
                        'url' => '#',
                        'active' => false,
                        'class' => 'text-gray-400 cursor-not-allowed',
                        'disabled' => true,
                        'note' => '(em breve)',
                    ],
                    [
                        'name' => '📜 Logs',
                        'url' => '#',
                        'active' => false,
                        'class' => 'text-gray-400 cursor-not-allowed',
                        'disabled' => true,
                        'note' => '(em breve)',
                    ],
                    [
                        'name' => '🔑 Acessos',
                        'url' => '#',
                        'active' => false,
                        'class' => 'text-gray-400 cursor-not-allowed',
                        'disabled' => true,
                        'note' => '(em breve)',
                    ],
                ],
            ],
        ];

        // Filtrar itens baseado no papel do usuário
        if ($user->role === 'operador') {
            // Remover itens administrativos para operadores
            $menuItems = array_filter($menuItems, function($menu) {
                return !in_array($menu['id'], ['administracao']);
            });
        }

        // Históricos padrão por layout: apenas admin
        if ($user->role !== 'admin') {
            $menuItems = array_map(function($menu) {
                if (($menu['id'] ?? '') === 'administracao' && !empty($menu['items'])) {
                    $menu['items'] = array_values(array_filter($menu['items'], function($item) {
                        return ($item['url'] ?? '') !== route('historicos-padrao-layout');
                    }));
                }
                return $menu;
            }, $menuItems);
        }

        return $menuItems;
    }

    public function getUserData()
    {
        $user = Auth::user();
        
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'initial' => strtoupper(substr($user->name, 0, 1)),
        ];
    }
} 