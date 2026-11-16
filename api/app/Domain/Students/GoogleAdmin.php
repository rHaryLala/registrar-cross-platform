<?php

namespace App\Domain\Students;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Comptes Google Workspace de zurcher.edu.mg par l'API Google Admin (port de `data/google.admin.php`), sans bibliothèque : un compte de service signe
 * son jeton (JWT RS256, openssl) et agit au nom d'un administrateur du domaine.
 *
 * Mise en place (une fois, par un super-administrateur Google) : un projet Google Cloud avec l'API « Admin SDK » et un compte de service ; la délégation à
 * l'échelle du domaine avec le champ https://www.googleapis.com/auth/admin.directory.user ; la clé JSON sur le serveur, HORS du dépôt (GOOGLE_ADMIN_KEY).
 */
final class GoogleAdmin
{
    private const SCOPE = 'https://www.googleapis.com/auth/admin.directory.user';

    private const API = 'https://admin.googleapis.com/admin/directory/v1';

    public static function configured(): bool
    {
        $path = (string) config('registrar.google_admin.key_path');

        return config('registrar.google_admin.subject') !== '' && $path !== '' && is_readable($path);
    }

    /** Le compte (ou l'alias) existe-t-il dans Google ? null si Google ne répond pas ou n'est pas configuré. */
    public static function addressExists(string $address): ?bool
    {
        if (! self::configured()) {
            return null;
        }
        try {
            [$code] = self::api('GET', '/users/'.rawurlencode(strtolower(trim($address))), ['fields' => 'primaryEmail']);

            return $code === 200 ? true : ($code === 404 ? false : null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Crée le compte Google d'un étudiant : unité « / », le mot de passe de la fiche, pas de changement imposé à la première connexion, matricule, mention,
     * parcours et téléphone. Ne lève jamais : l'issue est rendue, la création de l'étudiant est déjà enregistrée.
     *
     * @param  array{address: string, last_name: string, first_name: string, password: string, student_id?: string, mention?: string, track?: string, phone?: string, home?: string}  $e
     * @return array{created: bool, existed: bool, reason: string}
     */
    public static function createStudent(array $e): array
    {
        $address = strtolower(trim($e['address']));
        if (! self::configured()) {
            return ['created' => false, 'existed' => false, 'reason' => 'Compte Google non créé : l’API Google Admin n’est pas configurée.'];
        }
        if (! preg_match("/^[a-z0-9._'-]+@".preg_quote((string) config('registrar.google_admin.domain'), '/').'$/', $address)) {
            return ['created' => false, 'existed' => false, 'reason' => "Compte Google non créé : « {$address} » n’est pas une adresse que Google accepte (accent, espace ou autre domaine)."];
        }
        if (strlen($e['password']) < 8) {
            return ['created' => false, 'existed' => false, 'reason' => 'Compte Google non créé : le mot de passe de la fiche fait moins de 8 caractères.'];
        }
        $clean = fn ($v) => trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        $last = mb_substr($clean($e['last_name'] ?? ''), 0, 60);
        $first = mb_substr($clean($e['first_name'] ?? ''), 0, 60);
        $body = [
            'primaryEmail' => $address,
            'name' => ['givenName' => $first !== '' ? $first : $last, 'familyName' => $last !== '' ? $last : $first],
            'password' => $e['password'],
            'changePasswordAtNextLogin' => false,
            'orgUnitPath' => '/',
            'organizations' => [['name' => 'Université Adventiste Zurcher', 'title' => 'Student', 'description' => 'Student', 'primary' => true, 'department' => $clean($e['mention'] ?? ''), 'costCenter' => $clean($e['track'] ?? '')]],
        ];
        if (($e['student_id'] ?? '') !== '') {
            $body['externalIds'] = [['value' => (string) $e['student_id'], 'type' => 'organization']];
        }
        $digits = preg_replace('/\D/', '', (string) ($e['phone'] ?? ''));
        if (preg_match('/^(?:0|261)(\d{9})$/', (string) $digits, $m)) {
            $body['phones'] = [['value' => '+261'.$m[1], 'type' => 'mobile']];
        }
        if ($clean($e['home'] ?? '') !== '') {
            $body['addresses'] = [['type' => 'home', 'formatted' => $clean($e['home'])]];
        }

        try {
            [$code, $r] = self::api('POST', '/users', [], $body);
        } catch (\Throwable $x) {
            return ['created' => false, 'existed' => false, 'reason' => 'Compte Google non créé : '.$x->getMessage()];
        }
        if ($code === 200 || $code === 201) {
            return ['created' => true, 'existed' => false, 'reason' => "Compte Google créé : {$address} fonctionne dès maintenant, avec le mot de passe de la fiche."];
        }
        if ($code === 409) {
            return ['created' => false, 'existed' => true, 'reason' => "{$address} existe déjà dans Google : son mot de passe peut différer de celui de la fiche."];
        }

        return ['created' => false, 'existed' => false, 'reason' => 'Compte Google non créé, Google répond : '.(string) ($r['error']['message'] ?? 'code '.$code)];
    }

    /**
     * Appel de la Directory API ; rend [code, corps]. Les erreurs de droits et d'indisponibilité lèvent ; 404 et 409 sont rendus à l'appelant.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function api(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $timeout = (int) config('registrar.google_admin.timeout');
        $request = Http::withToken(self::token())->acceptJson()->timeout($timeout);
        $url = self::API.$path;
        $response = match ($method) {
            'GET' => $request->get($url, $query),
            default => $request->post($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
        };
        $code = $response->status();
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException('L’administrateur emprunté ('.config('registrar.google_admin.subject').') n’a pas le droit de faire cela dans Google Admin'.($response->json('error.message') ? ' : '.$response->json('error.message') : '').'.');
        }
        if ($code === 429 || $code >= 500) {
            throw new \RuntimeException('Google est momentanément indisponible (code '.$code.') : réessayez dans un instant.');
        }

        return [$code, (array) $response->json()];
    }

    /** Jeton d'accès (une heure) du compte de service, au nom de l'administrateur ; gardé en cache. */
    private static function token(): string
    {
        $subject = (string) config('registrar.google_admin.subject');
        $path = (string) config('registrar.google_admin.key_path');
        $cacheKey = 'google-admin-token:'.sha1($path."\n".strtolower($subject));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $key = json_decode((string) @file_get_contents($path), true);
        if (! is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new \RuntimeException('La clé du compte de service est illisible (fichier JSON attendu, tel que Google le donne).');
        }
        $now = time();
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
            'iss' => $key['client_email'], 'sub' => $subject, 'scope' => self::SCOPE, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]));
        $signature = '';
        if (! openssl_sign($input, $signature, (string) $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('La clé privée du compte de service ne permet pas de signer.');
        }
        $r = Http::asForm()->timeout((int) config('registrar.google_admin.timeout'))->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input.'.'.$b64($signature),
        ]);
        if ($r->status() !== 200 || ! $r->json('access_token')) {
            $what = (string) $r->json('error');
            $detail = (string) $r->json('error_description');
            throw new \RuntimeException(match (true) {
                $what === 'unauthorized_client' => 'Google refuse le compte de service : la délégation à l’échelle du domaine n’est pas accordée (ou sans le champ '.self::SCOPE.').',
                $what === 'invalid_client' || preg_match('/signature|account not found|not found/i', $detail) === 1 => 'Google ne reconnaît pas la clé du compte de service (supprimée, ou fichier modifié).',
                preg_match('/email or user id/i', $detail) === 1 => 'Google ne connaît pas le compte emprunté ('.$subject.').',
                preg_match('/iat|exp|timeframe/i', $detail) === 1 => 'Google refuse le jeton : l’horloge du serveur est décalée.',
                default => 'Google refuse la connexion : '.($detail !== '' ? $detail : ($what !== '' ? $what : 'code '.$r->status())).'.',
            });
        }
        $token = (string) $r->json('access_token');
        Cache::put($cacheKey, $token, max(60, (int) $r->json('expires_in', 3600) - 120));

        return $token;
    }
}
