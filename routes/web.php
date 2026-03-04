<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

// Redirecionar '/' para home
Route::get('/', function () {
    return redirect()->route('home');
});

// Rotas protegidas por autenticação
Route::middleware(['auth'])->group(function () {
    Route::get('/usuarios', App\Livewire\GerenciadorUsuarios::class)->name('usuarios');
    Route::get('/importador', function () {
        return redirect()->route('importador-avancado');
    });
    Route::get('/importador-avancado', App\Livewire\ImportadorAvancado::class)->name('importador-avancado');
    Route::get('/importador-personalizado', App\Livewire\ImportadorPersonalizado::class)->name('importador-personalizado');
    Route::get('/tabela', App\Livewire\TabelaLancamentos::class)->name('tabela');
    Route::get('/empresas', App\Livewire\GerenciadorEmpresas::class)->name('empresas');
    Route::get('/terceiros', App\Livewire\GerenciadorTerceiros::class)->name('terceiros');
    Route::get('/amarracoes', fn () => redirect()->route('regras-amarracao'))->name('amarracoes');
    Route::get('/regras-amarracao', App\Livewire\GerenciadorRegrasAmarracao::class)->name('regras-amarracao');
    Route::get('/importacoes', App\Livewire\ListaImportacoes::class)->name('importacoes');
    Route::get('/exportador', App\Livewire\ExportadorContabil::class)->name('exportador');
    Route::get('/extrator-bancario', App\Livewire\ExtratorBancario::class)->name('extrator-bancario');
    Route::get('/home', App\Livewire\Home::class)->name('home');

    // Rota de exemplo para navegação Vue


    // CRUD Empresas Operadoras (apenas admin)
    Route::get('/empresas-operadoras', App\Livewire\EmpresasOperadorasForm::class)->name('empresas-operadoras');

    // Históricos padrão por layout (apenas admin)
    Route::get('/historicos-padrao-layout', App\Livewire\GerenciadorHistoricosPadraoLayout::class)->name('historicos-padrao-layout');

    // Trocar empresa no seletor global (recarrega a página para vigorar nos campos)
    Route::get('/trocar-empresa/{id}', function (string $id) {
        $empresa = \App\Models\Empresa::find($id);
        if ($empresa) {
            session(['empresa_selecionada_id' => (int) $id]);
            session()->save();
        }
        return redirect()->to(request()->query('redirect', url()->previous()));
    })->name('trocar-empresa');
});

// Rotas de download protegidas por autenticação
Route::middleware(['auth'])->group(function () {
    Route::get('/download/{arquivo}', function ($arquivo) {
        // Validar nome do arquivo para prevenir path traversal
        $arquivo = basename($arquivo);
        
        $path = storage_path("app/exports/{$arquivo}");
        
        if (!file_exists($path)) {
            abort(404, 'Arquivo não encontrado');
        }
        
        return response()->download($path);
    })->name('download.arquivo');

    Route::get('/download-arquivo/{arquivo}', function ($arquivo) {
        // Validar nome do arquivo para prevenir path traversal
        $arquivo = basename($arquivo);
        
        $path = storage_path("app/exports/{$arquivo}");
        
        if (!file_exists($path)) {
            abort(404, 'Arquivo não encontrado');
        }
        
        return response()->download($path);
    })->name('download.arquivo.api');
});



Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// Fallback: redirecionar rotas inexistentes para home (apenas em produção)
if (app()->environment('production')) {
    Route::fallback(function () {
        return redirect()->route('home');
    });
}
