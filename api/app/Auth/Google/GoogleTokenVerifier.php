<?php

namespace App\Auth\Google;

/** Vérifie un jeton ID Google et renvoie ses informations, ou null s'il est invalide. */
interface GoogleTokenVerifier
{
    /** @return array{email: string, email_verified: bool, sub: string}|null */
    public function verify(string $idToken): ?array;
}
