<?php

namespace App\Services\AutomacaoFiscal\Contratos;

use App\Models\AutomacaoExecucao;
use App\Models\EmpresaIntegracao;

interface PortalAutomacao
{
    public function codigo(): string;

    public function validarConfiguracao(EmpresaIntegracao $integracao): ResultadoValidacao;

    public function executar(AutomacaoExecucao $execucao): ResultadoAutomacao;
}
