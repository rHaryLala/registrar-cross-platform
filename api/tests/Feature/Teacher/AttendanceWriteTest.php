<?php

namespace Tests\Feature\Teacher;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/** Registres de présence : les écritures de l'enseignant (séances, pointages, justificatifs, base des 15 %, signalement §476). */
class AttendanceWriteTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;
    use MakesTeacherData;

    private function api(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp();
    }

    private function today(int $minusDays = 0): string
    {
        return now()->subDays($minusDays)->format('Y-m-d');
    }

    /** Ouvre une séance par l'API, renvoie son id. */
    private function openSession(User $user, int $course, ?string $date = null, ?string $time = '08:00'): int
    {
        return $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => $date ?? $this->today(), 'planned_time' => $time])->assertSuccessful()->json('data.session_id');
    }

    // ------------------------------------------------------------- les séances

    public function test_opening_a_session_creates_the_register_and_one_line_per_enrolled_student(): void
    {
        [$user, $course, $uid] = $this->classroom();

        $res = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => $this->today(), 'planned_time' => '8:30', 'teacher_arrival' => '08:35'])
            ->assertCreated()->assertJsonPath('data.existed', false);

        $sid = $res->json('data.session_id');
        $sheet = DB::table('t_presence_feuille')->where('cours_id', $course)->first();
        $this->assertSame([$this->year, 1, $uid, $user->id], [$sheet->annee_scolaire, (int) $sheet->semestre, (int) $sheet->teacher_uid, (int) $sheet->cree_par]);
        $this->assertNull($sheet->seances_prevues);
        $row = DB::table('t_presence_seance')->where('id', $sid)->first();
        $this->assertSame(['08:30:00', '08:35:00', 0, $user->id], [$row->heure_prevue, $row->arrivee_enseignant, (int) $row->message_retard, (int) $row->cree_par]);
        $this->assertSame(['E001', 'E002', 'E003'], DB::table('t_presence_ligne')->where('seance_id', $sid)->orderBy('student_id')->pluck('student_id')->all());
        $this->assertSame(3, DB::table('t_presence_ligne')->where('seance_id', $sid)->whereNull('statut')->count());
    }

    public function test_opening_the_same_session_twice_returns_the_existing_one(): void
    {
        [$user, $course] = $this->classroom();
        $first = $this->openSession($user, $course);

        $again = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => $this->today(), 'planned_time' => '08:00'])->assertCreated();

        $this->assertSame([$first, true], [$again->json('data.session_id'), $again->json('data.existed')]);
        $this->assertSame(1, DB::table('t_presence_seance')->count());
        $this->assertSame(1, DB::table('t_presence_feuille')->where('cours_id', $course)->count());
    }

    public function test_the_same_day_at_another_time_is_another_session_and_no_time_matches_no_time(): void
    {
        [$user, $course] = $this->classroom();
        $a = $this->openSession($user, $course, null, '08:00');
        $b = $this->openSession($user, $course, null, '14:00');
        $c = $this->openSession($user, $course, null, null);
        $d = $this->openSession($user, $course, null, null);

        $this->assertCount(3, array_unique([$a, $b, $c]));
        $this->assertSame($c, $d, 'sans heure, la même date reste la même séance');
    }

    public function test_a_future_session_is_refused(): void
    {
        [$user, $course] = $this->classroom();

        $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->addDay()->format('Y-m-d')])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, DB::table('t_presence_seance')->count());
    }

    public function test_session_input_is_validated(): void
    {
        [$user, $course] = $this->classroom();
        $url = "/api/v1/teacher/courses/{$course}/sessions";

        foreach ([
            ['date' => '2026-02-30'], ['date' => 'hier'], ['date' => $this->today(), 'planned_time' => '25:00'], ['date' => $this->today(), 'planned_time' => '8h30'],
            ['date' => $this->today(), 'teacher_arrival' => '12:61'], ['date' => $this->today(), 'semester' => 7],
        ] as $bad) {
            $this->api($user)->postJson($url, array_merge($this->base(), $bad))->assertStatus(422);
        }
        $this->api($user)->postJson($url, ['date' => $this->today()])->assertStatus(422);   // l'année est obligatoire
        $this->assertSame(0, DB::table('t_presence_seance')->count());
    }

    public function test_a_teacher_cannot_open_a_session_in_a_course_that_is_not_theirs(): void
    {
        [$user] = $this->makeTeacher();
        [, $course] = $this->classroom();

        $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => $this->today()])->assertNotFound();
        $this->assertSame(0, DB::table('t_presence_feuille')->count());
        $this->assertSame(0, DB::table('t_audit_requete')->count());
    }

    public function test_a_late_enrolled_student_gets_a_line_when_the_teacher_marks_them(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $this->enrol($course, ['E004' => ['Zafy', 'Mia']]);   // inscrit après l'ouverture de la séance

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E004", ['status' => 'present'])->assertOk();

        $this->assertSame('present', DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E004')->value('statut'));
    }

    public function test_updating_a_session_changes_times_message_and_comment(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);

        $res = $this->api($user)->patchJson("/api/v1/teacher/sessions/{$sid}", ['planned_time' => '08:00', 'teacher_arrival' => '08:20', 'warned' => false, 'comment' => '  Pluie  '])->assertOk();

        $this->assertSame([20, true, 'Pluie'], [$res->json('data.session.teacher_late_minutes'), $res->json('data.session.no_penalty'), $res->json('data.session.comment')]);

        $this->api($user)->patchJson("/api/v1/teacher/sessions/{$sid}", ['planned_time' => '08:00', 'teacher_arrival' => '08:20', 'warned' => true, 'comment' => null])->assertOk()->assertJsonPath('data.session.no_penalty', false);
        $this->assertNull(DB::table('t_presence_seance')->where('id', $sid)->value('commentaire'));
    }

    public function test_deleting_a_session_removes_its_marks_but_not_the_register(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertOk();

        $this->api($user)->deleteJson("/api/v1/teacher/sessions/{$sid}")->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertSame([0, 0, 1], [DB::table('t_presence_seance')->count(), DB::table('t_presence_ligne')->count(), DB::table('t_presence_feuille')->count()]);
    }

    // ---------------------------------------------------------------- pointage

    public function test_marking_present_late_and_absent(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $mark = fn (string $student, array $body) => $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/{$student}", $body);

        $mark('E001', ['status' => 'present'])->assertOk()->assertJsonPath('data.label', 'Présent')->assertJsonPath('data.category', 'present');
        $mark('E002', ['status' => 'retard', 'minutes_late' => 7])->assertOk()->assertJsonPath('data.label', 'Retard 7 min')->assertJsonPath('data.category', 'retard');
        $mark('E003', ['status' => 'absent'])->assertOk()->assertJsonPath('data.category', 'absence');

        $this->assertSame(['present', null], $this->line($sid, 'E001'));
        $this->assertSame(['retard', 7], $this->line($sid, 'E002'));
        $this->assertSame(['absent', null], $this->line($sid, 'E003'));
        $this->assertSame($user->id, (int) DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E003')->value('modifie_par'));
    }

    /** @return array{0: ?string, 1: ?int} */
    private function line(int $session, string $student): array
    {
        $l = DB::table('t_presence_ligne')->where('seance_id', $session)->where('student_id', $student)->first();

        return [$l->statut, $l->minutes_retard === null ? null : (int) $l->minutes_retard];
    }

    public function test_a_late_without_minutes_counts_for_five(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'retard'])->assertOk()->assertJsonPath('data.minutes_late', 5)->assertJsonPath('data.label', 'Retard 5 min');
        $this->assertSame(['retard', 5], $this->line($sid, 'E001'));
    }

    public function test_the_duration_decides_not_the_button(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'retard', 'minutes_late' => 12])->assertOk()->assertJsonPath('data.category', 'absence')->assertJsonPath('data.label', 'Retard 12 min (compte absence)');
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'retard', 'minutes_late' => 3])->assertOk()->assertJsonPath('data.category', 'present');
    }

    public function test_minutes_are_dropped_unless_it_is_a_late_and_bounded_otherwise(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $url = "/api/v1/teacher/sessions/{$sid}/marks/E001";

        $this->api($user)->putJson($url, ['status' => 'absent', 'minutes_late' => 9])->assertOk();
        $this->assertSame(['absent', null], $this->line($sid, 'E001'));
        foreach ([0, 241, -3] as $bad) {
            $this->api($user)->putJson($url, ['status' => 'retard', 'minutes_late' => $bad])->assertStatus(422);
        }
        $this->api($user)->putJson($url, ['status' => 'retard', 'minutes_late' => 240])->assertOk();
        $this->api($user)->putJson($url, ['status' => 'en retard'])->assertStatus(422);
        $this->api($user)->putJson($url, [])->assertStatus(422);   // « présent » ne se déduit jamais d'un corps vide
    }

    public function test_marking_null_clears_the_mark_and_marking_again_is_idempotent(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $url = "/api/v1/teacher/sessions/{$sid}/marks/E001";

        $this->api($user)->putJson($url, ['status' => 'absent'])->assertOk();
        $this->api($user)->putJson($url, ['status' => 'absent'])->assertOk();
        $this->assertSame(1, DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->count());

        $this->api($user)->putJson($url, ['status' => null])->assertOk()->assertJsonPath('data.label', 'Non pointé');
        $this->assertSame([null, null], $this->line($sid, 'E001'));
    }

    public function test_an_unknown_student_cannot_be_marked(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E999", ['status' => 'present'])->assertNotFound()->assertJsonPath('code', 'presence.student_not_listed');
        $this->assertSame(0, DB::table('t_presence_ligne')->where('student_id', 'E999')->count());
    }

    public function test_a_student_of_another_course_cannot_be_marked(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $other = $this->makeCourse($uid, 'AUT 101');
        $this->enrol($other, ['Z777' => ['Ailleurs', 'Lui']]);
        $sid = $this->openSession($user, $course);

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/Z777", ['status' => 'absent'])->assertNotFound();
    }

    public function test_another_teacher_cannot_mark_in_my_session(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        [$intruder] = $this->makeTeacher('Naina', 'Rabe');

        $this->api($intruder)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertNotFound();
        $this->api($intruder)->patchJson("/api/v1/teacher/sessions/{$sid}", ['planned_time' => null, 'teacher_arrival' => null, 'warned' => true])->assertNotFound();
        $this->api($intruder)->deleteJson("/api/v1/teacher/sessions/{$sid}")->assertNotFound();
        $this->api($intruder)->postJson("/api/v1/teacher/sessions/{$sid}/mark-all-present")->assertNotFound();
        $this->api($intruder)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001/justification", ['justification' => 'medical'])->assertNotFound();
        $this->assertSame([null, null], $this->line($sid, 'E001'));
        $this->assertSame(1, DB::table('t_presence_seance')->count());
    }

    public function test_a_student_account_cannot_write(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $student = User::factory()->create(['student_id' => 'E001']);

        $this->api($student)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'present'])->assertForbidden();
        $this->assertSame([null, null], $this->line($sid, 'E001'));
    }

    public function test_registrariat_staff_can_correct_any_register(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $staff = User::factory()->staff(3, 'registrar')->create();

        $this->api($staff)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertOk();
        $this->assertSame($staff->id, (int) DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('modifie_par'));
    }

    public function test_the_justification_is_stored_and_cleared(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertOk();
        $url = "/api/v1/teacher/sessions/{$sid}/marks/E001/justification";

        $this->api($user)->putJson($url, ['justification' => 'medical'])->assertOk();
        $this->assertSame('medical', DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('justificatif'));
        $this->api($user)->putJson($url, ['justification' => 'excuse'])->assertStatus(422);
        $this->api($user)->putJson($url, ['justification' => null])->assertOk();
        $this->assertNull(DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('justificatif'));
        $this->assertSame('absent', DB::table('t_presence_ligne')->where('seance_id', $sid)->where('student_id', 'E001')->value('statut'), 'un justificatif ne change pas le pointage');
    }

    public function test_mark_all_present_only_touches_students_not_marked_yet(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertOk();
        $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E002", ['status' => 'retard', 'minutes_late' => 8])->assertOk();

        $this->api($user)->postJson("/api/v1/teacher/sessions/{$sid}/mark-all-present")->assertOk()->assertJsonPath('data.marked', 1);
        $this->api($user)->postJson("/api/v1/teacher/sessions/{$sid}/mark-all-present")->assertOk()->assertJsonPath('data.marked', 0);

        $this->assertSame([['absent', null], ['retard', 8], ['present', null]], [$this->line($sid, 'E001'), $this->line($sid, 'E002'), $this->line($sid, 'E003')]);
    }

    public function test_mark_all_present_also_reaches_students_enrolled_after_the_session_opened(): void
    {
        [$user, $course] = $this->classroom();
        $sid = $this->openSession($user, $course);
        $this->enrol($course, ['E004' => ['Zafy', 'Mia']]);

        $this->api($user)->postJson("/api/v1/teacher/sessions/{$sid}/mark-all-present")->assertOk()->assertJsonPath('data.marked', 4);
        $this->assertSame(['present', null], $this->line($sid, 'E004'));
    }

    // ------------------------------------------------- base des 15 % et bilans

    public function test_setting_planned_sessions_gives_the_allowed_absences(): void
    {
        [$user, $course] = $this->classroom();

        $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base() + ['planned' => 30])->assertOk()
            ->assertJsonPath('data.planned_sessions', 30)->assertJsonPath('data.allowed_absences', 4);
        $this->assertSame(30, (int) DB::table('t_presence_feuille')->where('cours_id', $course)->value('seances_prevues'));

        $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base() + ['planned' => null])->assertOk()->assertJsonPath('data.allowed_absences', null);
        $this->assertNull(DB::table('t_presence_feuille')->where('cours_id', $course)->value('seances_prevues'));
        $this->assertSame(1, DB::table('t_presence_feuille')->where('cours_id', $course)->count(), 'toujours un seul registre');
    }

    public function test_planned_sessions_are_bounded(): void
    {
        [$user, $course] = $this->classroom();

        foreach ([0, 201, -1, 'beaucoup', 3.5] as $bad) {
            $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base() + ['planned' => $bad])->assertStatus(422);
        }
        $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base() + ['planned' => 200])->assertOk();
        $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base() + ['planned' => 1])->assertOk();
        $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base())->assertStatus(422);   // la clé est obligatoire (null = vider)
    }

    public function test_the_mark_response_carries_the_up_to_date_balance(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $this->openSheet($course, 20, 1, null, $uid);   // 3 absences permises
        $url = fn (int $s) => "/api/v1/teacher/sessions/{$s}/marks/E001";

        $res = null;
        foreach ([1, 2, 3] as $i) {
            $res = $this->api($user)->putJson($url($this->openSession($user, $course, $this->today($i))), ['status' => 'absent'])->assertOk();
        }
        $this->assertSame([3, 3, 'limit', false], [$res->json('data.summary.counted'), $res->json('data.summary.allowed'), $res->json('data.summary.state'), $res->json('data.newly_flagged')]);
        $this->assertSame(0, DB::table('t_presence_signalement')->count());
    }

    public function test_exceeding_fifteen_percent_flags_the_student_once(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 20, 1, null, $uid);

        $newly = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $sid = $this->openSession($user, $course, $this->today($i));
            $newly[] = $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'absent'])->assertOk()->json('data.newly_flagged');
        }

        $this->assertSame([false, false, false, true, false], $newly, 'signalé à la 4e absence (> 3 permises), jamais deux fois');
        $flag = DB::table('t_presence_signalement')->where('feuille_id', $sheet)->get();
        $this->assertCount(1, $flag);
        $this->assertSame(['E001', 4, 20, $user->id], [$flag[0]->student_id, (int) $flag[0]->absences, (int) $flag[0]->seances_prevues, (int) $flag[0]->signale_par]);
        $this->assertNull($flag[0]->traite_le);
    }

    public function test_two_lates_count_as_one_absence_for_the_flag(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $this->openSheet($course, 10, 1, null, $uid);   // 1 absence permise
        $flagged = [];
        foreach ([1, 2, 3, 4] as $i) {   // 4 retards de 6 min = 2 absences comptées > 1
            $sid = $this->openSession($user, $course, $this->today($i));
            $flagged[] = $this->api($user)->putJson("/api/v1/teacher/sessions/{$sid}/marks/E001", ['status' => 'retard', 'minutes_late' => 6])->assertOk()->json('data.newly_flagged');
        }

        $this->assertSame([false, false, false, true], $flagged);
        $flag = DB::table('t_presence_signalement')->first();
        $this->assertSame([2, 10], [(int) $flag->absences, (int) $flag->seances_prevues], 'le signalement garde les absences COMPTÉES (4 retards = 2), pas les absences brutes (0)');
    }

    public function test_a_teacher_who_arrives_late_without_warning_cancels_the_penalty_and_the_flag(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $this->openSheet($course, 10, 1, null, $uid);   // 1 absence permise
        $late = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => $this->today(1), 'planned_time' => '08:00', 'teacher_arrival' => '08:30'])->json('data.session_id');
        $b = $this->openSession($user, $course, $this->today(2));
        $c = $this->openSession($user, $course, $this->today(3));

        foreach ([$late, $b] as $s) {
            $res = $this->api($user)->putJson("/api/v1/teacher/sessions/{$s}/marks/E001", ['status' => 'absent'])->assertOk();
        }
        $this->assertSame([1, 1, 'limit', false], [$res->json('data.summary.counted'), $res->json('data.summary.no_penalty') === 1 ? 1 : 0, $res->json('data.summary.state'), $res->json('data.newly_flagged')], '§474 : l’absence de la séance sans pénalité ne compte pas');
        $this->assertSame(0, DB::table('t_presence_signalement')->count());

        $this->api($user)->putJson("/api/v1/teacher/sessions/{$c}/marks/E001", ['status' => 'absent'])->assertOk()->assertJsonPath('data.newly_flagged', true);
    }

    public function test_lowering_the_planned_sessions_flags_students_without_a_new_absence(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 40, 1, null, $uid);   // 6 permises
        foreach ([1, 2, 3] as $i) {
            $this->api($user)->putJson('/api/v1/teacher/sessions/'.$this->openSession($user, $course, $this->today($i)).'/marks/E001', ['status' => 'absent'])->assertOk();
        }
        $this->assertSame(0, DB::table('t_presence_signalement')->count());

        $res = $this->api($user)->putJson("/api/v1/teacher/courses/{$course}/planned", $this->base() + ['planned' => 10])->assertOk();   // 1 permise : 3 absences dépassent

        $this->assertSame(1, $res->json('data.newly_flagged'));
        $this->assertSame(['E001'], DB::table('t_presence_signalement')->where('feuille_id', $sheet)->pluck('student_id')->all());
    }

    public function test_taking_away_a_sessions_exemption_flags_students_without_a_new_absence(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 10, 1, null, $uid);   // 1 permise
        $exempt = $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => $this->today(1), 'planned_time' => '08:00', 'teacher_arrival' => '08:40'])->json('data.session_id');
        $other = $this->openSession($user, $course, $this->today(2));
        foreach ([$exempt, $other] as $s) {
            $this->api($user)->putJson("/api/v1/teacher/sessions/{$s}/marks/E001", ['status' => 'absent'])->assertOk();
        }
        $this->assertSame(0, DB::table('t_presence_signalement')->count(), 'deux absences, dont une sans pénalité (§474) : une seule compte, c’est la limite');

        // L'enseignant dit qu'il avait prévenu : la séance redevient normale, la 2e absence compte, 2 > 1.
        $res = $this->api($user)->patchJson("/api/v1/teacher/sessions/{$exempt}", ['planned_time' => '08:00', 'teacher_arrival' => '08:40', 'warned' => true])->assertOk();

        $this->assertSame([1, false], [$res->json('data.newly_flagged'), $res->json('data.session.no_penalty')]);
        $this->assertSame(['E001'], DB::table('t_presence_signalement')->where('feuille_id', $sheet)->pluck('student_id')->all());
    }

    public function test_no_flag_without_planned_sessions(): void
    {
        [$user, $course] = $this->classroom();
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            $this->api($user)->putJson('/api/v1/teacher/sessions/'.$this->openSession($user, $course, $this->today($i)).'/marks/E001', ['status' => 'absent'])->assertOk()->assertJsonPath('data.newly_flagged', false);
        }

        $this->assertSame(0, DB::table('t_presence_signalement')->count());
    }
}
