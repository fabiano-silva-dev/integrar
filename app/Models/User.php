<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Services\OperadoraContext;
use Illuminate\Database\Eloquent\Builder;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'empresa_operadora_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->empresa_operadora_id === null && !$user->isSuperAdmin() && OperadoraContext::id() !== null) {
                $user->empresa_operadora_id = OperadoraContext::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isEscritorioAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isGerente(): bool
    {
        return $this->role === 'gerente';
    }

    public function podeVerLogDocumentos(): bool
    {
        return $this->isSuperAdmin() || $this->isEscritorioAdmin();
    }

    /**
     * @return list<string>
     */
    public function niveisQuePodeAtribuir(): array
    {
        if ($this->isSuperAdmin() || $this->isEscritorioAdmin()) {
            return ['operador', 'gerente', 'admin'];
        }

        if ($this->isGerente()) {
            return ['operador'];
        }

        return [];
    }

    public function podeAtribuirNivel(string $role): bool
    {
        return in_array($role, $this->niveisQuePodeAtribuir(), true);
    }

    public function podeEditarUsuario(self $alvo): bool
    {
        if ($alvo->isSuperAdmin()) {
            return false;
        }

        if ($this->isSuperAdmin() || $this->isEscritorioAdmin()) {
            return true;
        }

        if ($this->isGerente()) {
            return (int) $alvo->id === (int) $this->id || $alvo->role === 'operador';
        }

        return false;
    }

    public function podeExcluirUsuario(self $alvo): bool
    {
        if ((int) $alvo->id === (int) $this->id || $alvo->isSuperAdmin()) {
            return false;
        }

        if ($this->isSuperAdmin() || $this->isEscritorioAdmin()) {
            return true;
        }

        if ($this->isGerente()) {
            return $alvo->role === 'operador';
        }

        return false;
    }

    public function podeAlterarNivelDe(self $alvo): bool
    {
        if ($alvo->isSuperAdmin()) {
            return false;
        }

        if ($this->isGerente() && (int) $alvo->id === (int) $this->id) {
            return false;
        }

        if ($this->isSuperAdmin() || $this->isEscritorioAdmin()) {
            return true;
        }

        if ($this->isGerente()) {
            return $alvo->role === 'operador';
        }

        return false;
    }

    public function empresaOperadora(): BelongsTo
    {
        return $this->belongsTo(EmpresasOperadora::class, 'empresa_operadora_id');
    }

    public function scopeDoEscritorio(Builder $query): Builder
    {
        $operadoraId = OperadoraContext::id();

        if ($operadoraId !== null) {
            $query->where('empresa_operadora_id', $operadoraId);
        }

        return $query;
    }
}
