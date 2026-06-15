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
                'cresol' => 'Cresol (PDF)',
            ],
            'banco_brasil' => [
                'banco_brasil' => 'Banco do Brasil (PDF)',
            ],
        ];
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

    public function executar(string $layout, string $caminhoPdf, string $caminhoOfx): array
    {
        $script = 'conversor_extrato_pdf_ofx.py';
        $caminhoScript = '/var/www/html/scripts/' . $script;

        if (!file_exists($caminhoScript)) {
            throw new \RuntimeException("Script Python não encontrado: {$caminhoScript}");
        }

        $comando = sprintf(
            'python3 %s %s "%s" "%s"',
            $caminhoScript,
            $layout,
            $caminhoPdf,
            $caminhoOfx
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

        return $this->parsearSaida($resultado->output());
    }

    public function registrarSucesso(ConversaoExtrato $conversao, array $dados, string $nomeOfx): void
    {
        $conversao->update([
            'status' => 'concluida',
            'nome_arquivo_ofx' => $nomeOfx,
            'total_lancamentos' => $dados['total_lancamentos'] ?? 0,
            'data_inicial' => $this->parsearData($dados['data_inicial'] ?? null),
            'data_final' => $this->parsearData($dados['data_final'] ?? null),
            'metadados' => array_filter([
                'cooperativa' => $dados['cooperativa'] ?? null,
                'conta' => $dados['conta'] ?? null,
                'titular' => $dados['titular'] ?? null,
                'acct_id' => $dados['acct_id'] ?? null,
            ]),
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
