<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

trait MenuTrait
{
    private function podeAcessarItemMenu(array $item, string $role): bool
    {
        if (!isset($item['roles']) || !is_array($item['roles']) || empty($item['roles'])) {
            return true;
        }

        return in_array($role, $item['roles'], true);
    }

    public function getMenuOptions()
    {
        $user = Auth::user();
        $role = $user->role ?? 'operador';
        
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
                        'roles' => ['admin', 'gerente'],
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
                'id' => 'conversao',
                'name' => '🔄 Conversão',
                'icon' => 'fa-exchange-alt',
                'items' => [
                    [
                        'name' => '📄 PDF para OFX (Sicoob)',
                        'url' => route('conversao-pdf-ofx-sicoob'),
                        'active' => request()->routeIs('conversao-pdf-ofx-sicoob*'),
                        'title' => 'Converte extrato PDF do Sicoob para arquivo OFX',
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
                        'roles' => ['admin'],
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

        // Filtra links sem acesso e remove menus vazios.
        $menuItems = array_values(array_filter(array_map(function ($menu) use ($role) {
            $items = $menu['items'] ?? [];
            $menu['items'] = array_values(array_filter($items, function ($item) use ($role) {
                return $this->podeAcessarItemMenu($item, $role);
            }));

            return !empty($menu['items']) ? $menu : null;
        }, $menuItems)));

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