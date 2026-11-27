<?php

namespace App\Models\Nfse;

class ValidacaoPortal extends RegistroNfse
{
    protected $table = 'nfse_validacoes_portal';

    protected $guarded = ['id'];

    protected $hidden = ['config_hash'];

    protected function casts(): array
    {
        return ['concluida_em' => 'datetime', 'diagnostico_tecnico' => 'array'];
    }

    public function mensagem(): string
    {
        return match ($this->status) {
            'pendente' => 'Aguardando teste de acesso.',
            'iniciada' => 'Conferindo acesso ao portal.',
            'confirmada' => str_ends_with($this->modo, '_representante') ? 'Acesso e empresa representada confirmados.' : 'Acesso do prestador confirmado.',
            'config_alterada' => 'Configuração ou permissão alterada. Teste novamente.',
            default => match ($this->codigo) {
                'LOGIN_REJECTED' => 'Login recusado. Confira usuário e senha.',
                'IDENTITY_MISMATCH' => 'O CPF/CNPJ ativo não corresponde ao titular esperado.',
                'REPRESENTATION_NOT_CONFIRMED' => 'Representação não confirmada. Confira a procuração no portal.',
                'MANUAL_REQUIRED' => 'O portal solicitou uma verificação manual. Acesso não confirmado.',
                'LAYOUT_CHANGED' => 'Não foi possível identificar o perfil ativo no portal. Acesso não confirmado.',
                default => 'Acesso não confirmado. Confira o cadastro, a disponibilidade do portal e o serviço de automação.',
            },
        };
    }
}
