<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\MenuTrait;
use App\Models\Empresa;
use App\Models\EmpresasOperadora;
use App\Services\OperadoraContext;

class MenuController extends Controller
{
    use MenuTrait;

    public static function getMenuData()
    {
        $instance = new self();

        $empresas = collect();
        $empresaAtual = null;
        $operadoras = collect();

        if (auth()->check()) {
            $empresas = Empresa::orderBy('nome')->get();
            $empresaAtual = $empresas->firstWhere('id', session('empresa_selecionada_id'));

            if (auth()->user()->isSuperAdmin()) {
                $operadoras = EmpresasOperadora::where('ativo', true)
                    ->orderBy('nome_fantasia')
                    ->orderBy('razao_social')
                    ->get();
            }
        }

        return [
            'menuItems' => $instance->getMenuOptions(),
            'userData' => $instance->getUserData(),
            'empresas' => $empresas,
            'empresaAtual' => $empresaAtual,
            'operadoras' => $operadoras,
            'operadoraAtual' => OperadoraContext::operadoraAtual(),
        ];
    }
}
