<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;

/**
 * Usuario que inicia sesión con su PAT personal de Azure DevOps. `email` es su
 * uniqueName y `azure_id` el id de su identidad (System.AssignedTo.id).
 */
#[Fillable(['azure_id', 'name', 'email', 'password', 'azure_pat', 'agent_schedule_enabled', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'azure_pat'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'azure_pat' => 'encrypted',
            'agent_schedule_enabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Los administradores se definen en `AUTH_ADMINS` (correos separados por coma).
     */
    public function isAdmin(): bool
    {
        return in_array(strtolower($this->email), config('azure.admins'), true);
    }

    /**
     * Ejecuta el callback como este usuario (jobs en cola): AzureDevOpsService
     * se resuelve con su PAT. Restaura el usuario anterior al terminar.
     */
    public function actAs(callable $callback): mixed
    {
        $guard = Auth::guard('web');
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($this);

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
