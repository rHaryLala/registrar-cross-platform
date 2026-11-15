<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private function login(string $identifier, string $password = UserFactory::PASSWORD)
    {
        return $this->fromApp()->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => $password]);
    }

    private function lastLog(string $event): ?object
    {
        return DB::table('security_logs')->where('event_type', $event)->orderByDesc('id')->first();
    }

    public function test_login_by_pseudo_opens_a_session_and_is_logged(): void
    {
        $user = User::factory()->create(['pseudo' => 'fanja']);

        $this->login('fanja')
            ->assertOk()
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user, 'web');
        $log = $this->lastLog('login_success');
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_login_by_unique_email_works(): void
    {
        $user = User::factory()->create(['mail' => 'solo@zurcher.edu.mg']);

        $this->login('SOLO@zurcher.edu.mg')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_an_email_shared_by_two_active_accounts_is_refused_not_guessed(): void
    {
        User::factory()->count(2)->create(['mail' => 'partage@zurcher.edu.mg']);

        $this->login('partage@zurcher.edu.mg')
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth.ambiguous_email');

        $this->assertGuest('web');
        $this->assertSame('ambiguous_email', json_decode($this->lastLog('login_failed')->details)->reason);
    }

    public function test_wrong_password_and_unknown_account_get_the_same_answer(): void
    {
        User::factory()->create(['pseudo' => 'existe']);

        $wrong = $this->login('existe', 'mauvais')->assertStatus(401)->assertJsonPath('code', 'auth.invalid_credentials');
        $unknown = $this->login('inconnu-total', 'mauvais')->assertStatus(401)->assertJsonPath('code', 'auth.invalid_credentials');

        $this->assertSame($wrong->json('title'), $unknown->json('title'));
        $this->assertSame($wrong->json('detail'), $unknown->json('detail'));
        $this->assertSame('application/problem+json', $wrong->headers->get('Content-Type'));
    }

    public function test_failures_are_logged_with_their_reason_but_never_the_typed_identifier(): void
    {
        $known = User::factory()->create(['pseudo' => 'connu']);

        $this->login('connu', 'mauvais');
        $bad = $this->lastLog('login_failed');
        $this->assertSame($known->id, $bad->user_id);
        $this->assertSame('bad_password', json_decode($bad->details)->reason);

        // Un utilisateur qui tape son mot de passe dans le champ identifiant ne doit pas le laisser dans le journal.
        $this->login('MonMotDePasseSecret42', 'x');
        $unknown = $this->lastLog('login_failed');
        $this->assertNull($unknown->user_id);
        $this->assertSame('unknown_account', json_decode($unknown->details)->reason);
        $this->assertStringNotContainsString('MonMotDePasseSecret42', $unknown->details);
        $this->assertStringNotContainsString('mauvais', DB::table('security_logs')->pluck('details')->implode(' '));
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['pseudo' => 'suspendu']);

        $this->login('suspendu')->assertStatus(401);
        $this->assertGuest('web');
    }

    public function test_a_legacy_sha256_password_works_and_is_converted_to_bcrypt(): void
    {
        $user = User::factory()->legacyPassword()->create(['pseudo' => 'ancien']);
        $this->assertSame(64, strlen($user->password));

        $this->login('ancien')->assertOk();

        $this->assertStringStartsWith('$2y$', User::find($user->id)->password);
        // Et le même mot de passe fonctionne encore avec la nouvelle empreinte.
        $this->login('ancien')->assertOk();
    }

    public function test_a_converted_legacy_account_stays_logged_in_on_the_next_request(): void
    {
        // Régression : la session mémorisait l'ANCIENNE empreinte (sha256) alors que la base venait de passer en bcrypt ;
        // le middleware AuthenticateSession déconnectait alors l'étudiant à la requête suivante.
        User::factory()->legacyPassword()->create(['pseudo' => 'ancien3']);

        $this->login('ancien3')->assertOk();

        // Un vrai serveur relit l'utilisateur en base à chaque requête : on oublie celui que la garde a en mémoire.
        $this->app['auth']->forgetGuards();

        $this->fromApp()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role', 'student');
    }

    public function test_a_wrong_password_on_a_legacy_account_leaves_it_untouched(): void
    {
        $user = User::factory()->legacyPassword()->create(['pseudo' => 'ancien2']);
        $before = $user->password;

        $this->login('ancien2', 'faux')->assertStatus(401);

        $this->assertSame($before, User::find($user->id)->password);
    }

    public function test_missing_fields_return_a_structured_validation_error(): void
    {
        $this->fromApp()->postJson('/api/v1/auth/login', ['identifier' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_a_request_that_does_not_come_from_the_app_is_refused(): void
    {
        User::factory()->create(['pseudo' => 'sansorigine']);

        $this->postJson('/api/v1/auth/login', ['identifier' => 'sansorigine', 'password' => UserFactory::PASSWORD])
            ->assertStatus(400)
            ->assertJsonPath('code', 'client.not_stateful');
    }

    public function test_the_eleventh_attempt_in_fifteen_minutes_is_throttled(): void
    {
        User::factory()->create(['pseudo' => 'cible']);

        for ($i = 0; $i < 10; $i++) {
            $this->login('cible', 'faux')->assertStatus(401);
        }

        $this->login('cible', 'faux')
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertJsonStructure(['retry_after']);
    }

    public function test_me_requires_a_session_then_returns_the_account_without_secrets(): void
    {
        $this->fromApp()->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth.unauthenticated');

        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromApp()->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.level', 8)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.pseudo');
    }

    public function test_logout_closes_the_session_and_is_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromApp()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame($user->id, $this->lastLog('logout')->user_id);
    }
}
