<?php

namespace App\Services\AutomacaoFiscal;

use App\Models\PortalIntegracao;
use App\Services\AutomacaoFiscal\Contratos\PortalAutomacao;
use App\Services\AutomacaoFiscal\Portais\EcacRsPortal;
use App\Services\AutomacaoFiscal\Portais\FakePortalDriver;
use App\Services\AutomacaoFiscal\Portais\NfseNacionalPortal;
use InvalidArgumentException;

class PortalDriverResolver
{
    public function resolve(PortalIntegracao|string $portal): PortalAutomacao
    {
        $codigo = $portal instanceof PortalIntegracao ? $portal->driver : $portal;

        if (config('automacao_fiscal.fake_mode', true) || $codigo === 'fake') {
            return new FakePortalDriver($codigo === 'fake' ? 'fake' : $codigo);
        }

        return match ($codigo) {
            'ecac_rs' => app(EcacRsPortal::class),
            'nfse_nacional' => app(NfseNacionalPortal::class),
            default => throw new InvalidArgumentException("Driver de portal não suportado: {$codigo}"),
        };
    }
}
