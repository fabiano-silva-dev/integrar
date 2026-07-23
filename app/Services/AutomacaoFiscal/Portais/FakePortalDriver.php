<?php

namespace App\Services\AutomacaoFiscal\Portais;

use App\Models\AutomacaoExecucao;
use App\Models\EmpresaIntegracao;
use App\Services\AutomacaoFiscal\AutomacaoArtefatoService;
use App\Services\AutomacaoFiscal\Contratos\PortalAutomacao;
use App\Services\AutomacaoFiscal\Contratos\ResultadoAutomacao;
use App\Services\AutomacaoFiscal\Contratos\ResultadoValidacao;
use App\Services\AutomacaoFiscal\Logs\AutomacaoLogService;

/**
 * Driver simulado para validar fila, agenda e logs sem acessar portais reais.
 */
class FakePortalDriver implements PortalAutomacao
{
    public function __construct(private readonly string $codigoPortal = 'fake')
    {
    }

    public function codigo(): string
    {
        return $this->codigoPortal;
    }

    public function validarConfiguracao(EmpresaIntegracao $integracao): ResultadoValidacao
    {
        if (!$integracao->ativo) {
            return ResultadoValidacao::falha('Integração inativa.', 'inativo');
        }

        return ResultadoValidacao::sucesso('Configuração simulada válida.');
    }

    public function executar(AutomacaoExecucao $execucao): ResultadoAutomacao
    {
        $logService = app(AutomacaoLogService::class);
        $artefatos = app(AutomacaoArtefatoService::class);
        $quantidade = 3;

        $passos = [
            ['nivel' => 'info', 'etapa' => 'RUN_STARTED', 'mensagem' => 'Execução simulada iniciada'],
            ['nivel' => 'info', 'etapa' => 'CERTIFICATE_CONFIGURED', 'mensagem' => 'Certificado A1 simulado configurado'],
            ['nivel' => 'info', 'etapa' => 'BROWSER_STARTED', 'mensagem' => 'Navegador simulado iniciado'],
            ['nivel' => 'info', 'etapa' => 'NAVIGATION_STARTED', 'mensagem' => 'Navegando no portal (simulado)'],
            ['nivel' => 'info', 'etapa' => 'AUTHENTICATION_CONFIRMED', 'mensagem' => 'Autenticação simulada confirmada'],
            ['nivel' => 'info', 'etapa' => 'EXTRACT_STARTED', 'mensagem' => 'Coleta simulada iniciada'],
            ['nivel' => 'info', 'etapa' => 'EXTRACT_FINISHED', 'mensagem' => "Documentos simulados gerados ({$quantidade})"],
            ['nivel' => 'info', 'etapa' => 'RUN_FINISHED', 'mensagem' => 'Execução simulada finalizada'],
        ];

        foreach ($passos as $passo) {
            $execucao->update([
                'etapa_atual' => $passo['etapa'],
                'mensagem_usuario' => $passo['mensagem'],
            ]);

            $logService->registrar(
                $execucao,
                $passo['nivel'],
                $passo['mensagem'],
                $passo['etapa'],
                ['driver' => 'fake', 'portal' => $this->codigoPortal]
            );

            if ($passo['etapa'] === 'NAVIGATION_STARTED') {
                $png = $artefatos->gerarPngSimulado('Portal: ' . $this->codigoPortal);
                $artefatos->gravar(
                    $execucao,
                    'screenshot',
                    '01-entry-simulado.png',
                    $png,
                    'image/png',
                    null,
                    ['driver' => 'fake']
                );
            }

            if (!app()->runningUnitTests()) {
                usleep(200000);
            }
        }

        return new ResultadoAutomacao(
            status: 'sucesso',
            mensagemUsuario: "Consulta simulada concluída. Foram localizados {$quantidade} documentos.",
            quantidadeEncontrada: $quantidade,
            quantidadeImportada: $quantidade,
            logs: [],
            metadados: ['driver' => 'fake']
        );
    }
}
