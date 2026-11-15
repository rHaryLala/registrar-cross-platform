<?php

namespace App\Auth\Google;

use App\Auth\LoginResult;
use App\Models\User;

/**
 * Connexion par Google, réservée aux adresses @zurcher.edu.mg.
 *
 * Règles reprises de Middleware::authenticateWithGoogle :
 *   1. jeton valide, adresse vérifiée par Google ;
 *   2. domaine @zurcher.edu.mg ;
 *   3. compte actif retrouvé par son e-mail dans `compt_utilisateur`.
 *
 * Pas encore portés (étape suivante, avec des tests sur de vrais cas) :
 *   - le repli enseignant (fiche `teacher` retrouvée par e-mail, compte créé à la
 *     première connexion) ;
 *   - la création du compte d'un étudiant connu dans `tbl_2024_etudiant`. L'ancien
 *     code échoue souvent ici (412 événements `google_user_create_error`) : il
 *     faut lire ce chemin ligne à ligne avant de le reprendre, pas le copier.
 * Un compte dans ces deux cas reçoit donc, pour l'instant, `user_not_found`.
 */
final class GoogleLoginService
{
    public const DOMAIN = '@zurcher.edu.mg';

    public function __construct(private readonly GoogleTokenVerifier $verifier) {}

    public function attempt(string $credential): LoginResult
    {
        $claims = $this->verifier->verify($credential);

        if ($claims === null) {
            return LoginResult::failed('invalid_token');
        }

        if (! $claims['email_verified']) {
            return LoginResult::failed('email_not_verified');
        }

        $email = mb_strtolower(trim($claims['email']));

        if (! str_ends_with($email, self::DOMAIN)) {
            return LoginResult::failed('invalid_domain');
        }

        $user = User::query()
            ->whereRaw('LOWER(TRIM(mail)) = ?', [$email])
            ->where('etat', 1)
            ->first();

        return $user ? LoginResult::success($user) : LoginResult::failed('user_not_found');
    }
}
