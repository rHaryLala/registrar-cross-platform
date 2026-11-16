<?php

namespace Tests\Feature\Grading;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/** Remise de notes : saisies faites hors ligne (le serveur fait foi) et journal d'activité commun. */
class GradingOfflineAuditTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;
    use MakesTeacherData;

    private function api(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp();
    }

    private function cell(User $u, int $course, string $student, string $field, mixed $value, array $extra = [])
    {
        return $this->api($u)->putJson("/api/v1/teacher/grading/courses/{$course}/cells/{$student}", $this->base() + ['field' => $field, 'value' => $value] + $extra);
    }

    private function ago(int $minutes): int
    {
        return now()->subMinutes($minutes)->getTimestamp() * 1000;
    }

    /** Une feuille où E001 a un quiz de 10, saisi « il y a $minutes minutes » côté serveur. @return array{0: User, 1: int} */
    private function withQuiz(int $minutes): array
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->update(['modifie_le' => now()->subMinutes($minutes)->format('Y-m-d H:i:s')]);
        DB::table('t_remise_notes_valeur')->update(['modifie_le' => now()->subMinutes($minutes)->format('Y-m-d H:i:s')]);

        return [$user, $course];
    }

    // ------------------------------------------------------------- hors ligne

    public function test_an_offline_entry_is_dated_when_the_teacher_made_it(): void
    {
        [$user, $course] = $this->withQuiz(60);
        $when = now()->subMinutes(10);

        $this->cell($user, $course, 'E002', 'c1', 8, ['client_at' => $when->getTimestamp() * 1000])->assertOk();

        $this->assertSame($when->format('Y-m-d H:i:s'), DB::table('t_remise_notes_ligne')->where('student_id', 'E002')->value('modifie_le'));
        $this->assertSame($when->format('Y-m-d H:i:s'), DB::table('t_remise_notes_journal')->where('student_id', 'E002')->value('date_action'));
    }

    public function test_an_offline_entry_older_than_the_server_cell_is_a_conflict_and_changes_nothing(): void
    {
        [$user, $course] = $this->withQuiz(5);

        $res = $this->cell($user, $course, 'E001', 'c1', 3, ['client_at' => $this->ago(30)])->assertStatus(409)->assertJsonPath('code', 'grading.conflict')->assertJsonPath('target', 'cell');

        $this->assertNotNull($res->json('modified_at'));
        $this->assertSame('10.00', DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('quiz'));
    }

    public function test_an_offline_entry_newer_than_the_server_cell_is_applied(): void
    {
        [$user, $course] = $this->withQuiz(60);

        $this->cell($user, $course, 'E001', 'c1', 3, ['client_at' => $this->ago(20)])->assertOk();
        $this->assertSame('3.00', DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('quiz'));
    }

    public function test_an_offline_entry_that_wants_the_value_already_there_is_not_a_conflict(): void
    {
        [$user, $course] = $this->withQuiz(5);

        $this->cell($user, $course, 'E001', 'c1', '10,0', ['client_at' => $this->ago(30)])->assertOk();
    }

    public function test_force_applies_a_conflicting_entry_at_the_current_time(): void
    {
        [$user, $course] = $this->withQuiz(5);

        $this->cell($user, $course, 'E001', 'c1', 3, ['client_at' => $this->ago(30), 'force' => true])->assertOk();

        $l = DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->first();
        $this->assertSame('3.00', $l->quiz);
        $this->assertGreaterThan(now()->subMinute()->format('Y-m-d H:i:s'), $l->modifie_le);
    }

    public function test_an_offline_entry_is_not_in_conflict_with_the_creation_of_its_own_line(): void
    {
        [$user, $course] = $this->classroom();

        // Première écriture de la feuille, faite hors ligne il y a 10 minutes : la feuille et ses lignes naissent maintenant.
        $this->cell($user, $course, 'E001', 'c1', 10, ['client_at' => $this->ago(10)])->assertOk();
        $this->cell($user, $course, 'E002', 'c1', 11, ['client_at' => $this->ago(9)])->assertOk();

        $this->assertSame(['10.00', '11.00'], DB::table('t_remise_notes_ligne')->whereIn('student_id', ['E001', 'E002'])->orderBy('student_id')->pluck('quiz')->all());
    }

    public function test_a_replayed_entry_is_harmless(): void
    {
        [$user, $course] = $this->classroom();
        $body = ['client_at' => $this->ago(20)];

        $this->cell($user, $course, 'E001', 'c1', 12, $body)->assertOk();
        $this->cell($user, $course, 'E001', 'c1', 12, $body)->assertOk();   // le réseau a coupé avant la réponse : on renvoie

        $this->assertSame(1, DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->count());
        $this->assertSame(1, DB::table('t_remise_notes_journal')->where('action', 'saisie')->count());
    }

    public function test_an_entry_older_than_fourteen_days_is_stale(): void
    {
        [$user, $course] = $this->classroom();

        $this->cell($user, $course, 'E001', 'c1', 5, ['client_at' => now()->subDays(15)->getTimestamp() * 1000])->assertStatus(409)->assertJsonPath('code', 'grading.stale');
        $this->cell($user, $course, 'E001', 'c1', 5, ['client_at' => now()->subDays(13)->getTimestamp() * 1000])->assertOk();
    }

    public function test_an_offline_column_change_conflicts_when_the_structure_moved_since(): void
    {
        [$user, $course] = $this->classroom();
        $url = "/api/v1/teacher/grading/courses/{$course}/components";
        $five = fn (int $quiz, int $final) => [['slot' => 1, 'label' => 'Quiz', 'weight' => $quiz], ['slot' => 2, 'label' => 'Recherche', 'weight' => 20], ['slot' => 3, 'label' => 'Autres', 'weight' => 10], ['slot' => 4, 'label' => 'Examen mi-sem.', 'weight' => 25], ['slot' => 5, 'label' => 'Examen final', 'weight' => $final]];
        $this->api($user)->putJson($url, $this->base() + ['components' => $five(15, 30)])->assertOk();   // un autre appareil, il y a un instant

        $this->api($user)->putJson($url, $this->base() + ['components' => $five(5, 40), 'client_at' => $this->ago(30)])->assertStatus(409)->assertJsonPath('target', 'weights');
        $this->assertSame(15.0, (float) DB::table('t_remise_notes_composante')->where('slot', 1)->value('poids'));

        $this->api($user)->putJson($url, $this->base() + ['components' => $five(5, 40), 'client_at' => $this->ago(30), 'force' => true])->assertOk();
        $this->assertSame(5.0, (float) DB::table('t_remise_notes_composante')->where('slot', 1)->value('poids'));
    }

    public function test_a_sheet_frozen_meanwhile_refuses_an_offline_entry_even_when_forced(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        DB::table('t_remise_notes')->update(['statut' => 'soumise']);

        $this->cell($user, $course, 'E001', 'c1', 1, ['client_at' => $this->ago(5)])->assertStatus(409)->assertJsonPath('code', 'grading.frozen');
        $this->cell($user, $course, 'E001', 'c1', 1, ['force' => true])->assertStatus(409)->assertJsonPath('code', 'grading.frozen');
    }

    // ---------------------------------------------------------------- journal

    public function test_a_write_leaves_an_entry_in_the_shared_activity_log(): void
    {
        [$user, $course] = $this->classroom();

        $this->cell($user, $course, 'E001', 'c1', 14)->assertOk();

        $req = DB::table('t_audit_requete')->first();
        $this->assertSame(1, DB::table('t_audit_requete')->count());
        $this->assertSame(['modification', 'Remise de notes · saisie', $user->id, 'PUT', "/api/v1/teacher/grading/courses/{$course}/cells/E001"], [$req->type_action, $req->libelle, (int) $req->user_id, $req->methode, $req->chemin]);
        foreach (['t_remise_notes', 't_remise_notes_ligne', 't_remise_notes_composante', 't_remise_notes_valeur', 't_remise_notes_journal'] as $table) {
            $this->assertStringContainsString(",{$table},", $req->tables_touchees);
        }
    }

    public function test_a_cell_update_is_logged_with_before_and_after(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 14)->assertOk();
        DB::table('t_audit_requete')->delete();

        $this->cell($user, $course, 'E001', 'c1', 9)->assertOk();

        $m = DB::table('t_audit_modification')->where('table_nom', 't_remise_notes_valeur')->first();
        $c = json_decode($m->changements, true);
        $this->assertSame(['UPDATE', '14.00', 9], [$m->operation, $c['lignes'][0]['avant']['valeur'], $c['lignes'][0]['apres']['valeur']]);
    }

    public function test_submitting_is_logged(): void
    {
        [$user, $course] = $this->classroom();
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();

        $req = DB::table('t_audit_requete')->orderByDesc('id')->first();

        $this->assertSame('Remise de notes · transmission', $req->libelle);
        $m = DB::table('t_audit_modification')->where('requete_id', $req->id)->where('table_nom', 't_remise_notes')->where('operation', 'UPDATE')->first();
        $this->assertSame('soumise', json_decode($m->changements, true)['lignes'][0]['apres']['statut']);
    }

    public function test_reads_and_refused_writes_leave_no_trace(): void
    {
        [$user, $course] = $this->classroom();
        [$intruder] = $this->makeTeacher('Naina', 'Rabe');

        $this->api($user)->getJson("/api/v1/teacher/grading/courses/{$course}/sheet?year=".rawurlencode($this->year).'&semester=1')->assertOk();
        $this->cell($intruder, $course, 'E001', 'c1', 5)->assertNotFound();
        $this->cell($user, $course, 'E001', 'c1', 99)->assertStatus(422);
        $this->cell($user, $course, 'E001', 'bidon', 5)->assertStatus(422);
        $this->cell($user, $course, 'E001', 'quiz', 5)->assertStatus(422);

        $this->assertSame([0, 0, 0], [DB::table('t_audit_requete')->count(), DB::table('t_audit_modification')->count(), DB::table('t_remise_notes')->count()]);
    }

    public function test_a_frozen_write_leaves_no_trace_either(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        DB::table('t_remise_notes')->update(['statut' => 'validee']);
        $logs = DB::table('t_audit_requete')->count();

        $this->cell($user, $course, 'E001', 'c1', 1)->assertStatus(409);

        $this->assertSame($logs, DB::table('t_audit_requete')->count());
        $this->assertSame('10.00', DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('quiz'));
    }
}
