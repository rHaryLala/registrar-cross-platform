<?php

namespace App\Auth\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Vérifie localement la signature du jeton ID Google (RS256) avec les clés
 * publiques de Google, gardées en cache.
 *
 * L'ancien code appelait `oauth2.googleapis.com/tokeninfo` à CHAQUE connexion
 * (délai maximal 10 s, ~256 ms en moyenne sur 2 139 appels) : on retire cet
 * aller-retour réseau et la dépendance qui va avec.
 */
final class GoogleJwtVerifier implements GoogleTokenVerifier
{
    private const KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly string $clientId) {}

    public function verify(string $idToken): ?array
    {
        if ($this->clientId === '' || $idToken === '') {
            return null;
        }

        try {
            JWT::$leeway = 30;
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys()));
        } catch (Throwable) {
            return null;
        }

        if (! in_array($claims['iss'] ?? '', self::ISSUERS, true)
            || ($claims['aud'] ?? null) !== $this->clientId
            || empty($claims['email'])
            || empty($claims['sub'])) {
            return null;
        }

        return [
            'email' => (string) $claims['email'],
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sub' => (string) $claims['sub'],
        ];
    }

    /** @return array<string, mixed> */
    private function keys(): array
    {
        return Cache::remember('google.jwks', 3600, function () {
            return Http::timeout(5)->get(self::KEYS_URL)->throw()->json();
        });
    }
}
