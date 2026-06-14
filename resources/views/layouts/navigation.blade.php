@php
    $menuData = \App\Http\Controllers\MenuController::getMenuData();
    $menuItems = $menuData['menuItems'];
    $userData = $menuData['userData'];
    $empresas = $menuData['empresas'];
    $empresaAtual = $menuData['empresaAtual'];
    $operadoras = $menuData['operadoras'];
    $operadoraAtual = $menuData['operadoraAtual'];
@endphp
@include('layouts.menu-blade', [
    'menuItems' => $menuItems,
    'userData' => $userData,
    'empresas' => $empresas,
    'empresaAtual' => $empresaAtual,
    'operadoras' => $operadoras,
    'operadoraAtual' => $operadoraAtual,
])
