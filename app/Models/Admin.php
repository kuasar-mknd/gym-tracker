<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
class Admin extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<\Database\Factories\AdminFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    #[\Override]
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Une ligne dans `admins` ne suffit pas : il faut qu'un role ou une
     * permission Shield lui ait ete accorde.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists() || $this->permissions()->exists();
    }

    /**
     * Le nom du rôle du super administrateur (`filament-shield.super_admin.name`).
     * Les capacités de `AppServiceProvider::ouvrirLesOutilsAuSuperAdministrateur()`
     * suivent ce nom : `RolePolicy` ne laisse ni modifier ce rôle à qui ne le
     * porte pas, ni le supprimer, et aucun rôle ne prend ni ne perd ce nom.
     */
    public static function roleDuSuperAdministrateur(): string
    {
        $role = config('filament-shield.super_admin.name');

        return is_string($role) && $role !== '' ? $role : 'super_admin';
    }

    /**
     * L'administrateur porte-t-il le rôle du super administrateur.
     */
    public function estSuperAdministrateur(): bool
    {
        return $this->hasRole(self::roleDuSuperAdministrateur());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
