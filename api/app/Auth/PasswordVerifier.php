<?php

namespace App\Auth;

/**
 * Vérifie un mot de passe contre l'empreinte stockée dans `compt_utilisateur`.
 *
 * Deux formats coexistent en production (relevé du 14/11/2026 : 206 comptes
 * actifs en bcrypt, 11 en ancien format) :
 *   - bcrypt (`$2y$…`), lu par password_verify ;
 *   - ancien format : sha256(mot de passe . sel statique), converti en bcrypt
 *     à la première connexion réussie, comme le faisait l'ancien code.
 *
 * Le chemin hérité est volontairement isolé ici : une fois les 11 comptes
 * réinitialisés ou convertis, on supprime la méthode et la constante.
 */
final class PasswordVerifier
{
    public const FAIL = 'fail';

    public const OK = 'ok';

    /** Mot de passe correct, mais encore en ancien format : à convertir en bcrypt. */
    public const OK_LEGACY = 'ok_legacy';

    public function verify(string $plain, string $stored): string
    {
        if ($stored === '') {
            return self::FAIL;
        }

        if (password_verify($plain, $stored)) {
            return self::OK;
        }

        if (preg_match('/^[0-9a-f]{64}$/', $stored) === 1
            && hash_equals($stored, hash('sha256', $plain.config('registrar.legacy_password_salt')))) {
            return self::OK_LEGACY;
        }

        return self::FAIL;
    }

    /**
     * Dépense le même temps qu'une vérification réelle quand le compte n'existe pas,
     * pour qu'un identifiant inconnu ne se distingue pas d'un mauvais mot de passe
     * par le temps de réponse.
     */
    public function burn(string $plain): void
    {
        password_verify($plain, '$2y$12$abcdefghijklmnopqrstuuI3x1H0VQcQy3Zy3q3e3Pq3a3Z3a3Z3a');
    }
}
