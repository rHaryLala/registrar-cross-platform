<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Messages;
use App\Domain\Admissions\Notifier;
use App\Domain\Admissions\Sms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Envoi des notifications aux candidats : courriel, SMS, reprises espacées, décision changée entre-temps. */
class NotifierTest extends TestCase
{
    private string $smsLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        config(['mail.default' => 'array', 'registrar.sms.enabled' => true, 'registrar.sms.provider' => 'journal']);
        $this->smsLog = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sms-test-'.getmypid().'.log';
        @unlink($this->smsLog);
        config(['registrar.sms.log_path' => $this->smsLog]);
    }

    protected function tearDown(): void
    {
        @unlink($this->smsLog);
        parent::tearDown();
    }

    private function candidate(string $status = 'accepte', array $over = []): int
    {
        return DB::table('candidat')->insertGetId(array_merge([
            'nom_complet' => 'Zo Rabe', 'telephone' => '034 12 345 67', 'email' => 'zo@exemple.test', 'mention' => 'INFO', 'mention_libelle' => 'Informatique', 'diplome_vise' => 'licence',
            'statut' => $status, 'date_creation' => '2026-09-10 10:00:00',
        ], $over));
    }

    private function task(int $candidate, string $channel, string $decision = 'accepte', array $over = []): int
    {
        return DB::table('t_candidat_notification')->insertGetId(array_merge([
            'candidat_id' => $candidate, 'canal' => $channel, 'decision' => $decision, 'statut' => 'en_attente', 'essais' => 0, 'cree_le' => '2026-11-15 09:59:00',
        ], $over));
    }

    private function row(int $id): object
    {
        return DB::table('t_candidat_notification')->where('id', $id)->first();
    }

    private function sentMails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    private function dispatch(): array
    {
        return app(Notifier::class)->dispatchDue();
    }

    private function twilio(int $status = 201): void
    {
        config(['registrar.sms.provider' => 'twilio', 'registrar.sms.twilio_sid' => 'ACtest', 'registrar.sms.twilio_token' => 'secret', 'registrar.sms.twilio_from' => '+15005550006']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], $status)]);
    }

    // ------------------------------------------------------------------ envoi

    public function test_an_email_is_sent_with_the_decision_text_and_recorded(): void
    {
        $c = $this->candidate('accepte');
        $t = $this->task($c, 'email');

        $this->assertSame(['sent' => 1, 'failed' => 0, 'retry' => 0, 'skipped' => 0], $this->dispatch());

        $mails = $this->sentMails();
        $this->assertCount(1, $mails);
        $msg = $mails[0]->getOriginalMessage();
        $this->assertSame('zo@exemple.test', $msg->getTo()[0]->getAddress());
        $this->assertSame('Votre candidature a été acceptée — Université Adventiste Zurcher', $msg->getSubject());
        $this->assertStringContainsString('Bonjour Zo Rabe', $msg->getTextBody());
        $this->assertStringContainsString('<strong>acceptée</strong>', $msg->getHtmlBody());
        $this->assertSame(['envoyee', 1, null, '2026-11-15 10:00:00'], [$this->row($t)->statut, $this->row($t)->essais, $this->row($t)->prochain_essai, $this->row($t)->traite_le]);
        $this->assertSame('2026-11-15 10:00:00', DB::table('candidat')->where('id', $c)->value('notifie_le'));
        $this->assertNull(DB::table('candidat')->where('id', $c)->value('notifie_sms_le'));
    }

    public function test_a_refusal_has_its_own_subject(): void
    {
        $c = $this->candidate('refuse');
        $this->task($c, 'email', 'refuse');

        $this->dispatch();

        $this->assertSame('Suite donnée à votre candidature — Université Adventiste Zurcher', $this->sentMails()[0]->getOriginalMessage()->getSubject());
    }

    public function test_an_sms_goes_to_the_journal_without_accents_in_one_message(): void
    {
        $c = $this->candidate('accepte');
        $t = $this->task($c, 'sms');

        $this->dispatch();

        $log = (string) file_get_contents($this->smsLog);
        $this->assertStringContainsString('+261341234567', $log);
        $this->assertStringContainsString('UAZ : votre candidature en Informatique est acceptee.', $log);
        $this->assertStringContainsString('1 SMS', $log);
        $this->assertSame('envoyee', $this->row($t)->statut);
        $this->assertSame('2026-11-15 10:00:00', DB::table('candidat')->where('id', $c)->value('notifie_sms_le'));
        $this->assertNull(DB::table('candidat')->where('id', $c)->value('notifie_le'));
    }

    public function test_twilio_receives_the_request_and_a_refusal_is_a_failure_without_leaking_secrets(): void
    {
        $this->twilio(400);
        $t = $this->task($this->candidate(), 'sms');

        $this->assertSame(1, $this->dispatch()['retry']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/Accounts/ACtest/Messages.json') && $r['To'] === '+261341234567' && $r['From'] === '+15005550006' && str_contains($r['Body'], 'acceptee'));
        $this->assertSame('Refusé par le fournisseur (HTTP 400).', $this->row($t)->raison);
        $this->assertStringNotContainsString('secret', (string) $this->row($t)->raison);
    }

    public function test_twilio_success_marks_it_sent(): void
    {
        $this->twilio(201);
        $t = $this->task($this->candidate(), 'sms');

        $this->assertSame(1, $this->dispatch()['sent']);
        $this->assertSame('envoyee', $this->row($t)->statut);
    }

    public function test_twilio_without_configuration_sends_nothing(): void
    {
        config(['registrar.sms.provider' => 'twilio', 'registrar.sms.twilio_sid' => '', 'registrar.sms.twilio_token' => '', 'registrar.sms.twilio_from' => '']);
        Http::fake();
        $t = $this->task($this->candidate(), 'sms');

        $this->dispatch();

        Http::assertNothingSent();
        $this->assertStringContainsString('Twilio n’est pas configuré', $this->row($t)->raison);
    }

    public function test_sms_can_be_switched_off_by_configuration(): void
    {
        config(['registrar.sms.enabled' => false]);
        $t = $this->task($this->candidate(), 'sms');

        $this->dispatch();

        $this->assertFileDoesNotExist($this->smsLog);
        $this->assertSame('Envoi SMS désactivé par la configuration.', $this->row($t)->raison);
    }

    // ------------------------------------------------------------------ reprises

    public function test_a_failure_is_retried_after_one_then_five_minutes_and_ends_as_a_failure_that_does_not_restart(): void
    {
        $this->twilio(500);
        $t = $this->task($this->candidate(), 'sms');

        $this->assertSame(1, $this->dispatch()['retry']);
        $this->assertSame([1, 'en_attente', '2026-11-15 10:01:00'], [$this->row($t)->essais, $this->row($t)->statut, $this->row($t)->prochain_essai]);

        $this->assertSame(0, array_sum($this->dispatch()), 'pas avant l’heure prévue');

        $this->travelTo('2026-11-15 10:01:00');
        $this->assertSame(1, $this->dispatch()['retry']);
        $this->assertSame([2, '2026-11-15 10:06:00'], [$this->row($t)->essais, $this->row($t)->prochain_essai]);

        $this->travelTo('2026-11-15 10:06:00');
        $this->assertSame(1, $this->dispatch()['failed']);
        $this->assertSame(['echec', 3, null], [$this->row($t)->statut, $this->row($t)->essais, $this->row($t)->prochain_essai]);

        $this->travelTo('2026-11-15 12:00:00');
        $this->assertSame(0, array_sum($this->dispatch()), 'une tâche en échec ne repart pas toute seule');
        Http::assertSentCount(3);
    }

    public function test_the_third_wait_is_thirty_minutes_when_more_attempts_are_allowed(): void
    {
        config(['registrar.admissions.max_attempts' => 4]);
        $this->twilio(500);
        $t = $this->task($this->candidate(), 'sms', 'accepte', ['essais' => 2]);

        $this->dispatch();

        $this->assertSame([3, '2026-11-15 10:30:00'], [$this->row($t)->essais, $this->row($t)->prochain_essai]);
    }

    public function test_a_mail_transport_error_is_a_failure_not_a_crash_and_does_not_block_the_next_tasks(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $t = $this->task($this->candidate(), 'email');
        $other = $this->task($this->candidate(), 'email');

        $r = $this->dispatch();

        $this->assertSame(2, $r['retry']);
        $this->assertSame('Le courriel n’a pas pu être envoyé.', $this->row($t)->raison);
        $this->assertSame(1, $this->row($other)->essais);
    }

    // ------------------------------------------------------------------ garde-fous

    public function test_nothing_leaves_if_the_decision_changed_in_the_meantime(): void
    {
        $c = $this->candidate('refuse');
        $t = $this->task($c, 'email', 'accepte');

        $this->assertSame(1, $this->dispatch()['skipped']);

        $this->assertSame([], $this->sentMails());
        $this->assertSame(['ignoree', 'Décision modifiée avant l’envoi.'], [$this->row($t)->statut, $this->row($t)->raison]);
    }

    public function test_a_missing_or_invalid_contact_at_send_time_closes_the_task(): void
    {
        $a = $this->task($this->candidate('accepte', ['email' => 'pas-un-mail']), 'email');
        $b = $this->task($this->candidate('accepte', ['telephone' => '123']), 'sms');

        $this->assertSame(2, $this->dispatch()['skipped']);

        $this->assertSame(['ignoree', 'ignoree'], [$this->row($a)->statut, $this->row($b)->statut]);
        $this->assertSame([], $this->sentMails());
        $this->assertFileDoesNotExist($this->smsLog);
    }

    public function test_a_deleted_candidate_closes_the_task(): void
    {
        $t = $this->task(999999, 'email');

        $this->assertSame(1, $this->dispatch()['skipped']);
        $this->assertSame('ignoree', $this->row($t)->statut);
    }

    public function test_only_tasks_waiting_and_due_are_picked_and_each_leaves_once(): void
    {
        $c = $this->candidate();
        $future = $this->task($c, 'email', 'accepte', ['prochain_essai' => '2026-11-15 10:05:00']);
        $done = $this->task($c, 'email', 'accepte', ['statut' => 'envoyee']);
        $ignored = $this->task($c, 'sms', 'accepte', ['statut' => 'ignoree']);
        $ready = $this->task($c, 'email', 'accepte', ['prochain_essai' => '2026-11-15 09:00:00']);   // un nouvel essai arrivé à échéance

        $this->assertSame(1, $this->dispatch()['sent']);
        $this->assertSame(0, array_sum($this->dispatch()), 'un second passage ne renvoie rien');

        $this->assertCount(1, $this->sentMails());
        $this->assertSame(['en_attente', 'envoyee', 'ignoree', 'envoyee'], [$this->row($future)->statut, $this->row($done)->statut, $this->row($ignored)->statut, $this->row($ready)->statut]);
    }

    public function test_a_task_being_sent_is_reserved_against_a_second_run(): void
    {
        $t = $this->task($this->candidate(), 'email');
        DB::table('t_candidat_notification')->where('id', $t)->update(['prochain_essai' => '2026-11-15 10:10:00']);   // une première exécution l'a réservée

        $this->assertSame(0, array_sum($this->dispatch()));
        $this->assertSame([], $this->sentMails());
    }

    public function test_at_most_fifty_tasks_per_run(): void
    {
        $c = $this->candidate();
        for ($i = 0; $i < 55; $i++) {
            $this->task($c, 'sms');
        }

        $this->assertSame(50, $this->dispatch()['sent']);
        $this->assertSame(5, DB::table('t_candidat_notification')->where('statut', 'en_attente')->count());
    }

    public function test_the_scheduled_command_runs_the_dispatch(): void
    {
        $this->task($this->candidate(), 'email');

        $this->artisan('applications:notify')->expectsOutputToContain('envoyés : 1')->assertSuccessful();
    }

    // ------------------------------------------------------------------ textes et numéros

    public function test_phone_numbers_are_normalised_to_the_malagasy_international_form(): void
    {
        foreach (['0325855186', '032 58 551 86', '+261325855186', '261325855186', '00261325855186', '(032) 58-551-86'] as $ok) {
            $this->assertSame('+261325855186', Sms::normalize($ok), $ok);
        }
        foreach ([null, '', 'abc', '12345', '0225855186', '03258551', '032585518612', '+33612345678', '0 2 5'] as $bad) {
            $this->assertNull(Sms::normalize($bad), (string) $bad);
        }
    }

    public function test_accents_are_removed_from_sms_text(): void
    {
        $this->assertSame('Ete a l\'ecole : "Cafe" - oeuvre...', Sms::simplify('Été à l’école : “Café” — œuvre…'));
    }

    public function test_sms_texts_fit_one_message_and_drop_a_mention_that_is_too_long(): void
    {
        $short = Messages::sms(['mention_libelle' => 'Informatique'], 'accepte');
        $refus = Messages::sms(['mention_libelle' => 'Gestion'], 'refuse');
        $long = Messages::sms(['mention_libelle' => str_repeat('Très longue mention ', 6)], 'accepte');

        $this->assertSame('UAZ : votre candidature en Informatique est acceptee. Presentez-vous au registrariat avec vos pieces justificatives. Tel +261 34 38 180 81', $short);
        $this->assertSame('UAZ : apres examen, votre candidature en Gestion n\'a pas ete retenue. Vous pouvez postuler a une prochaine session. Tel +261 34 38 180 81', $refus);
        $this->assertLessThanOrEqual(160, strlen($short));
        $this->assertSame('UAZ : votre candidature est acceptee. Presentez-vous au registrariat avec vos pieces justificatives. Tel +261 34 38 180 81', $long);
        $this->assertNull(Messages::sms([], 'en_cours'));
        $this->assertTrue(Messages::isMaster(['diplome_vise' => ' MASTER ']));
        $this->assertFalse(Messages::isMaster(['diplome_vise' => 'licence']));
        $this->assertFalse(Messages::isMaster([]));
        $this->assertSame(Messages::sms(['mention' => 'INFO'], 'accepte'), Messages::sms(['mention_libelle' => null, 'mention' => 'INFO'], 'accepte'));
    }

    public function test_emails_escape_what_the_applicant_typed(): void
    {
        $m = Messages::email(['nom_complet' => '<script>x</script> Zo', 'mention_libelle' => 'A&B'], 'accepte');

        $this->assertStringNotContainsString('<script>', $m['html']);
        $this->assertStringContainsString('&lt;script&gt;', $m['html']);
        $this->assertStringContainsString('A&amp;B', $m['html']);
        $this->assertStringContainsString('<script>', $m['text'], 'le texte brut n’est pas du HTML');
        $this->assertNull(Messages::email(['nom_complet' => 'Zo'], 'nouveau'));
        $this->assertStringContainsString('registrar@zurcher.edu.mg', $m['html']);
    }

    public function test_the_master_message_carries_the_address_and_password_and_says_when_it_is_active(): void
    {
        $c = ['nom_complet' => 'Zo Rabe', 'mention' => 'INFO', 'mention_libelle' => 'Informatique'];

        $created = Messages::identifiers($c, 'zo.rabe@zurcher.edu.mg', 'Mot2passe!', true);
        $failed = Messages::identifiers($c, 'zo.rabe@zurcher.edu.mg', 'Mot2passe!', false);
        $before = Messages::identifiers($c, 'zo.rabe@zurcher.edu.mg', 'Mot2passe!', null, '2026-10-01');
        $after = Messages::identifiers($c, 'zo.rabe@zurcher.edu.mg', 'Mot2passe!', null, '2026-10-11');

        $this->assertSame('Votre candidature en Master a été acceptée — Université Adventiste Zurcher', $created['subject']);
        $this->assertStringContainsString('Adresse : zo.rabe@zurcher.edu.mg', $created['text']);
        $this->assertStringContainsString('Mot de passe : Mot2passe!', $created['text']);
        $this->assertStringContainsString('mention Informatique', $created['text']);
        $this->assertStringContainsString('active dès maintenant', $created['text']);
        $this->assertStringContainsString('sera activée sous peu', $failed['text']);
        $this->assertStringContainsString('sera active à partir du dimanche 11 octobre 2026', $before['text']);
        $this->assertStringContainsString('Cette adresse est active.', $after['text']);
    }
}
