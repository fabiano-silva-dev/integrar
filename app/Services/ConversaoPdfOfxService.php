<?php

namespace App\Services;

use App\Models\ConversaoExtrato;
use App\Services\OperadoraContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ConversaoPdfOfxService
{
    public function layoutsPdfPorFamilia(): array
    {
        return [
            'grafeno' => [
                'grafeno' => 'Grafeno (PDF)',
            ],
            'sicoob' => [
                'sicoob' => 'Sicoob (PDF)',
            ],
            'caixa' => [
                'caixa_federal' => 'Caixa Econômica Federal (PDF) - Modelo antigo',
                'caixa' => 'Caixa Internet Banking (PDF) - Modelo novo',
                'caixa_data_efetiva' => 'Caixa (PDF) - Data efetiva (paisagem)',
            ],
            'sicredi' => [
                'sicredi' => 'SICREDI (PDF)',
            ],
            'santander' => [
                'santander' => 'Santander (PDF)',
            ],
            'itau' => [
                'itau' => 'Itaú (PDF)',
            ],
            'bradesco' => [
                'bradesco' => 'Bradesco (PDF)',
            ],
            'cresol' => [
                'cresol' => 'Cresol (PDF) - Extrato consolidado',
                'cresol_modelo2' => 'Cresol (PDF) - Extrato conta corrente',
            ],
            'banco_brasil' => [
                'banco_brasil' => 'Banco do Brasil (PDF)',
            ],
            'banrisul' => [
                'banrisul' => 'Banrisul (PDF) - Extrato',
                'banrisul_enriquecido' => 'Banrisul (PDF) - Extrato + Pagamentos + PIX',
            ],
            'nubank' => [
                'nubank' => 'Nubank (PDF)',
            ],
            'infinitepay' => [
                'infinitepay' => 'InfinitePay (PDF)',
            ],
        ];
    }

    public function layoutsRequeremArquivosAuxiliares(): array
    {
        return ['banrisul_enriquecido'];
    }

    public function layoutsComListagemLancamentos(): array
    {
        return ['banrisul', 'banrisul_enriquecido', 'cresol', 'cresol_modelo2', 'nubank', 'infinitepay'];
    }

    public function layoutRequerArquivosAuxiliares(string $layout): bool
    {
        return in_array($layout, $this->layoutsRequeremArquivosAuxiliares(), true);
    }

    public function layoutExibeListagemLancamentos(string $layout): bool
    {
        return in_array($layout, $this->layoutsComListagemLancamentos(), true);
    }

    public function familiasLayout(): array
    {
        return [
            'grafeno' => 'Grafeno',
            'sicoob' => 'Sicoob',
            'caixa' => 'Caixa',
            'sicredi' => 'Sicredi',
            'santander' => 'Santander',
            'itau' => 'Itaú',
            'bradesco' => 'Bradesco',
            'cresol' => 'Cresol',
            'banco_brasil' => 'Banco do Brasil',
            'banrisul' => 'Banrisul',
            'nubank' => 'Nubank',
            'infinitepay' => 'InfinitePay',
        ];
    }

    public function layoutsSuportados(): array
    {
        return array_keys(array_merge(...array_values($this->layoutsPdfPorFamilia())));
    }

    public function familiaDoLayout(string $layout): ?string
    {
        foreach ($this->layoutsPdfPorFamilia() as $familia => $layouts) {
            if (array_key_exists($layout, $layouts)) {
                return $familia;
            }
        }

        return null;
    }

    /**
     * Caminhos relativos a public/ das miniaturas de referência por layout.
     *
     * @return array<string, string>
     */
    public function miniaturasLayout(): array
    {
        return [
            'cresol' => 'images/extratos/cresol/consolidado.png',
            'cresol_modelo2' => 'images/extratos/cresol/conta-corrente.png',
        ];
    }

    /**
     * URLs das miniaturas disponíveis para os layouts de uma família.
     *
     * @return array<string, string>
     */
    public function miniaturasPorFamilia(string $familia): array
    {
        if ($familia === '') {
            return [];
        }

        $layouts = array_keys($this->layoutsPdfPorFamilia()[$familia] ?? []);
        $miniaturas = $this->miniaturasLayout();
        $resultado = [];

        foreach ($layouts as $layout) {
            $caminho = $miniaturas[$layout] ?? null;
            if ($caminho && is_file(public_path($caminho))) {
                $resultado[$layout] = asset($caminho);
            }
        }

        return $resultado;
    }

    public function criarRegistro(string $layout, string $nomeArquivoOrigem): ConversaoExtrato
    {
        $empresa = OperadoraContext::resolveEmpresaDaSessao();

        return ConversaoExtrato::create([
            'user_id' => Auth::id(),
            'empresa_id' => $empresa?->id,
            'empresa_operadora_id' => $empresa?->empresa_operadora_id ?? OperadoraContext::requireId(),
            'familia_layout' => $this->familiaDoLayout($layout) ?? '',
            'layout' => $layout,
            'nome_arquivo_origem' => $nomeArquivoOrigem,
            'status' => 'processando',
        ]);
    }

    public function executar(string $layout, string $caminhoPdf, string $caminhoOfx, ?string $caminhoPreview = null): array
    {
        if ($layout === 'banrisul') {
            return $this->executarScript(
                'conversor_extrato_banrisul_pdf_ofx.py',
                array_filter([$caminhoPdf, $caminhoOfx, $caminhoPreview]),
                $layout,
                $caminhoPreview
            );
        }

        $script = 'conversor_extrato_pdf_ofx.py';
        $caminhoScript = '/var/www/html/scripts/' . $script;

        if (!file_exists($caminhoScript)) {
            throw new \RuntimeException("Script Python não encontrado: {$caminhoScript}");
        }

        $comando = sprintf(
            'python3 %s %s "%s" "%s"%s',
            $caminhoScript,
            $layout,
            $caminhoPdf,
            $caminhoOfx,
            $caminhoPreview ? ' "' . str_replace('"', '\\"', $caminhoPreview) . '"' : ''
        );

        Log::info('Executando conversão PDF->OFX', [
            'layout' => $layout,
            'comando' => $comando,
        ]);

        $resultado = Process::run($comando);

        Log::info('Resultado conversão PDF->OFX', [
            'layout' => $layout,
            'sucesso' => $resultado->successful(),
            'saida' => $resultado->output(),
            'erro' => $resultado->errorOutput(),
        ]);

        if (!$resultado->successful()) {
            throw new \RuntimeException(trim($resultado->errorOutput() ?: $resultado->output() ?: 'Falha na conversão.'));
        }

        $dados = $this->parsearSaida($resultado->output());

        if ($caminhoPreview && file_exists($caminhoPreview)) {
            $conteudo = file_get_contents($caminhoPreview);
            $lancamentos = json_decode($conteudo ?: '[]', true);
            if (is_array($lancamentos)) {
                $dados['lancamentos'] = $lancamentos;
            }
        }

        return $dados;
    }

    public function executarEnriquecido(
        string $layout,
        string $caminhoExtrato,
        string $caminhoPix,
        string $caminhoPagamentos,
        string $caminhoOfx,
        ?string $caminhoPreview = null
    ): array {
        if ($layout !== 'banrisul_enriquecido') {
            throw new \InvalidArgumentException("Layout não suporta arquivos auxiliares: {$layout}");
        }

        return $this->executarScript(
            'conversor_extrato_banrisul_enriquecido_pdf_ofx.py',
            array_filter([
                $caminhoExtrato,
                $caminhoPix,
                $caminhoPagamentos,
                $caminhoOfx,
                $caminhoPreview,
            ]),
            $layout,
            $caminhoPreview
        );
    }

    private function executarScript(string $script, array $argumentos, string $layout, ?string $caminhoPreview): array
    {
        $caminhoScript = '/var/www/html/scripts/' . $script;

        if (!file_exists($caminhoScript)) {
            throw new \RuntimeException("Script Python não encontrado: {$caminhoScript}");
        }

        $argsEscapados = array_map(
            static fn (string $arg) => '"' . str_replace('"', '\\"', $arg) . '"',
            $argumentos
        );

        $comando = sprintf(
            'python3 %s %s',
            $caminhoScript,
            implode(' ', $argsEscapados)
        );

        Log::info('Executando conversão PDF->OFX', [
            'layout' => $layout,
            'comando' => $comando,
        ]);

        $resultado = Process::run($comando);

        Log::info('Resultado conversão PDF->OFX', [
            'layout' => $layout,
            'sucesso' => $resultado->successful(),
            'saida' => $resultado->output(),
            'erro' => $resultado->errorOutput(),
        ]);

        if (!$resultado->successful()) {
            throw new \RuntimeException(trim($resultado->errorOutput() ?: $resultado->output() ?: 'Falha na conversão.'));
        }

        $dados = $this->parsearSaida($resultado->output());

        if ($caminhoPreview && file_exists($caminhoPreview)) {
            $conteudo = file_get_contents($caminhoPreview);
            $lancamentos = json_decode($conteudo ?: '[]', true);
            if (is_array($lancamentos)) {
                $dados['lancamentos'] = $lancamentos;
            }
        }

        if (preg_match('/Lançamentos enriquecidos:\s*(\d+)/', $resultado->output(), $matches)) {
            $dados['total_enriquecidos'] = (int) $matches[1];
        }

        if (preg_match('/Pagamentos separados \(juros\/multa\):\s*(\d+)/', $resultado->output(), $matches)) {
            $dados['total_separados_encargos'] = (int) $matches[1];
        }

        return $dados;
    }

    public function registrarSucesso(ConversaoExtrato $conversao, array $dados, string $nomeOfx): void
    {
        $metadados = array_filter([
            'cooperativa' => $dados['cooperativa'] ?? null,
            'conta' => $dados['conta'] ?? null,
            'titular' => $dados['titular'] ?? null,
            'acct_id' => $dados['acct_id'] ?? null,
            'total_enriquecidos' => $dados['total_enriquecidos'] ?? null,
            'total_separados_encargos' => $dados['total_separados_encargos'] ?? null,
            'lancamentos' => $dados['lancamentos'] ?? null,
        ], static fn ($valor) => $valor !== null && $valor !== []);

        $conversao->update([
            'status' => 'concluida',
            'nome_arquivo_ofx' => $nomeOfx,
            'total_lancamentos' => $dados['total_lancamentos'] ?? 0,
            'data_inicial' => $this->parsearData($dados['data_inicial'] ?? null),
            'data_final' => $this->parsearData($dados['data_final'] ?? null),
            'metadados' => $metadados ?: null,
        ]);
    }

    public function registrarErro(ConversaoExtrato $conversao, string $mensagem): void
    {
        $conversao->update([
            'status' => 'erro',
            'erro_mensagem' => $mensagem,
        ]);
    }

    public function parsearSaida(string $saida): array
    {
        return [
            'total_lancamentos' => $this->extrair($saida, '/Total de lançamentos:\s*(\d+)/', 'int'),
            'data_inicial' => $this->extrair($saida, '/Data inicial:\s*(.+)/', 'string'),
            'data_final' => $this->extrair($saida, '/Data final:\s*(.+)/', 'string'),
            'cooperativa' => $this->extrair($saida, '/(?:Cooperativa|Agência) extraída:\s*(.+)/', 'string'),
            'conta' => $this->extrair($saida, '/Conta extraída:\s*(.+)/', 'string'),
            'titular' => $this->extrair($saida, '/Titular:\s*(.+)/', 'string'),
            'acct_id' => $this->extrair($saida, '/ACCTID OFX:\s*(.+)/', 'string'),
        ];
    }

    private function extrair(string $saida, string $pattern, string $tipo)
    {
        if (!preg_match($pattern, $saida, $matches)) {
            return $tipo === 'int' ? 0 : null;
        }

        $valor = trim($matches[1]);

        if ($tipo === 'int') {
            return (int) $valor;
        }

        return $valor;
    }

    private function parsearData(?string $data): ?string
    {
        if (!$data) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', trim($data))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
