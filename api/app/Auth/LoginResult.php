<?php

namespace App\Auth;

use App\Models\User;

/** Issue d'une tentative de connexion : un compte, ou un motif d'échec. */
final class LoginResult
{
    private function __construct(
        public readonly ?User $user,
        public readonly ?string $reason,
        public readonly ?User $knownUser = null,
        /** 'account' = mot de passe du compte ; 'student_table' = mot de passe de la fiche étudiante. */
        public readonly string $via = 'account',
        public readonly bool $created = false,
    ) {}

    public static function success(User $user, string $via = 'account', bool $created = false): self
    {
        return new self($user, null, null, $via, $created);
    }

    /** @param User|null $knownUser le compte visé, s'il existe (pour le journal seulement) */
    public static function failed(string $reason, ?User $knownUser = null): self
    {
        return new self(null, $reason, $knownUser);
    }

    public function ok(): bool
    {
        return $this->user !== null;
    }
}
