<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\Auth;

trait MenuTrait
{
    private function podeAcessarItemMenu(array $item, string $role): bool
    {
        if (!isset($item['roles']) || !is_array($item['roles']) || empty($item['roles'])) {
            return true;
        }

        if ($role === 'super_admin') {
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
                'name' => 'Cadastros',
                'icon' => 'folder',
                'items' => [
                    [
                        'name' => 'Empresas',
                        'icon' => 'building',
                        'url' => route('empresas'),
                        'active' => request()->routeIs('empresas*'),
                    ],
                    [
                        'name' => 'Usuários',
                        'icon' => 'users',
                        'url' => route('usuarios'),
                        'active' => request()->routeIs('usuarios*'),
                        'roles' => ['admin', 'gerente'],
                    ],
                    [
                        'name' => 'Plano de Contas',
                        'icon' => 'document',
                        'url' => route('plano-contas'),
                        'active' => request()->routeIs('plano-contas*'),
                    ],
                ],
            ],
            [
                'id' => 'importacao',
                'name' => 'Importação',
                'icon' => 'import',
                'items' => [
                    [
                        'name' => 'Importação de Extratos',
                        'icon' => 'document',
                        'url' => route('importador-avancado'),
                        'active' => request()->routeIs('importador-avancado*'),
                    ],
                    [
                        'name' => 'Importação Personalizada',
                        'icon' => 'sliders',
                        'url' => route('importador-personalizado'),
                        'active' => request()->routeIs('importador-personalizado*'),
                        'title' => 'Importação personalizada de CSV, XLS ou XLSX',
                    ],
                    [
                        'name' => 'Importações anteriores',
                        'icon' => 'clock',
                        'url' => route('importacoes'),
                        'active' => request()->routeIs('importacoes*'),
                    ],
                ],
            ],
            [
                'id' => 'conversao',
                'name' => 'Conversão',
                'icon' => 'convert',
                'items' => [
                    [
                        'name' => 'PDF para OFX',
                        'icon' => 'document',
                        'url' => route('conversao-pdf-ofx'),
                        'active' => request()->routeIs('conversao-pdf-ofx'),
                        'title' => 'Converte extrato PDF para arquivo OFX',
                    ],
                    [
                        'name' => 'Histórico de conversões',
                        'icon' => 'clock',
                        'url' => route('conversoes-extrato'),
                        'active' => request()->routeIs('conversoes-extrato*'),
                        'title' => 'Consulta conversões PDF para OFX realizadas',
                    ],
                ],
            ],
            [
                'id' => 'lancamentos',
                'name' => 'Lançamentos',
                'icon' => 'table',
                'items' => [
                    [
                        'name' => 'Tabela de lançamentos',
                        'icon' => 'table',
                        'url' => route('tabela'),
                        'active' => request()->routeIs('tabela*'),
                    ],
                ],
            ],
            [
                'id' => 'regras-amarracao',
                'name' => 'Regras de Amarração',
                'icon' => 'link',
                'items' => [
                    [
                        'name' => 'Descrições',
                        'icon' => 'document',
                        'url' => route('regras-amarracao'),
                        'active' => request()->routeIs('regras-amarracao*'),
                        'title' => 'Regras de amarração por descrição do extrato',
                    ],
                    [
                        'name' => 'Terceiros',
                        'icon' => 'user-group',
                        'url' => route('terceiros'),
                        'active' => request()->routeIs('terceiros*'),
                    ],
                ],
            ],
            [
                'id' => 'automacao-fiscal',
                'name' => 'Automação Fiscal',
                'icon' => 'document',
                'items' => [
                    [
                        'name' => 'Painel',
                        'icon' => 'table',
                        'url' => route('automacao-fiscal.painel'),
                        'active' => request()->routeIs('automacao-fiscal.painel'),
                    ],
                    [
                        'name' => 'Executar consulta',
                        'icon' => 'document',
                        'url' => route('automacao-fiscal.executar'),
                        'active' => request()->routeIs('automacao-fiscal.executar') || request()->routeIs('automacao-fiscal.execucao'),
                        'title' => 'Salvar parâmetros e acompanhar processamento da automação',
                        'roles' => ['admin'],
                    ],
                    [
                        'name' => 'Análises fiscais',
                        'icon' => 'document',
                        'url' => route('automacao-fiscal.analises'),
                        'active' => request()->routeIs('automacao-fiscal.analises') || request()->routeIs('automacao-fiscal.analise'),
                        'title' => 'Análises por empresa, portal e competência',
                    ],

                ],
            ],
            [
                'id' => 'exportacao',
                'name' => 'Exportação',
                'icon' => 'export',
                'items' => [
                    [
                        'name' => 'Exportador',
                        'icon' => 'export',
                        'url' => route('exportador'),
                        'active' => request()->routeIs('exportador*'),
                    ],
                ],
            ],
            [
                'id' => 'administracao',
                'name' => 'Administração',
                'icon' => 'cog',
                'items' => [
                    [
                        'name' => 'Escritórios',
                        'icon' => 'building',
                        'url' => route('empresas-operadoras'),
                        'active' => request()->routeIs('empresas-operadoras*'),
                        'roles' => ['super_admin'],
                    ],
                    [
                        'name' => 'Históricos padrão por layout',
                        'icon' => 'document',
                        'url' => route('historicos-padrao-layout'),
                        'active' => request()->routeIs('historicos-padrao-layout*'),
                        'roles' => ['admin'],
                    ],
                    [
                        'name' => 'Configurações',
                        'url' => route('configuracoes.automacao-fiscal'),
                        'icon' => 'cog',
                        'active' => request()->routeIs('configuracoes.*'),
                        'roles' => ['admin', 'gerente'],
                    ],
                    [
                        'name' => 'Logs',
                        'url' => '#',
                        'icon' => 'document',
                        'active' => false,
                        'class' => 'text-gray-400 cursor-not-allowed',
                        'disabled' => true,
                        'note' => 'Em breve',
                    ],
                    [
                        'name' => 'Acessos',
                        'url' => '#',
                        'icon' => 'users',
                        'active' => false,
                        'class' => 'text-gray-400 cursor-not-allowed',
                        'disabled' => true,
                        'note' => 'Em breve',
                    ],
                ],
            ],
        ];

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
