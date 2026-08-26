<?php

namespace App\Http\Controllers\OAuth;

use App\Enums\Documentos\StatusContaGoogle;
use App\Http\Controllers\Controller;
use App\Models\Documentos\ContaGoogle;
use App\Services\Documentos\GoogleDriveService;
use App\Services\OperadoraContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class GoogleOAuthController extends Controller
{
    public function redirect(Request $request, GoogleDriveService $drive): RedirectResponse
    {
        $this->garantirAcesso();

        if (OperadoraContext::superAdminPrecisaSelecionarEscritorio()) {
            return redirect()->route('documentos.drive')
                ->with('error', 'Selecione um escritório no menu superior.');
        }

        if (! $drive->configurado()) {
            return redirect()->route('documentos.drive')
                ->with('error', 'Salve o aplicativo Google neste escritório antes de conectar a conta.');
        }

        $state = Str::random(40);
        $operadoraId = OperadoraContext::requireId();
        $request->session()->put('google_oauth_state', $state);
        $request->session()->put('google_oauth_operadora_id', $operadoraId);

        return redirect()->away($drive->urlAutorizacao($state, $operadoraId));
    }

    public function callback(Request $request, GoogleDriveService $drive): RedirectResponse
    {
        $this->garantirAcesso();

        $stateEsperado = $request->session()->pull('google_oauth_state');
        $operadoraId = (int) $request->session()->pull('google_oauth_operadora_id');

        if (! is_string($stateEsperado) || $stateEsperado === '' || $request->query('state') !== $stateEsperado) {
            return redirect()->route('documentos.drive')
                ->with('error', 'Autorização Google inválida. Tente conectar de novo.');
        }

        if ($request->query('error')) {
            return redirect()->route('documentos.drive')
                ->with('error', 'A autorização Google foi cancelada.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('documentos.drive')
                ->with('error', 'Google não devolveu o código de autorização.');
        }

        if ($operadoraId <= 0) {
            return redirect()->route('documentos.drive')
                ->with('error', 'Escritório não identificado na autorização.');
        }

        try {
            $tokens = $drive->trocarCode($code, $operadoraId);
        } catch (\Throwable $exception) {
            return redirect()->route('documentos.drive')
                ->with('error', $exception->getMessage());
        }

        $conta = ContaGoogle::withoutGlobalScope('operadora')
            ->where('empresa_operadora_id', $operadoraId)
            ->first();

        $emailAnterior = $conta?->google_email;
        $avisoOutraConta = $emailAnterior
            && $tokens['email']
            && strcasecmp($emailAnterior, $tokens['email']) !== 0;

        $dados = [
            'empresa_operadora_id' => $operadoraId,
            'google_email' => $tokens['email'],
            'access_token' => $tokens['access_token'],
            'status' => StatusContaGoogle::Conectado,
            'token_expires_at' => $tokens['expires_at'],
            'scopes' => $tokens['scopes'],
        ];

        if (is_string($tokens['refresh_token']) && $tokens['refresh_token'] !== '') {
            $dados['refresh_token'] = $tokens['refresh_token'];
        } elseif ($conta === null || ! $conta->refresh_token) {
            return redirect()->route('documentos.drive')
                ->with('error', 'Google não devolveu o refresh token. Revogue o acesso do IntegraExpert na conta Google e conecte de novo.');
        }

        if ($conta === null) {
            ContaGoogle::withoutGlobalScope('operadora')->create($dados);
        } else {
            $conta->update($dados);
        }

        $mensagem = 'Conta Google conectada.';
        if ($avisoOutraConta) {
            $mensagem .= ' A conta é outra: pastas antigas podem ficar inacessíveis.';
        }

        return redirect()->route('documentos.drive')->with('message', $mensagem);
    }

    private function garantirAcesso(): void
    {
        $user = Auth::user();

        if (! $user || (! $user->isSuperAdmin() && ! in_array($user->role, ['admin', 'gerente'], true))) {
            abort(403, 'Sem permissão para conectar o Google Drive.');
        }
    }
}
