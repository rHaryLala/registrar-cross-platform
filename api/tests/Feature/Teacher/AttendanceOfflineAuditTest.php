<?php

namespace Tests\Feature\Teacher;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/**
 * Écritures faites hors ligne (le serveur fait foi : conflit 409, « appliquer quand même ») et journal d'activité partagé
 * avec l'ancienne application (mêmes tables, même format).
 */
class AttendanceOfflineAuditTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;
    use MakesTeacherData;

    private function api(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp();
    }

    private function ms(string $when): int
    {
        return (int) (strtotime($when) * 1000);
    }

    /** Une séance ouverte, avec le pointage de E001 fait « il y a $minutes minutes » sur le serveur. @return array{0: User, 1: int, 2: int} */
    private function sessionMarked(int $minutes = 60): array
    {
        [$user, $course] = $this->classroom();
        $sid = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d'), 'planned_time' => '08:00'])->json('data.session_id');
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'present'])->assertOk();
        DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->update(['modifie_le' => now()->subMinutes($minutes)->format('Y-m-d H:i:s')]);

        return [$user, $sid, $course];
    }

    // ------------------------------------------------------------- hors ligne

    public function test_an_offline_mark_is_dated_when_the_teacher_made_it(): void
    {
        [$user, $sid] = $this->sessionMarked(60);
        $when = now()->subMinutes(10);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'absent', 'client_at' => $when->getTimestamp() * 1000])->assertOk();

        $this->assertSame($when->format('Y-m-d H:i:s'), DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E002')->value('modifie_le'));
    }

    public function test_an_offline_mark_older_than_the_server_data_is_a_conflict_and_changes_nothing(): void
    {
        [$user, $sid] = $this->sessionMarked(5);   // le serveur a été modifié il y a 5 minutes

        $res = $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent', 'client_at' => now()->subMinutes(30)->getTimestamp() * 1000])
            ->assertStatus(409)->assertJsonPath('code', 'presence.conflict')->assertJsonPath('target', 'mark');

        $this->assertNotNull($res->json('modified_at'));
        $this->assertSame('present', DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('statut'));
        $this->assertSame('application/problem+json', $res->headers->get('Content-Type'));
    }

    public function test_an_offline_mark_newer_than_the_server_data_is_applied(): void
    {
        [$user, $sid] = $this->sessionMarked(60);   // modifié il y a 1 h

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent', 'client_at' => now()->subMinutes(20)->getTimestamp() * 1000])->assertOk();
        $this->assertSame('absent', DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('statut'));
    }

    public function test_force_applies_a_conflicting_change_at_the_current_time(): void
    {
        [$user, $sid] = $this->sessionMarked(5);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent', 'client_at' => now()->subMinutes(30)->getTimestamp() * 1000, 'force' => true])->assertOk();

        $l = DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->first();
        $this->assertSame('absent', $l->statut);
        $this->assertGreaterThan(now()->subMinute()->format('Y-m-d H:i:s'), $l->modifie_le, 'daté de maintenant, pas de l’instant hors ligne');
    }

    public function test_a_replayed_offline_change_is_harmless(): void
    {
        [$user, $sid] = $this->sessionMarked(60);
        $body = ['status' => 'retard', 'minutes_late' => 7, 'client_at' => now()->subMinutes(20)->getTimestamp() * 1000];

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", $body)->assertOk();
        // Le réseau a coupé avant la réponse : la file hors ligne renvoie la même écriture. Même instant = même état, pas de conflit.
        DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->update(['modifie_le' => date('Y-m-d H:i:s', intdiv($body['client_at'], 1000))]);
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", $body)->assertOk();

        $this->assertSame(['retard', 7], [DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('statut'), (int) DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('minutes_retard')]);
        $this->assertSame(1, DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->count());
    }

    public function test_a_change_older_than_fourteen_days_is_refused_as_stale(): void
    {
        [$user, $sid] = $this->sessionMarked(60);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'absent', 'client_at' => now()->subDays(15)->getTimestamp() * 1000])
            ->assertStatus(409)->assertJsonPath('code', 'presence.stale');
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'absent', 'client_at' => now()->subDays(13)->getTimestamp() * 1000])->assertOk();
    }

    public function test_a_clock_ahead_of_the_server_is_clamped_to_now_never_in_the_future(): void
    {
        [$user, $sid] = $this->sessionMarked(60);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'absent', 'client_at' => now()->addHours(3)->getTimestamp() * 1000])->assertOk();

        $this->assertLessThanOrEqual(now()->format('Y-m-d H:i:s'), DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E002')->value('modifie_le'));
    }

    public function test_an_offline_change_to_a_session_conflicts_when_the_session_moved_since(): void
    {
        [$user, $sid] = $this->sessionMarked(60);
        DB::table('t_presence_seance')->where('id', $sid)->update(['modifie_le' => now()->subMinutes(2)->format('Y-m-d H:i:s')]);
        $body = ['planned_time' => '09:00', 'teacher_arrival' => null, 'warned' => false];

        $this->api($user)->patchJson("/api/v1/teacher/sessions/{$sid}", $body + ['client_at' => now()->subMinutes(30)->getTimestamp() * 1000])->assertStatus(409)->assertJsonPath('target', 'session');
        $this->api($user)->deleteJson("/api/v1/teacher/sessions/{$sid}", ['client_at' => now()->subMinutes(30)->getTimestamp() * 1000])->assertStatus(409);
        $this->assertSame('08:00:00', DB::table('t_presence_seance')->where('id', $sid)->value('heure_prevue'));
        $this->api($user)->patchJson("/api/v1/teacher/sessions/{$sid}", $body + ['client_at' => now()->subMinutes(30)->getTimestamp() * 1000, 'force' => true])->assertOk();
        $this->assertSame('09:00:00', DB::table('t_presence_seance')->where('id', $sid)->value('heure_prevue'));
    }

    public function test_an_offline_planned_sessions_change_conflicts_when_the_register_moved_since(): void
    {
        [$user, , $course] = $this->sessionMarked(60);
        DB::table('t_presence_feuille')->where('cours_id', $course)->update(['modifie_le' => now()->subMinutes(2)->format('Y-m-d H:i:s')]);

        $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base() + ['planned' => 25, 'client_at' => now()->subMinutes(30)->getTimestamp() * 1000])->assertStatus(409)->assertJsonPath('target', 'planned');
        $this->assertNull(DB::table('t_presence_feuille')->where('cours_id', $course)->value('seances_prevues'));
    }

    public function test_opening_a_session_offline_creates_the_register_dated_at_the_offline_time(): void
    {
        [$user, $course] = $this->classroom();
        $when = now()->subHours(5);

        $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d'), 'planned_time' => '08:00', 'client_at' => $when->getTimestamp() * 1000])->assertCreated();

        $this->assertSame($when->format('Y-m-d H:i:s'), DB::table('t_presence_feuille')->where('cours_id', $course)->value('cree_le'));
        $this->assertSame($when->format('Y-m-d H:i:s'), DB::table('t_presence_seance')->value('cree_le'));
    }

    public function test_client_at_must_be_an_integer(): void
    {
        [$user, $sid] = $this->sessionMarked(60);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'absent', 'client_at' => 'hier'])->assertStatus(422);
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'absent', 'client_at' => -5])->assertStatus(422);
    }

    // ---------------------------------------------------------------- journal

    public function test_a_write_leaves_one_entry_in_the_shared_activity_log(): void
    {
        [$user, $course] = $this->classroom();

        $this->api($user)->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36'])
            ->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d'), 'planned_time' => '08:00'])->assertCreated();

        $req = DB::table('t_audit_requete')->first();
        $this->assertSame(1, DB::table('t_audit_requete')->count());
        $this->assertSame(['modification', 'Présence · ouvrir une séance', $user->id, $user->pseudo, 7, 'POST', "/api/v1/teacher/courses/{$course}/sessions", 201, 'Android · Chrome'],
            [$req->type_action, $req->libelle, (int) $req->user_id, $req->user_pseudo, (int) $req->user_niveau, $req->methode, $req->chemin, (int) $req->statut_http, $req->appareil]);
        $this->assertSame(',t_presence_feuille,t_presence_seance,t_presence_ligne,', $req->tables_touchees);
        $this->assertSame(3, (int) $req->nb_modifications);
        $this->assertSame(['t_presence_feuille', 't_presence_seance', 't_presence_ligne'], DB::table('t_audit_modification')->where('requete_id', $req->id)->orderBy('ordre')->pluck('table_nom')->all());
    }

    public function test_an_update_is_logged_field_by_field_with_before_and_after(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d'), 'planned_time' => '08:00'])->json('data.session_id');
        DB::table('t_audit_requete')->delete();

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'retard', 'minutes_late' => 8])->assertOk();

        $m = DB::table('t_audit_modification')->first();
        $this->assertSame(['UPDATE', 't_presence_ligne', 1], [$m->operation, $m->table_nom, (int) $m->lignes]);
        $c = json_decode($m->changements, true);
        $this->assertFalse($c['tronque']);
        $this->assertSame(['statut' => null, 'minutes_retard' => null], array_intersect_key($c['lignes'][0]['avant'], ['statut' => 1, 'minutes_retard' => 1]));
        $this->assertSame(['statut' => 'retard', 'minutes_retard' => 8], array_intersect_key($c['lignes'][0]['apres'], ['statut' => 1, 'minutes_retard' => 1]));
        $this->assertArrayNotHasKey('seance_id', $c['lignes'][0]['apres'], 'seules les colonnes qui ont changé sont journalisées');
    }

    public function test_a_mark_that_changes_nothing_leaves_no_log_line(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d')])->json('data.session_id');
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertOk();
        // modifie_le change à chaque écriture ; on fige l'instant pour que « rien n'a changé » soit vrai champ par champ.
        $this->travelTo(now());
        DB::table('t_audit_requete')->delete();
        $line = DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->first();
        DB::table('t_presence_ligne')->where('id', $line->id)->update(['modifie_le' => now()->format('Y-m-d H:i:s'), 'modifie_par' => $user->id]);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertOk();

        $this->assertSame(0, DB::table('t_audit_requete')->count());
    }

    public function test_reads_and_refused_writes_leave_no_trace(): void
    {
        [$user, $course] = $this->classroom();
        [$intruder] = $this->makeTeacher('Naina', 'Rabe');

        $this->api($user)->getJson("/api/v1/teacher/courses/{$course}/register?year=".rawurlencode($this->year).'&semester=1')->assertOk();
        $this->api($intruder)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d')])->assertNotFound();
        $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->addDays(2)->format('Y-m-d')])->assertStatus(422);

        $this->assertSame(0, DB::table('t_audit_requete')->count());
        $this->assertSame(0, DB::table('t_audit_modification')->count());
    }

    public function test_a_flag_is_logged_with_the_mark_that_caused_it(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $this->openSheet($course, 10, 1, null, $uid);   // 1 permise
        $sessions = [];
        foreach ([1, 2] as $i) {
            $sessions[] = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->subDays($i)->format('Y-m-d')])->json('data.session_id');
        }
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sessions[0]}/marks/E001", ['status' => 'absent'])->assertOk();
        DB::table('t_audit_requete')->delete();

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sessions[1]}/marks/E001", ['status' => 'absent'])->assertOk()->assertJsonPath('data.newly_flagged', true);

        $this->assertSame(',t_presence_ligne,t_presence_signalement,', DB::table('t_audit_requete')->value('tables_touchees'));
        $this->assertSame(['t_presence_ligne', 't_presence_signalement'], DB::table('t_audit_modification')->orderBy('ordre')->pluck('table_nom')->all());
    }

    public function test_deleting_a_session_names_the_marks_that_disappeared(): void
    {
        [$user, $sid] = $this->sessionMarked(60);
        DB::table('t_audit_requete')->delete();

        $this->api($user)->deleteJson("/api/v1/teacher/sessions/{$sid}")->assertOk();

        $mods = DB::table('t_audit_modification')->orderBy('ordre')->get();
        $this->assertSame(['t_presence_ligne', 't_presence_seance'], $mods->pluck('table_nom')->all());
        $this->assertSame([3, 'DELETE'], [(int) $mods[0]->lignes, $mods[0]->operation]);
        $this->assertSame(['present', 'E001'], [json_decode($mods[0]->changements, true)['lignes'][0]['avant']['statut'], json_decode($mods[0]->changements, true)['lignes'][0]['avant']['student_id']]);
    }

    public function test_secrets_are_masked_in_the_logged_request_data(): void
    {
        [$user, $course] = $this->classroom();

        $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d'), 'password' => 'secret-123', 'token' => 'abc'])->assertCreated();

        $data = json_decode((string) DB::table('t_audit_requete')->value('donnees'), true)['json'];
        $this->assertSame('••••', $data['password']);
        $this->assertSame('••••', $data['token']);
        $this->assertSame($this->year, $data['year']);
    }

    public function test_an_offline_justification_conflicts_when_the_mark_moved_since(): void
    {
        [$user, $sid] = $this->sessionMarked(5);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001/justification", ['justification' => 'medical', 'client_at' => now()->subMinutes(30)->getTimestamp() * 1000])
            ->assertStatus(409)->assertJsonPath('code', 'presence.conflict');
        $this->assertNull(DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('justificatif'));
    }

    public function test_the_domain_itself_refuses_a_future_session(): void
    {
        [$user, $course] = $this->classroom();

        try {
            app(\App\Domain\Teacher\TeacherAttendance::class)->createSession($user, $course, $this->year, 1, now()->addDay()->format('Y-m-d'), null, null, false, null, false);
            $this->fail('une séance à venir ne doit jamais être créée');
        } catch (\App\Exceptions\ApiProblem $e) {
            $this->assertSame('presence.future_session', $e->problemCode);
        }
        $this->assertSame(0, DB::table('t_presence_seance')->count());
    }

    public function test_the_log_tells_an_empty_value_from_a_missing_one(): void
    {
        $request = \Illuminate\Http\Request::create('/api/v1/teacher/x', 'PATCH');
        $trail = new \App\Support\AuditTrail;

        $trail->update('t_presence_seance', 'id', ['id' => 1, 'commentaire' => null], ['id' => 1, 'commentaire' => '']);
        $trail->commit($request, null, 'Présence · test');

        $c = json_decode((string) DB::table('t_audit_modification')->value('changements'), true);
        $this->assertSame([['commentaire' => null], ['commentaire' => '']], [$c['lignes'][0]['avant'], $c['lignes'][0]['apres']]);
    }

    public function test_a_failing_log_never_breaks_the_action(): void
    {
        // Pas de DDL dans les tests (il validerait la transaction) : on fait échouer le journal de l'intérieur.
        $request = new class extends \Illuminate\Http\Request
        {
            public function getRequestUri(): string
            {
                throw new \RuntimeException('journal en panne');
            }
        };
        $trail = new \App\Support\AuditTrail;
        $trail->insert('t_presence_seance', 'id', ['id' => 1]);
        \Illuminate\Support\Facades\Exceptions::fake();

        $trail->commit($request, null, 'Présence · test');   // ne lève rien

        $this->assertSame(0, DB::table('t_audit_requete')->count());
        $this->assertTrue($trail->isEmpty(), 'la liste en attente est vidée même après un échec');
        \Illuminate\Support\Facades\Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'journal en panne');
    }
}
