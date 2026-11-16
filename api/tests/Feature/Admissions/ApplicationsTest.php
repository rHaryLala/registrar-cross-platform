<?php

namespace Tests\Feature\Admissions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Candidatures de pré-admission : liste, fiche, instruction (statut), archivage, tâches de notification. */
class ApplicationsTest extends TestCase
{
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function registrar(array $attrs = []): User
    {
        return User::factory()->staff(3, 'registrar')->create($attrs);
    }

    private function candidate(string $status = 'nouveau', array $over = []): int
    {
        return DB::table('candidat')->insertGetId(array_merge([
            'nom_complet' => 'Candidat '.(++$this->n), 'telephone' => '034 12 345 67', 'email' => 'c'.$this->n.'@exemple.test', 'mention' => 'INFO', 'mention_libelle' => 'Informatique',
            'diplome_vise' => 'Licence', 'statut' => $status, 'date_creation' => '2026-09-10 10:00:00',
        ], $over));
    }

    private function list(User $who, string $query = '')
    {
        return $this->api($who)->getJson('/api/v1/staff/applications'.$query);
    }

    private function setStatus(User $who, int $id, string $status)
    {
        return $this->api($who)->postJson("/api/v1/staff/applications/{$id}/status", ['status' => $status]);
    }

    private function tasks(int $id): array
    {
        return DB::table('t_candidat_notification')->where('candidat_id', $id)->orderBy('id')->get()->map(fn ($t) => [$t->canal, $t->decision, $t->statut])->all();
    }

    // ------------------------------------------------------------------ accès

    public function test_only_superadmin_and_registrar_can_use_applications(): void
    {
        $id = $this->candidate();
        $denied = [User::factory()->staff(2, 'admin')->create(), User::factory()->staff(4, 'comptabilite')->create(), User::factory()->staff(6, 'chef_mention')->create(),
            User::factory()->staff(7, 'teacher')->create(), User::factory()->create(), User::factory()->staff(3, 'registrar')->inactive()->create()];

        foreach ($denied as $who) {
            $this->list($who)->assertForbidden()->assertJsonPath('code', 'forbidden.registrar_only');
            $this->api($who)->getJson("/api/v1/staff/applications/{$id}")->assertForbidden();
            $this->setStatus($who, $id, 'accepte')->assertForbidden();
            $this->api($who)->postJson("/api/v1/staff/applications/{$id}/archive", ['archive' => true])->assertForbidden();
            $this->api($who)->postJson("/api/v1/staff/applications/{$id}/notifications/retry")->assertForbidden();
        }
        $this->assertSame('nouveau', DB::table('candidat')->where('id', $id)->value('statut'));
        $this->assertSame(0, DB::table('t_candidat_notification')->count());
        $this->list($this->registrar())->assertOk();
        $this->list(User::factory()->staff(1, 'superadmin')->create())->assertOk();
    }

    public function test_a_session_is_required(): void
    {
        $this->getJson('/api/v1/staff/applications')->assertUnauthorized();
        $this->postJson('/api/v1/staff/applications/1/status', ['status' => 'accepte'])->assertUnauthorized();
    }

    public function test_the_profile_tells_who_can_open_applications(): void
    {
        $flag = fn (User $u) => $this->api($u)->getJson('/api/v1/auth/me')->json('data.applications');

        $this->assertTrue($flag($this->registrar()));
        $this->assertTrue($flag(User::factory()->staff(1, 'superadmin')->create()));
        $this->assertFalse($flag(User::factory()->staff(2, 'admin')->create()));
        $this->assertFalse($flag(User::factory()->create()));
    }

    // ------------------------------------------------------------------ liste

    public function test_the_list_counts_by_status_without_the_archives_and_newest_first(): void
    {
        $a = $this->candidate('nouveau', ['date_creation' => '2026-09-01 08:00:00']);
        $b = $this->candidate('nouveau', ['date_creation' => '2026-09-03 08:00:00']);
        $this->candidate('en_cours');
        $this->candidate('accepte');
        $this->candidate('converti');
        $this->candidate('nouveau', ['archive_le' => '2026-10-01 10:00:00']);   // rangée : hors compteurs

        $d = $this->list($this->registrar())->assertOk()->json('data');

        $this->assertSame(['nouveau' => 2, 'en_cours' => 1, 'accepte' => 1, 'refuse' => 0, 'converti' => 1], $d['counts']);
        $this->assertSame([5, 1, ''], [$d['total'], $d['archived'], $d['filter']]);
        $this->assertSame([$b, $a], array_slice(array_column(collect($d['rows'])->where('status', 'nouveau')->values()->all(), 'id'), 0, 2), 'les plus récentes d’abord');
        $this->assertSame([null], array_values(array_unique(array_column($d['rows'], 'archived_at'))), 'aucune archive dans la liste par défaut');
        $this->assertCount(5, $d['rows']);
    }

    public function test_filters_by_status_and_archive_and_ignores_an_unknown_one(): void
    {
        $new = $this->candidate('nouveau');
        $done = $this->candidate('refuse');
        $old = $this->candidate('accepte', ['archive_le' => '2026-10-01 10:00:00', 'date_creation' => '2026-09-20 10:00:00']);
        $later = $this->candidate('nouveau', ['archive_le' => '2026-10-05 10:00:00', 'date_creation' => '2026-09-01 10:00:00']);
        $oldRefused = $this->candidate('refuse', ['date_creation' => '2026-08-01 10:00:00']);
        $reg = $this->registrar();

        $this->assertSame([$new], array_column($this->list($reg, '?status=nouveau')->json('data.rows'), 'id'));
        $this->assertSame([$done, $oldRefused], array_column($this->list($reg, '?status=refuse')->json('data.rows'), 'id'), 'les plus récentes d’abord');
        $this->assertSame([$later, $old], array_column($this->list($reg, '?status=archive')->json('data.rows'), 'id'), 'les archives, par date de rangement');
        $this->assertSame(3, count($this->list($reg, '?status=inconnu')->json('data.rows')));
        $this->assertSame('', $this->list($reg, '?status=inconnu')->json('data.filter'));
    }

    public function test_the_latest_notification_of_each_channel_and_decision_is_the_one_shown(): void
    {
        $id = $this->candidate('accepte');
        $row = ['candidat_id' => $id, 'canal' => 'email', 'decision' => 'accepte', 'essais' => 3, 'cree_le' => '2026-11-14 10:00:00'];
        DB::table('t_candidat_notification')->insert([$row + ['statut' => 'echec', 'raison' => 'ancien'], $row + ['statut' => 'envoyee', 'raison' => null]]);

        $notices = collect($this->list($this->registrar())->json('data.rows'))->firstWhere('id', $id)['notifications'];

        $this->assertCount(1, $notices);
        $this->assertSame('envoyee', $notices[0]['state']);
    }

    public function test_a_row_shows_the_summary_and_the_student_file_of_a_converted_one(): void
    {
        $fiche = DB::table('tbl_2024_etudiant')->insertGetId(['student_id' => 'UAZ-9', 'student_nom' => 'Rabe', 'student_prenom' => 'Zo', 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2026-2027', 'period' => 'S1', 'remove' => 0]);
        $id = $this->candidate('converti', ['student_id' => 'UAZ-9', 'diplome_vise' => 'master', 'traite_par' => 'Hary Rabe', 'date_traitement' => '2026-10-02 09:00:00']);
        $lost = $this->candidate('converti', ['student_id' => 'FANTOME']);

        $rows = collect($this->list($this->registrar())->json('data.rows'))->keyBy('id');

        $this->assertSame([$id, 'Informatique', 'master', true, 'Converti', 'Hary Rabe', 'UAZ-9', $fiche], [$rows[$id]['id'], $rows[$id]['mention'], $rows[$id]['degree'], $rows[$id]['is_master'], $rows[$id]['status_label'], $rows[$id]['processed_by'], $rows[$id]['student_id'], $rows[$id]['student_file_id']]);
        $this->assertSame(['FANTOME', null], [$rows[$lost]['student_id'], $rows[$lost]['student_file_id']], 'fiche supprimée : la ligne reste, le lien manque');
    }

    public function test_the_mention_falls_back_to_its_code(): void
    {
        $id = $this->candidate('nouveau', ['mention_libelle' => '', 'mention' => 'EDUC']);

        $this->assertSame('EDUC', collect($this->list($this->registrar())->json('data.rows'))->firstWhere('id', $id)['mention']);
    }

    public function test_the_full_file_shows_everything_the_applicant_entered_but_not_their_address_on_the_network(): void
    {
        $id = $this->candidate('nouveau', ['nom' => 'Rabe', 'prenom' => 'Zo', 'genre' => 'F', 'lieu_naissance' => 'Antsirabe', 'nom_pere' => 'Papa', 'nom_mere' => 'Maman', 'tuteur' => 'Oncle',
            'contact_tuteur' => '0321111111', 'message' => 'Bonjour, je souhaite…', 'note_interne' => 'À rappeler', 'ip' => '203.0.113.9', 'annee_bacc' => '2025']);

        $d = $this->api($this->registrar())->getJson("/api/v1/staff/applications/{$id}")->assertOk()->json('data');

        $this->assertSame(['Rabe', 'Zo', 'F', 'Antsirabe', 'Papa', 'Maman', 'Oncle', '0321111111', 'Bonjour, je souhaite…', 'À rappeler', '2025'], [
            $d['last_name'], $d['first_name'], $d['gender'], $d['birth_place'], $d['father'], $d['mother'], $d['guardian'], $d['guardian_contact'], $d['message'], $d['internal_note'], $d['baccalaureate_year'],
        ]);
        $this->assertStringNotContainsString('203.0.113.9', json_encode($d));
        $this->api($this->registrar())->getJson('/api/v1/staff/applications/99999999')->assertNotFound()->assertJsonPath('code', 'applications.not_found');
    }

    // ------------------------------------------------------------------ instruction

    public function test_changing_the_status_records_who_and_when_and_leaves_a_trace(): void
    {
        $id = $this->candidate('nouveau');
        $reg = $this->registrar(['prenom' => 'Hary', 'nom' => 'Rabe']);

        $this->setStatus($reg, $id, 'en_cours')->assertOk()->assertJsonPath('data.changed', true)->assertJsonPath('data.status', 'en_cours');

        $row = DB::table('candidat')->where('id', $id)->first();
        $this->assertSame(['en_cours', 'Hary Rabe', '2026-11-15 10:00:00'], [$row->statut, $row->traite_par, $row->date_traitement]);
        $log = DB::table('t_audit_requete')->where('libelle', 'Candidatures · statut')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, DB::table('t_audit_modification')->where('requete_id', $log->id)->where('table_nom', 'candidat')->count());
    }

    public function test_the_agent_name_falls_back_to_the_pseudo(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar(['prenom' => '', 'nom' => '  ', 'pseudo' => 'registraire.x']);

        $this->setStatus($reg, $id, 'en_cours')->assertOk();

        $this->assertSame('registraire.x', DB::table('candidat')->where('id', $id)->value('traite_par'));
    }

    public function test_only_the_four_statuses_can_be_set_by_hand_and_a_converted_one_never_changes(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar();

        foreach (['converti', 'archive', 'nimporte', ''] as $bad) {
            $this->api($reg)->postJson("/api/v1/staff/applications/{$id}/status", ['status' => $bad])->assertUnprocessable();
        }
        $this->api($reg)->postJson("/api/v1/staff/applications/{$id}/status", [])->assertUnprocessable();
        $this->assertSame('nouveau', DB::table('candidat')->where('id', $id)->value('statut'));

        $done = $this->candidate('converti');
        $this->setStatus($reg, $done, 'refuse')->assertStatus(409)->assertJsonPath('code', 'applications.converted');
        $this->assertSame('converti', DB::table('candidat')->where('id', $done)->value('statut'));
        $this->assertSame([], $this->tasks($done));
        $this->setStatus($reg, 999999, 'accepte')->assertNotFound();
    }

    public function test_accepting_or_refusing_queues_one_email_and_one_sms_and_answers_202(): void
    {
        $a = $this->candidate();
        $b = $this->candidate();
        $reg = $this->registrar();

        $r = $this->setStatus($reg, $a, 'accepte')->assertStatus(202);
        $this->setStatus($reg, $b, 'refuse')->assertStatus(202);

        $this->assertSame([['email', 'accepte', 'en_attente'], ['sms', 'accepte', 'en_attente']], $this->tasks($a));
        $this->assertSame([['email', 'refuse', 'en_attente'], ['sms', 'refuse', 'en_attente']], $this->tasks($b));
        $this->assertSame(['email', 'sms'], array_column($r->json('data.notifications'), 'channel'));
    }

    public function test_other_statuses_notify_nobody_and_answer_200(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar();

        $this->setStatus($reg, $id, 'en_cours')->assertOk();
        $this->setStatus($reg, $id, 'nouveau')->assertOk();

        $this->assertSame([], $this->tasks($id));
    }

    public function test_the_second_click_changes_nothing_and_does_not_notify_again(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar();
        $this->setStatus($reg, $id, 'accepte')->assertStatus(202);
        $this->travelTo('2026-11-15 11:00:00');

        $r = $this->setStatus($reg, $id, 'accepte')->assertOk()->assertJsonPath('data.changed', false);

        $this->assertCount(2, $this->tasks($id));
        $this->assertSame('2026-11-15 10:00:00', DB::table('candidat')->where('id', $id)->value('date_traitement'), 'l’horodatage de la décision ne bouge pas');
        $this->assertCount(2, $r->json('data.notifications'));
    }

    public function test_a_channel_without_a_usable_contact_is_kept_ignored_with_the_reason(): void
    {
        $noMail = $this->candidate('nouveau', ['email' => '']);
        $badMail = $this->candidate('nouveau', ['email' => 'pas-un-mail']);
        $noPhone = $this->candidate('nouveau', ['telephone' => '']);
        $badPhone = $this->candidate('nouveau', ['telephone' => '12345']);
        $reg = $this->registrar();
        foreach ([$noMail, $badMail, $noPhone, $badPhone] as $id) {
            $this->setStatus($reg, $id, 'accepte');
        }
        $why = fn (int $id, string $channel) => DB::table('t_candidat_notification')->where('candidat_id', $id)->where('canal', $channel)->first(['statut', 'raison']);

        $this->assertSame(['ignoree', 'Le candidat n’a pas laissé d’adresse email.'], [$why($noMail, 'email')->statut, $why($noMail, 'email')->raison]);
        $this->assertSame('en_attente', $why($noMail, 'sms')->statut, 'l’autre canal part quand même');
        $this->assertSame(['ignoree', 'Adresse email du candidat invalide.'], [$why($badMail, 'email')->statut, $why($badMail, 'email')->raison]);
        $this->assertSame(['ignoree', 'Le candidat n’a pas laissé de numéro.'], [$why($noPhone, 'sms')->statut, $why($noPhone, 'sms')->raison]);
        $this->assertSame(['ignoree', 'Numéro de téléphone inexploitable.'], [$why($badPhone, 'sms')->statut, $why($badPhone, 'sms')->raison]);
        $this->assertSame('en_attente', $why($badPhone, 'email')->statut);
    }

    public function test_nothing_to_send_means_a_plain_200(): void
    {
        $id = $this->candidate('nouveau', ['email' => '', 'telephone' => '']);

        $this->setStatus($this->registrar(), $id, 'refuse')->assertOk();

        $this->assertSame([['email', 'refuse', 'ignoree'], ['sms', 'refuse', 'ignoree']], $this->tasks($id));
    }

    public function test_a_master_acceptance_waits_for_the_student_creation_for_the_email_but_still_texts(): void
    {
        $master = $this->candidate('nouveau', ['diplome_vise' => 'master']);
        $refused = $this->candidate('nouveau', ['diplome_vise' => 'master']);
        $reg = $this->registrar();

        $this->setStatus($reg, $master, 'accepte')->assertStatus(202);
        $this->setStatus($reg, $refused, 'refuse');

        $mail = DB::table('t_candidat_notification')->where('candidat_id', $master)->where('canal', 'email')->first();
        $this->assertSame('ignoree', $mail->statut);
        $this->assertStringContainsString('Master', $mail->raison);
        $this->assertSame('en_attente', DB::table('t_candidat_notification')->where('candidat_id', $master)->where('canal', 'sms')->value('statut'));
        $this->assertSame('en_attente', DB::table('t_candidat_notification')->where('candidat_id', $refused)->where('canal', 'email')->value('statut'), 'un refus de Master est notifié');
    }

    public function test_changing_the_decision_cancels_what_had_not_left_yet(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar();
        $this->setStatus($reg, $id, 'accepte');

        $this->setStatus($reg, $id, 'refuse')->assertStatus(202);

        $this->assertSame([['email', 'accepte', 'ignoree'], ['sms', 'accepte', 'ignoree'], ['email', 'refuse', 'en_attente'], ['sms', 'refuse', 'en_attente']], $this->tasks($id));
        $this->assertSame('Décision modifiée avant l’envoi.', DB::table('t_candidat_notification')->where('candidat_id', $id)->where('decision', 'accepte')->value('raison'));
    }

    public function test_the_same_decision_taken_again_does_not_notify_twice(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar();
        $this->setStatus($reg, $id, 'accepte');
        DB::table('t_candidat_notification')->where('candidat_id', $id)->update(['statut' => 'envoyee']);   // déjà parti
        $this->setStatus($reg, $id, 'en_cours')->assertOk();

        $this->setStatus($reg, $id, 'accepte')->assertOk();   // rien de nouveau à envoyer : 200

        $new = DB::table('t_candidat_notification')->where('candidat_id', $id)->where('statut', 'ignoree')->pluck('raison')->all();
        $this->assertEqualsCanonicalizing(['Le candidat a déjà reçu ce courriel.', 'Le candidat a déjà reçu ce SMS.'], $new);
        $this->assertSame(0, DB::table('t_candidat_notification')->where('candidat_id', $id)->where('statut', 'en_attente')->count());
    }

    // ------------------------------------------------------------------ archivage

    public function test_archiving_does_not_touch_the_status_and_can_be_undone(): void
    {
        $id = $this->candidate('refuse');
        $reg = $this->registrar(['prenom' => 'Hary', 'nom' => 'Rabe']);
        $archive = fn (bool $on) => $this->api($reg)->postJson("/api/v1/staff/applications/{$id}/archive", ['archive' => $on]);

        $archive(true)->assertOk()->assertJsonPath('data.archived', true);
        $row = DB::table('candidat')->where('id', $id)->first();
        $this->assertSame(['refuse', '2026-11-15 10:00:00', 'Hary Rabe'], [$row->statut, $row->archive_le, $row->archive_par]);
        $this->assertSame(0, DB::table('t_candidat_notification')->count());

        $archive(false)->assertOk();
        $row = DB::table('candidat')->where('id', $id)->first();
        $this->assertSame(['refuse', null, null], [$row->statut, $row->archive_le, $row->archive_par]);
    }

    public function test_archiving_twice_or_unarchiving_a_live_one_is_refused(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar();
        $call = fn (bool $on) => $this->api($reg)->postJson("/api/v1/staff/applications/{$id}/archive", ['archive' => $on]);

        $call(false)->assertStatus(409)->assertJsonPath('code', 'applications.already');
        $call(true)->assertOk();
        $call(true)->assertStatus(409);
        $this->api($reg)->postJson('/api/v1/staff/applications/999999/archive', ['archive' => true])->assertNotFound();
        $this->api($reg)->postJson("/api/v1/staff/applications/{$id}/archive", [])->assertUnprocessable();
    }

    // ------------------------------------------------------------------ relance

    public function test_retry_puts_failed_notifications_of_the_current_decision_back_in_line(): void
    {
        $id = $this->candidate('accepte');
        $row = ['candidat_id' => $id, 'essais' => 3, 'cree_le' => '2026-11-14 10:00:00'];
        DB::table('t_candidat_notification')->insert([
            $row + ['canal' => 'email', 'decision' => 'accepte', 'statut' => 'echec', 'raison' => 'x'],
            $row + ['canal' => 'sms', 'decision' => 'accepte', 'statut' => 'envoyee', 'raison' => null],
            $row + ['canal' => 'sms', 'decision' => 'refuse', 'statut' => 'echec', 'raison' => 'ancienne décision'],
        ]);

        $this->api($this->registrar())->postJson("/api/v1/staff/applications/{$id}/notifications/retry")->assertOk()->assertJsonPath('data.requeued', 1);

        $this->assertSame([['email', 'accepte', 'en_attente', 0], ['sms', 'accepte', 'envoyee', 3], ['sms', 'refuse', 'echec', 3]],
            DB::table('t_candidat_notification')->where('candidat_id', $id)->orderBy('id')->get()->map(fn ($t) => [$t->canal, $t->decision, $t->statut, $t->essais])->all());
        $this->api($this->registrar())->postJson('/api/v1/staff/applications/999999/notifications/retry')->assertNotFound();
    }

    public function test_responses_are_never_cached(): void
    {
        $id = $this->candidate();
        $reg = $this->registrar();

        $this->assertStringContainsString('no-store', (string) $this->list($reg)->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->setStatus($reg, $id, 'refuse')->headers->get('Cache-Control'));
    }
}
