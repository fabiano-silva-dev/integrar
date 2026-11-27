<?php

namespace App\Models\Nfse;

class DiagnosticoAcesso extends RegistroNfse
{
    protected $table = 'nfse_diagnosticos_acesso';
    protected $fillable = ['acesso_id', 'certificado_id', 'usuario_id', 'ambiente', 'config_hash', 'chave_hash', 'status', 'diagnostico_tecnico'];
    protected $hidden = ['config_hash', 'chave_hash'];

    protected $casts = ['diagnostico_tecnico' => 'array'];

    public function mensagem(): string
    {
        return match ($this->status) {
            'consulta_confirmada' => 'Consulta confirmada para o prestador.',
            'identidade_divergente' => 'Chave, identidade ou ambiente divergente.',
            'config_alterada' => 'Configuração alterada durante o teste.',
            'iniciada' => 'Consulta iniciada, sem conclusão registrada.',
            default => 'Consulta não confirmada. Confira o diagnóstico técnico.',
        };
    }
}
