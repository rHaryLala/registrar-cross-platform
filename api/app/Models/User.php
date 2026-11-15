<?php

namespace App\Models;

use App\Support\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Un compte de l'application : étudiant, enseignant ou personnel.
 *
 * Table historique `compt_utilisateur`, partagée avec l'ancien PHP pendant la
 * coexistence : aucune colonne n'est renommée ni ajoutée par ce code.
 */
#[Table('compt_utilisateur', timestamps: false)]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getAuthPassword(): string
    {
        return (string) $this->password;
    }

    /** Pas de jeton « se souvenir de moi » sur cette table. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function isActive(): bool
    {
        return (int) $this->etat === 1;
    }

    /** `user_type` fait foi ; à défaut, on déduit le rôle du niveau. */
    public function role(): ?Role
    {
        return Role::tryFrom((string) $this->user_type) ?? Role::fromLevel((int) $this->level);
    }
}
