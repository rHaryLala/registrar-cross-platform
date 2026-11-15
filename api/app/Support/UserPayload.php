<?php

namespace App\Support;

use App\Models\User;

/** Forme publique d'un compte dans l'API : jamais d'empreinte de mot de passe ni de colonne interne. */
final class UserPayload
{
    /** @return array<string, mixed> */
    public static function from(User $user): array
    {
        $first = trim((string) $user->prenom);
        $last = trim((string) $user->nom);

        return [
            'id' => (int) $user->id,
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim($first.' '.$last) ?: (string) $user->pseudo,
            'email' => $user->mail ?: null,
            'role' => $user->role()?->value,
            'level' => (int) $user->level,
            'student_id' => $user->student_id ?: null,
        ];
    }
}
