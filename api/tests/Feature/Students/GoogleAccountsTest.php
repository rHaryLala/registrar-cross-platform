<?php

namespace Tests\Feature\Students;

use App\Domain\Admissions\Notifier;
use App\Domain\Students\GoogleAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Comptes Google des étudiants créés : tâche planifiée, reprises espacées, courriel Master qui attend l'issue. */
class GoogleAccountsTest extends TestCase
{
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        Cache::flush();
        config(['registrar.google_admin.key_path' => base_path('tests/fixtures/google-test-key.json'), 'mail.default' => 'array']);
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    /** Une fiche créée par la nouvelle application, avec sa tâche Google. @return array{0: int, 1: int} fiche, tâche */
    private function created(string $status = 'en_attente', array $over = []): array
    {
        $fiche = DB::table('tbl_2024_etudiant')->insertGetId([
            'student_id' => 'G'.(++$this->n), 'student_nom' => 'Rabe', 'student_prenom' => 'Zo', 'password' => 'Mot2passe!', 'abonment' => 0, 'annee_scolaire' => '2026 - 2027', 'period' => '', 'remove' => 0,
            'student_email' => 'rabe.zo'.$this->n.'@zurcher.edu.mg', 'etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux', 'student_tel' => '034 12 345 67', 'student_adresse' => 'Lot 12',
        ]);
        $task = DB::table('t_etudiant_creation')->insertGetId(array_merge([
            'jeton' => bin2hex(random_bytes(16)), 'fiche_id' => $fiche, 'student_id' => 'G'.$this->n, 'adresse' => 'rabe.zo'.$this->n.'@zurcher.edu.mg', 'google_statut' => $status, 'google_essais' => 0, 'cree_le' => '2026-11-15 09:59:00',
        ], $over));

        return [$fiche, $task];
    }

    private function row(int $id): object
    {
        return DB::table('t_etudiant_creation')->where('id', $id)->first();
    }

    private function fakeGoogle(int $users = 200, array $body = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton-test', 'expires_in' => 3600], 200),
            'admin.googleapis.com/*' => Http::response($body, $users),
        ]);
    }

    private function runCommand(): string
    {
        \Illuminate\Support\Facades\Artisan::call('students:google-accounts');

        return \Illuminate\Support\Facades\Artisan::output();
    }

    // ------------------------------------------------------------------ création du compte

    public function test_the_account_is_created_with_the_fiche_data_and_a_signed_token(): void
    {
        $this->fakeGoogle(200);
        [, $t] = $this->created();

        $out = $this->runCommand();

        $this->assertStringContainsString('créés : 1', $out);
        $row = $this->row($t);
        $this->assertSame(['cree', 1, null], [$row->google_statut, $row->google_essais, $row->google_prochain_essai]);
        $this->assertStringContainsString('fonctionne dès maintenant', $row->google_raison);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'oauth2.googleapis.com')) {
                return false;
            }
            [$h, $p, $s] = explode('.', $r['assertion']);
            $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $claims['sub'] === 'it@zurcher.edu.mg' && $claims['iss'] === 'test-service@registrar-test.iam.gserviceaccount.com'
                && $claims['scope'] === 'https://www.googleapis.com/auth/admin.directory.user' && $claims['exp'] - $claims['iat'] === 3600 && $s !== '' && json_decode(base64_decode(strtr($h, '-_', '+/')), true)['alg'] === 'RS256';
        });
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'admin.googleapis.com/admin/directory/v1/users')) {
                return false;
            }

            return $r->hasHeader('Authorization', 'Bearer jeton-test') && $r['primaryEmail'] === 'rabe.zo1@zurcher.edu.mg' && $r['password'] === 'Mot2passe!' && $r['changePasswordAtNextLogin'] === false
                && $r['name'] === ['givenName' => 'Zo', 'familyName' => 'Rabe'] && $r['orgUnitPath'] === '/' && $r['externalIds'][0]['value'] === 'G1' && $r['phones'][0]['value'] === '+261341234567'
                && $r['organizations'][0]['department'] === 'Informatique' && $r['organizations'][0]['costCenter'] === 'Réseaux' && $r['addresses'][0]['formatted'] === 'Lot 12';
        });
    }

    public function test_the_token_is_requested_once_for_several_accounts(): void
    {
        $this->fakeGoogle(200);
        $this->created();
        $this->created();

        $this->runCommand();

        Http::assertSentCount(3);   // un jeton + deux créations
    }

    public function test_an_account_that_already_exists_is_reported_not_overwritten(): void
    {
        $this->fakeGoogle(409, ['error' => ['message' => 'Entity already exists.']]);
        [, $t] = $this->created();

        $out = $this->runCommand();

        $this->assertStringContainsString('déjà existants : 1', $out);
        $this->assertSame('existait', $this->row($t)->google_statut);
        $this->assertStringContainsString('existe déjà dans Google', $this->row($t)->google_raison);
    }

    public function test_google_being_down_is_retried_after_one_then_five_minutes_then_a_failure(): void
    {
        $this->fakeGoogle(503);
        [, $t] = $this->created();

        $this->runCommand();
        $this->assertSame([1, 'en_attente', '2026-11-15 10:01:00'], [$this->row($t)->google_essais, $this->row($t)->google_statut, $this->row($t)->google_prochain_essai]);
        $this->assertStringContainsString('momentanément indisponible', $this->row($t)->google_raison);

        $this->assertStringContainsString('à retenter : 0', $this->runCommand(), 'pas avant l’heure prévue');

        $this->travelTo('2026-11-15 10:01:00');
        $this->runCommand();
        $this->assertSame([2, '2026-11-15 10:06:00'], [$this->row($t)->google_essais, $this->row($t)->google_prochain_essai]);

        $this->travelTo('2026-11-15 10:06:00');
        $this->assertStringContainsString('échecs : 1', $this->runCommand());
        $this->assertSame(['echec', 3, null], [$this->row($t)->google_statut, $this->row($t)->google_essais, $this->row($t)->google_prochain_essai]);

        $this->travelTo('2026-11-15 12:00:00');
        $this->assertStringContainsString('échecs : 0', $this->runCommand(), 'un échec ne repart pas tout seul');
    }

    public function test_a_refusal_of_rights_says_which_administrator_was_used(): void
    {
        $this->fakeGoogle(403, ['error' => ['message' => 'Not Authorized to access this resource/api']]);
        [, $t] = $this->created();

        $this->runCommand();

        $this->assertStringContainsString('it@zurcher.edu.mg', $this->row($t)->google_raison);
        $this->assertStringContainsString('Not Authorized', $this->row($t)->google_raison);
    }

    public function test_a_google_error_on_the_token_gives_a_readable_reason(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized'], 400)]);
        [, $t] = $this->created();

        $this->runCommand();

        $this->assertStringContainsString('délégation à l’échelle du domaine', $this->row($t)->google_raison);
        Http::assertSentCount(1);
    }

    public function test_an_address_google_would_refuse_is_not_even_tried(): void
    {
        Http::fake();
        [, $t] = $this->created('en_attente', ['adresse' => 'rabé zo@zurcher.edu.mg']);
        [, $other] = $this->created('en_attente', ['adresse' => 'zo@autre-domaine.org']);

        $this->runCommand();

        Http::assertNothingSent();
        $this->assertStringContainsString('n’est pas une adresse que Google accepte', $this->row($t)->google_raison);
        $this->assertStringContainsString('n’est pas une adresse que Google accepte', $this->row($other)->google_raison);
    }

    public function test_a_password_shorter_than_eight_characters_is_not_sent_to_google(): void
    {
        Http::fake();
        [$fiche, $t] = $this->created();
        DB::table('tbl_2024_etudiant')->where('id', $fiche)->update(['password' => 'court']);

        $this->runCommand();

        Http::assertNothingSent();
        $this->assertStringContainsString('moins de 8 caractères', $this->row($t)->google_raison);
    }

    public function test_without_the_key_nothing_is_created_and_nothing_is_sent(): void
    {
        config(['registrar.google_admin.key_path' => '']);
        Http::fake();
        [, $t] = $this->created();

        $this->runCommand();

        Http::assertNothingSent();
        $this->assertStringContainsString('n’est pas configurée', $this->row($t)->google_raison);
        $this->assertFalse(GoogleAdmin::configured());
        $this->assertNull(GoogleAdmin::addressExists('x@zurcher.edu.mg'));
    }

    public function test_tasks_not_due_or_already_finished_are_left_alone_and_a_missing_fiche_fails(): void
    {
        $this->fakeGoogle(200);
        $this->created('cree');
        $this->created('echec');
        [, $later] = $this->created('en_attente', ['google_prochain_essai' => '2026-11-15 10:05:00']);
        [$gone, $orphan] = $this->created();
        DB::table('tbl_2024_etudiant')->where('id', $gone)->delete();

        $out = $this->runCommand();

        $this->assertStringContainsString('échecs : 1', $out);
        $this->assertSame('en_attente', $this->row($later)->google_statut);
        $this->assertSame(['echec', 'Fiche ou adresse introuvable.'], [$this->row($orphan)->google_statut, $this->row($orphan)->google_raison]);
        Http::assertNothingSent();
    }

    public function test_at_most_thirty_accounts_are_created_per_run(): void
    {
        $this->fakeGoogle(200);
        for ($i = 0; $i < 33; $i++) {
            $this->created();
        }

        $this->assertStringContainsString('créés : 30', $this->runCommand());
        $this->assertSame(3, DB::table('t_etudiant_creation')->where('google_statut', 'en_attente')->count());
    }

    public function test_a_task_being_processed_is_reserved_against_a_second_run(): void
    {
        $this->fakeGoogle(200);
        [, $t] = $this->created('en_attente', ['google_prochain_essai' => '2026-11-15 10:10:00']);

        $this->runCommand();

        Http::assertNothingSent();
        $this->assertSame('en_attente', $this->row($t)->google_statut);
    }

    public function test_the_address_check_answers_exists_absent_or_unknown(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            'admin.googleapis.com/admin/directory/v1/users/pris%40zurcher.edu.mg*' => Http::response(['primaryEmail' => 'pris@zurcher.edu.mg'], 200),
            'admin.googleapis.com/admin/directory/v1/users/libre%40zurcher.edu.mg*' => Http::response(['error' => []], 404),
            'admin.googleapis.com/*' => Http::response([], 500),
        ]);

        $this->assertTrue(GoogleAdmin::addressExists('Pris@zurcher.edu.mg'));
        $this->assertFalse(GoogleAdmin::addressExists('libre@zurcher.edu.mg'));
        $this->assertNull(GoogleAdmin::addressExists('autre@zurcher.edu.mg'));
    }

    // ------------------------------------------------------------------ relance

    public function test_retry_puts_a_failed_or_unconfigured_account_back_in_line(): void
    {
        [$a, $ta] = $this->created('echec', ['google_essais' => 3, 'google_raison' => 'x']);
        [$b, $tb] = $this->created('non_configure');
        [$c] = $this->created('cree');
        [$d] = $this->created('en_attente');
        $reg = User::factory()->staff(3, 'registrar')->create();

        $this->api($reg)->postJson("/api/v1/staff/students/{$a}/google/retry")->assertOk()->assertJsonPath('data.status', 'en_attente');
        $this->api($reg)->postJson("/api/v1/staff/students/{$b}/google/retry")->assertOk();
        $this->assertSame(['en_attente', 0, null, null], [$this->row($ta)->google_statut, $this->row($ta)->google_essais, $this->row($ta)->google_prochain_essai, $this->row($ta)->google_raison]);
        $this->api($reg)->postJson("/api/v1/staff/students/{$c}/google/retry")->assertStatus(409)->assertJsonPath('code', 'students.google_not_retryable');
        $this->api($reg)->postJson("/api/v1/staff/students/{$d}/google/retry")->assertStatus(409);
        $this->api($reg)->postJson('/api/v1/staff/students/999999/google/retry')->assertNotFound();
        $this->assertSame('en_attente', $this->row($tb)->google_statut);
    }

    public function test_retry_is_refused_when_google_is_not_configured_here(): void
    {
        config(['registrar.google_admin.key_path' => '']);
        [$a] = $this->created('echec');

        $this->api(User::factory()->staff(3, 'registrar')->create())->postJson("/api/v1/staff/students/{$a}/google/retry")->assertStatus(409)->assertJsonPath('code', 'students.google_not_configured');
    }

    // ------------------------------------------------------------------ courriel Master

    private function masterTask(string $google): array
    {
        [$fiche] = $this->created($google);
        $student = DB::table('tbl_2024_etudiant')->where('id', $fiche)->first();
        $candidate = DB::table('candidat')->insertGetId([
            'nom_complet' => 'Zo Rabe', 'telephone' => '034 12 345 67', 'email' => 'zo@exemple.test', 'mention' => 'informatique', 'mention_libelle' => 'Informatique', 'diplome_vise' => 'master',
            'statut' => 'converti', 'student_id' => $student->student_id, 'date_creation' => '2026-09-10 10:00:00',
        ]);
        $task = DB::table('t_candidat_notification')->insertGetId(['candidat_id' => $candidate, 'canal' => 'email', 'decision' => 'identifiants', 'statut' => 'en_attente', 'essais' => 0, 'cree_le' => '2026-11-15 09:59:00']);

        return [$task, $student];
    }

    private function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    public function test_the_master_email_waits_for_the_google_outcome_then_says_the_address_is_active(): void
    {
        [$task, $student] = $this->masterTask('en_attente');
        $notifier = app(Notifier::class);

        $this->assertSame(1, $notifier->dispatchDue()['retry'], 'le compte est en cours de création : on attend');
        $this->assertSame([], $this->sent());
        $row = DB::table('t_candidat_notification')->where('id', $task)->first();
        $this->assertSame([0, 'en_attente', '2026-11-15 10:01:00'], [$row->essais, $row->statut, $row->prochain_essai], 'attendre n’use pas un essai');

        DB::table('t_etudiant_creation')->where('student_id', $student->student_id)->update(['google_statut' => 'cree']);
        $this->travelTo('2026-11-15 10:01:00');
        $this->assertSame(1, $notifier->dispatchDue()['sent']);

        $text = $this->sent()[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('Adresse : '.$student->student_email, $text);
        $this->assertStringContainsString('Mot de passe : Mot2passe!', $text);
        $this->assertStringContainsString('active dès maintenant', $text);
    }

    public function test_the_master_email_adapts_to_the_google_outcome(): void
    {
        foreach (['echec' => 'sera activée sous peu', 'non_configure' => 'sera active à partir du dimanche 11 octobre 2026'] as $google => $phrase) {
            $this->travelTo('2026-09-01 10:00:00');
            [$task] = $this->masterTask($google);

            app(Notifier::class)->dispatchDue();

            $all = $this->sent();
            $this->assertStringContainsString($phrase, $all[array_key_last($all)]->getOriginalMessage()->getTextBody(), $google);
        }
    }

    public function test_the_master_email_is_skipped_when_the_fiche_has_no_address_or_password(): void
    {
        [$task, $student] = $this->masterTask('cree');
        DB::table('tbl_2024_etudiant')->where('id', $student->id)->update(['password' => '']);

        $this->assertSame(1, app(Notifier::class)->dispatchDue()['skipped']);
        $this->assertSame([], $this->sent());
        $this->assertSame('ignoree', DB::table('t_candidat_notification')->where('id', $task)->value('statut'));
    }

    public function test_the_master_email_only_leaves_for_a_converted_application(): void
    {
        [$task, $student] = $this->masterTask('cree');
        DB::table('candidat')->where('student_id', $student->student_id)->update(['statut' => 'accepte']);

        $this->assertSame(1, app(Notifier::class)->dispatchDue()['skipped']);
        $this->assertSame([], $this->sent());
    }
}
