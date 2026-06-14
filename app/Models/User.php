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
