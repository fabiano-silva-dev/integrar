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
    Route::get('/conversao/pdf-ofx', App\Livewire\ConversorPdfOfx::class)->name('conversao-pdf-ofx');
    Route::get('/conversao/historico', App\Livewire\ListaConversoesExtrato::class)->name('conversoes-extrato');
    Route::redirect('/conversao/pdf-ofx-sicoob', '/conversao/pdf-ofx');
    Route::get('/tabela', App\Livewire\TabelaLancamentos::class)->name('tabela');
    Route::get('/lancamentos/ajustes-massa', App\Livewire\AjustesLancamentosMassa::class)->name('lancamentos.ajustes-massa');
    Route::get('/empresas', App\Livewire\GerenciadorEmpresas::class)->name('empresas');
    Route::get('/empresas/importar', App\Livewire\AutomacaoFiscal\ImportadorEmpresas::class)->name('empresas.importar');
    Route::get('/configuracoes/automacao-fiscal/{aba?}', App\Livewire\AutomacaoFiscal\ConfiguracoesAutomacaoFiscal::class)
        ->name('configuracoes.automacao-fiscal');
    Route::get('/automacao-fiscal', App\Livewire\AutomacaoFiscal\PainelAutomacaoFiscal::class)
        ->name('automacao-fiscal.painel');
    Route::get('/automacao-fiscal/executar', App\Livewire\AutomacaoFiscal\ExecutarConsultaFiscal::class)
        ->name('automacao-fiscal.executar');
    Route::get('/automacao-fiscal/avulsas', App\Livewire\AutomacaoFiscal\ExecutarConsultaAvulsa::class)
        ->name('automacao-fiscal.avulsas');
    Route::get('/automacao-fiscal/avulsas/{tipo}', App\Livewire\AutomacaoFiscal\ExecutarConsultaAvulsa::class)
        ->where('tipo', '[a-z0-9_]+')
        ->name('automacao-fiscal.avulsa');
    Route::get('/automacao-fiscal/execucoes/{execucao}', App\Livewire\AutomacaoFiscal\ExecutarConsultaFiscal::class)
        ->name('automacao-fiscal.execucao');
    Route::get('/automacao-fiscal/artefatos/{artefato}', App\Http\Controllers\AutomacaoFiscal\AutomacaoArtefatoController::class)
        ->name('automacao-fiscal.artefato');
    Route::get('/automacao-fiscal/documentos/{documento}/xml', App\Http\Controllers\AutomacaoFiscal\DocumentoFiscalXmlController::class)
        ->whereNumber('documento')
        ->name('automacao-fiscal.documento.xml');
    Route::get('/automacao-fiscal/xml-download/{token}', App\Http\Controllers\AutomacaoFiscal\DocumentoFiscalXmlArquivoController::class)
        ->where('token', '[0-9a-fA-F-]{36}')
        ->name('automacao-fiscal.documento.xml.arquivo');
    Route::get('/automacao-fiscal/xml-download/{token}/danfe', App\Http\Controllers\AutomacaoFiscal\DocumentoFiscalXmlDanfeController::class)
        ->where('token', '[0-9a-fA-F-]{36}')
        ->name('automacao-fiscal.documento.xml.danfe');
    Route::get('/automacao-fiscal/analises', App\Livewire\AutomacaoFiscal\ResumoFiscalDocumentos::class)
        ->name('automacao-fiscal.analises');
    Route::get('/automacao-fiscal/analises/{empresa}/{portal}/{competencia}', App\Livewire\AutomacaoFiscal\ResumoFiscalDocumentos::class)
        ->where(['empresa' => '[0-9]+', 'portal' => '[0-9]+', 'competencia' => '[0-9]{4}-[0-9]{2}'])
        ->name('automacao-fiscal.analise');
    Route::get('/automacao-fiscal/analises/coleta/{coleta}', function (int $coleta) {
        $resolvido = app(\App\Services\AutomacaoFiscal\AnaliseFiscalService::class)->resolverDeColeta($coleta);
        if (! $resolvido) {
            return redirect()->route('automacao-fiscal.analises')
                ->with('message', 'Não foi possível localizar a análise desta coleta.');
        }

        return redirect()->route('automacao-fiscal.analise', [
            'empresa' => $resolvido['empresa_id'],
            'portal' => $resolvido['portal_id'],
            'competencia' => $resolvido['competencia'],
        ]);
    })->whereNumber('coleta')->name('automacao-fiscal.analises.coleta');
    Route::get('/automacao-fiscal/resumo-nfe/{coleta?}', function (?int $coleta = null) {
        if ($coleta) {
            return redirect()->route('automacao-fiscal.analises.coleta', $coleta);
        }

        return redirect()->route('automacao-fiscal.analises');
    });

    Route::get('/plano-contas', App\Livewire\GerenciadorPlanoContas::class)->name('plano-contas');
    Route::get('/plano-contas/importar', App\Livewire\ImportadorPlanoContas::class)->name('plano-contas.importar');
    Route::get('/terceiros', App\Livewire\GerenciadorTerceiros::class)->name('terceiros');
    Route::get('/amarracoes', fn () => redirect()->route('regras-amarracao'))->name('amarracoes');
    Route::get('/regras-amarracao', App\Livewire\GerenciadorRegrasAmarracao::class)->name('regras-amarracao');
    Route::get('/regras-amarracao/importar', App\Livewire\ImportadorRegrasAmarracao::class)->name('regras-amarracao.importar');
    Route::get('/importacoes', App\Livewire\ListaImportacoes::class)->name('importacoes');
    Route::get('/exportador', App\Livewire\ExportadorContabil::class)->name('exportador');
    Route::get('/extrator-bancario', App\Livewire\ExtratorBancario::class)->name('extrator-bancario');
    Route::get('/home', App\Livewire\Home::class)->name('home');

    // Rota de exemplo para navegação Vue


    // CRUD Empresas Operadoras (apenas super admin)
    Route::get('/empresas-operadoras', App\Livewire\EmpresasOperadorasForm::class)
        ->middleware('role:super_admin')
        ->name('empresas-operadoras');

    // Históricos padrão por layout (apenas admin)
    Route::get('/historicos-padrao-layout', App\Livewire\GerenciadorHistoricosPadraoLayout::class)->name('historicos-padrao-layout');

    Route::get('/documentos', App\Livewire\Documentos\ExploradorDocumentos::class)->name('documentos');

    Route::middleware('role:admin,gerente')->group(function () {
        Route::redirect('/documentos/whatsapp', '/configuracoes/documentos/whatsapp');
        Route::redirect('/documentos/grupos', '/configuracoes/documentos/grupos');
        Route::redirect('/documentos/drive', '/configuracoes/documentos/drive');
        Route::redirect('/documentos/ia', '/configuracoes/documentos/ia');
        Route::redirect('/documentos/recebidos', '/configuracoes/documentos/recebidos');
        Route::redirect('/documentos/log', '/configuracoes/documentos/log');
        Route::get('/configuracoes/documentos/whatsapp', App\Livewire\Documentos\ConexaoWhatsapp::class)->name('documentos.whatsapp');
        Route::get('/configuracoes/documentos/grupos', App\Livewire\Documentos\GruposWhatsapp::class)->name('documentos.grupos');
        Route::get('/configuracoes/documentos/drive', App\Livewire\Documentos\ContaGoogleDrive::class)->name('documentos.drive');
        Route::get('/configuracoes/documentos/ia', App\Livewire\Documentos\ConfiguracaoIaDocumentos::class)->name('documentos.ia');
        Route::get('/configuracoes/documentos/recebidos', App\Livewire\Documentos\DocumentosRecebidos::class)->name('documentos.recebidos');
        Route::get('/configuracoes/documentos/log', App\Livewire\Documentos\DocumentosProcessoLog::class)->name('documentos.log');
        Route::get('/oauth/google/redirect', [App\Http\Controllers\OAuth\GoogleOAuthController::class, 'redirect'])->name('oauth.google.redirect');
        Route::get('/oauth/google/callback', [App\Http\Controllers\OAuth\GoogleOAuthController::class, 'callback'])->name('oauth.google.callback');
    });

    // Trocar empresa no seletor global (recarrega a página para vigorar nos campos)
    Route::get('/trocar-empresa/{id}', function (string $id) {
        $empresa = \App\Models\Empresa::find($id);
        if (!$empresa) {
            abort(404, 'Empresa não encontrada.');
        }
        session(['empresa_selecionada_id' => (int) $id]);
        session()->save();
        return redirect()->to(request()->query('redirect', url()->previous()));
    })->name('trocar-empresa');

    // Trocar escritório (super admin)
    Route::get('/trocar-operadora/{id?}', function (?string $id = null) {
        if (!$id || $id === '0') {
            \App\Services\OperadoraContext::clear();
        } else {
            $operadora = \App\Models\EmpresasOperadora::find($id);
            if (!$operadora) {
                abort(404, 'Escritório não encontrado.');
            }
            \App\Services\OperadoraContext::set((int) $id);
        }
        session()->save();
        return redirect()->to(request()->query('redirect', url()->previous()));
    })->name('trocar-operadora');
});

// Rotas de download protegidas por autenticação
Route::middleware(['auth'])->group(function () {
    Route::get('/download/{arquivo}', function ($arquivo) {
        $arquivo = basename($arquivo);
        $path = \App\Services\OperadoraStorage::resolveAbsolutePath('exports', $arquivo);

        if (!$path) {
            abort(404, 'Arquivo não encontrado');
        }

        return response()->download($path);
    })->name('download.arquivo');

    Route::get('/download-arquivo/{arquivo}', function ($arquivo) {
        $arquivo = basename($arquivo);
        $path = \App\Services\OperadoraStorage::resolveAbsolutePath('exports', $arquivo);

        if (!$path) {
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

Route::post('/webhooks/evolution', App\Http\Controllers\Webhooks\WebhookEvolutionController::class)
    ->name('webhooks.evolution');

// Fallback: redirecionar rotas inexistentes para home (apenas em produção)
if (app()->environment('production')) {
    Route::fallback(function () {
        return redirect()->route('home');
    });
}
