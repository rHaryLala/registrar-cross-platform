<?php

namespace Tests\Feature\Auth;

use App\Auth\Google\GoogleTokenVerifier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    /** Remplace la vérification Google par un résultat connu : on teste nos règles, pas Google. */
    private function claims(?array $claims): void
    {
        $this->app->instance(GoogleTokenVerifier::class, new class($claims) implements GoogleTokenVerifier
        {
            public function __construct(private ?array $claims) {}

            public function verify(string $idToken): ?array
            {
                return $this->claims;
            }
        });
    }

    private function google()
    {
        return $this->fromApp()->postJson('/api/v1/auth/google', ['credential' => 'jeton-quelconque']);
    }

    private function lastDetails(string $event): object
    {
        return json_decode(DB::table('security_logs')->where('event_type', $event)->orderByDesc('id')->value('details'));
    }

    public function test_a_known_zurcher_address_logs_in(): void
    {
        $user = User::factory()->create(['mail' => 'fanja@zurcher.edu.mg']);
        $this->claims(['email' => 'fanja@zurcher.edu.mg', 'email_verified' => true, 'sub' => '1']);

        $this->google()->assertOk()->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('google', $this->lastDetails('google_login_success')->method);
    }

    public function test_the_match_ignores_case_and_surrounding_spaces(): void
    {
        $user = User::factory()->create(['mail' => ' Fanja@Zurcher.edu.mg ']);
        $this->claims(['email' => 'FANJA@zurcher.edu.mg', 'email_verified' => true, 'sub' => '1']);

        $this->google()->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_another_domain_is_refused_and_logged(): void
    {
        User::factory()->create(['mail' => 'quelquun@gmail.com']);
        $this->claims(['email' => 'quelquun@gmail.com', 'email_verified' => true, 'sub' => '1']);

        $this->google()->assertStatus(403)->assertJsonPath('code', 'auth.google_domain_not_allowed');

        $this->assertGuest('web');
        $this->assertSame('invalid_domain', $this->lastDetails('google_login_rejected')->reason);
    }

    public function test_an_unverified_address_is_refused(): void
    {
        User::factory()->create(['mail' => 'a@zurcher.edu.mg']);
        $this->claims(['email' => 'a@zurcher.edu.mg', 'email_verified' => false, 'sub' => '1']);

        $this->google()->assertStatus(403);
        $this->assertSame('email_not_verified', $this->lastDetails('google_login_rejected')->reason);
    }

    public function test_an_address_without_account_gets_a_clear_message_and_is_logged(): void
    {
        $this->claims(['email' => 'nouveau@zurcher.edu.mg', 'email_verified' => true, 'sub' => '1']);

        $this->google()
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth.google_unknown_account');

        $this->assertSame('user_not_found', $this->lastDetails('google_login_user_not_found')->reason);
    }

    public function test_an_inactive_account_is_treated_as_unknown(): void
    {
        User::factory()->inactive()->create(['mail' => 'ancien@zurcher.edu.mg']);
        $this->claims(['email' => 'ancien@zurcher.edu.mg', 'email_verified' => true, 'sub' => '1']);

        $this->google()->assertStatus(401)->assertJsonPath('code', 'auth.google_unknown_account');
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $this->claims(null);

        $this->google()->assertStatus(401)->assertJsonPath('code', 'auth.google_invalid_token');
        $this->assertSame('invalid_token', $this->lastDetails('google_login_rejected')->reason);
    }

    public function test_the_credential_is_required(): void
    {
        $this->fromApp()->postJson('/api/v1/auth/google', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }
}
