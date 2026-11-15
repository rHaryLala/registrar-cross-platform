<?php

namespace Tests\Feature\Auth;

use App\Auth\Google\GoogleJwtVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vérifie la VRAIE cryptographie : un jeton signé RS256 avec une clé générée dans le test,
 * dont la clé publique est servie à la place de celle de Google.
 */
class GoogleJwtVerifierTest extends TestCase
{
    private const CLIENT = 'client-de-test.apps.googleusercontent.com';

    private string $privatePem;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        [$this->privatePem, $jwk] = $this->makeKey('k1');
        Http::fake(['www.googleapis.com/*' => Http::response(['keys' => [$jwk]])]);
    }

    /**
     * Sous Windows, OpenSSL ne trouve pas toujours son fichier de configuration
     * (« error:80000003:system library::No such process ») : on lui indique celui livré avec PHP.
     *
     * @return array<string, string>
     */
    private function opensslOptions(): array
    {
        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] as $path) {
            if ($path !== null && is_file($path)) {
                return ['config' => $path];
            }
        }

        return [];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function makeKey(string $kid): array
    {
        $options = $this->opensslOptions();
        $key = openssl_pkey_new($options + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'OpenSSL ne peut pas générer de clé : '.openssl_error_string());
        openssl_pkey_export($key, $pem, null, $options);
        $d = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return [$pem, ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($d['n']), 'e' => $b64($d['e'])]];
    }

    /** @param array<string, mixed> $override */
    private function token(array $override = [], ?string $pem = null, string $kid = 'k1'): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT,
            'sub' => '123456789',
            'email' => 'fanja@zurcher.edu.mg',
            'email_verified' => true,
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ], $override);

        return JWT::encode($claims, $pem ?? $this->privatePem, 'RS256', $kid);
    }

    private function verifier(string $client = self::CLIENT): GoogleJwtVerifier
    {
        return new GoogleJwtVerifier($client);
    }

    public function test_a_valid_token_is_accepted(): void
    {
        $this->assertSame(
            ['email' => 'fanja@zurcher.edu.mg', 'email_verified' => true, 'sub' => '123456789'],
            $this->verifier()->verify($this->token()),
        );
    }

    public function test_the_alternative_issuer_form_is_accepted(): void
    {
        $this->assertNotNull($this->verifier()->verify($this->token(['iss' => 'accounts.google.com'])));
    }

    public function test_a_token_for_another_application_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['aud' => 'une-autre-application'])));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['iat' => time() - 7200, 'exp' => time() - 3600])));
    }

    public function test_a_wrong_issuer_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->token(['iss' => 'https://evil.example'])));
    }

    public function test_a_token_signed_with_another_key_is_rejected(): void
    {
        [$otherPem] = $this->makeKey('k1'); // même identifiant de clé, autre clé privée
        $this->assertNull($this->verifier()->verify($this->token([], $otherPem)));
    }

    public function test_an_unsigned_token_is_rejected(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'sub' => '1', 'email' => 'x@zurcher.edu.mg', 'exp' => time() + 3600])), '+/', '-_'), '=');

        $this->assertNull($this->verifier()->verify("{$header}.{$payload}."));
    }

    public function test_garbage_and_empty_input_are_rejected(): void
    {
        $this->assertNull($this->verifier()->verify('pas.un.jeton'));
        $this->assertNull($this->verifier()->verify(''));
    }

    public function test_an_unconfigured_client_id_rejects_everything(): void
    {
        $this->assertNull($this->verifier('')->verify($this->token()));
    }

    public function test_google_keys_are_fetched_once_then_cached(): void
    {
        $v = $this->verifier();
        $v->verify($this->token());
        $v->verify($this->token());

        Http::assertSentCount(1);
    }
}
