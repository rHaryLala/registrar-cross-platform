<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\AttendanceReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/** Assiduité vue du registrariat : signalements au-delà de 15 % (§476), retards des enseignants (§474), registres sans base. */
class AttendanceReviewTest extends TestCase
{
    use MakesTeacherData;

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function registrar(): User
    {
        return User::factory()->staff(3, 'registrar')->create();
    }

    /** Une séance, avec un statut par étudiant (matricule => [statut, minutes]). */
    private function held(int $sheet, string $date, array $marks = [], ?string $planned = null, ?string $arrival = null, bool $warned = false): int
    {
        $id = DB::table('t_presence_seance')->insertGetId([
            'feuille_id' => $sheet, 'date_seance' => $date, 'heure_prevue' => $planned, 'arrivee_enseignant' => $arrival, 'message_retard' => $warned ? 1 : 0,
            'cree_le' => $date.' 09:00:00', 'modifie_le' => $date.' 09:00:00',
        ]);
        foreach ($marks as $student => $mark) {
            [$status, $minutes] = is_array($mark) ? $mark : [$mark, null];
            DB::table('t_presence_ligne')->insert(['seance_id' => $id, 'student_id' => $student, 'statut' => $status, 'minutes_retard' => $minutes, 'modifie_le' => $date.' 09:00:00']);
        }

        return $id;
    }

    private function flag(int $sheet, string $student, int $absences = 4, int $planned = 20, string $at = '2026-10-10 10:00:00', ?string $processedAt = null): int
    {
        return DB::table('t_presence_signalement')->insertGetId([
            'feuille_id' => $sheet, 'student_id' => $student, 'absences' => $absences, 'seances_prevues' => $planned, 'signale_le' => $at,
            'traite_le' => $processedAt, 'traite_par' => $processedAt ? 1 : null,
        ]);
    }

    /** Un registre de 20 séances prévues où E001 a 4 absences (au-delà de 15 % = 3). @return array{0: int, 1: int} registre, signalement */
    private function flagged(): array
    {
        [, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 20, 1, null, $uid);
        for ($i = 1; $i <= 4; $i++) {
            $this->held($sheet, '2026-09-0'.$i, ['E001' => 'absent', 'E002' => 'present']);
        }

        return [$sheet, $this->flag($sheet, 'E001')];
    }

    private function overview(User $who, string $query = '')
    {
        return $this->api($who)->getJson('/api/v1/review/attendance'.$query);
    }

    // ------------------------------------------------------------------ accès

    public function test_only_superadmin_and_registrar_can_open_the_attendance_page(): void
    {
        [, $flag] = $this->flagged();
        [$teacher] = $this->makeTeacher('Zo', 'Rabe');

        foreach ([User::factory()->staff(2, 'admin')->create(), User::factory()->staff(4, 'comptabilite')->create(), $teacher, User::factory()->create(), User::factory()->staff(3, 'registrar')->inactive()->create()] as $who) {
            $this->overview($who)->assertForbidden()->assertJsonPath('code', 'forbidden.registrar_only');
            $this->api($who)->postJson("/api/v1/review/attendance/flags/{$flag}/process")->assertForbidden();
        }
        $this->assertNull(DB::table('t_presence_signalement')->where('id', $flag)->value('traite_le'));

        $this->overview($this->registrar())->assertOk();
        $this->overview(User::factory()->staff(1, 'superadmin')->create())->assertOk();
    }

    public function test_it_requires_a_session(): void
    {
        $this->getJson('/api/v1/review/attendance')->assertUnauthorized();
        $this->postJson('/api/v1/review/attendance/flags/1/process')->assertUnauthorized();
    }

    public function test_the_profile_tells_who_has_the_attendance_space(): void
    {
        $this->api($this->registrar())->getJson('/api/v1/auth/me')->assertJsonPath('data.attendance_space', true);
        $this->api(User::factory()->staff(1, 'superadmin')->create())->getJson('/api/v1/auth/me')->assertJsonPath('data.attendance_space', true);
        $this->api(User::factory()->staff(2, 'admin')->create())->getJson('/api/v1/auth/me')->assertJsonPath('data.attendance_space', false);
        $this->api(User::factory()->create())->getJson('/api/v1/auth/me')->assertJsonPath('data.attendance_space', false);
    }

    // ------------------------------------------------------------------ signalements

    public function test_flags_list_the_student_course_teacher_and_the_up_to_date_count(): void
    {
        [$sheet, $flag] = $this->flagged();

        $d = $this->overview($this->registrar(), '?year=2026+-+2027')->assertOk()->json('data');

        $this->assertSame(1, $d['pending_total']);
        $this->assertCount(1, $d['flags']);
        $f = $d['flags'][0];
        $this->assertSame([$flag, 'E001', 'Andria', 'Zo', 'Andry Rasoa'], [$f['id'], $f['student_id'], $f['last_name'], $f['first_name'], $f['teacher']]);
        $this->assertSame([4, 20, null], [$f['counted_at_flag'], $f['planned_at_flag'], $f['processed_at']]);
        $this->assertSame(['exceeded', 4, 3, 20.0], [$f['today']['state'], $f['today']['counted'], $f['today']['allowed'], (float) $f['today']['rate']]);
        $this->assertSame('Semestre 1', $f['semester_label']);
        $this->assertSame($sheet, DB::table('t_presence_feuille')->where('cours_id', $f['course_id'])->value('id'));
    }

    public function test_the_count_is_refreshed_when_an_absence_was_corrected_since(): void
    {
        [$sheet] = $this->flagged();
        DB::table('t_presence_ligne')->where('student_id', 'E001')->update(['statut' => 'present']);

        $f = $this->overview($this->registrar())->json('data.flags.0');

        $this->assertSame(['ok', 0], [$f['today']['state'], $f['today']['counted']]);
        $this->assertSame(4, $f['counted_at_flag'], 'le compte au moment du signalement reste affiché');
    }

    public function test_the_count_is_the_flagged_students_own_not_the_classs(): void
    {
        [, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 20, 1, null, $uid);
        $this->held($sheet, '2026-09-01', ['E001' => 'absent', 'E002' => 'absent', 'E003' => 'absent']);
        $this->held($sheet, '2026-09-02', ['E001' => 'present', 'E002' => 'absent', 'E003' => 'absent']);
        $this->flag($sheet, 'E001', 4, 20);

        $t = $this->overview($this->registrar())->json('data.flags.0.today');

        $this->assertSame([2, 1, 1], [$t['recorded'], $t['absent'], $t['present']]);
    }

    public function test_two_lates_count_as_one_absence_and_a_teacher_no_show_cancels_the_session(): void
    {
        [, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 20, 1, null, $uid);
        $this->held($sheet, '2026-09-01', ['E001' => ['retard', 6]]);
        $this->held($sheet, '2026-09-02', ['E001' => ['retard', 7]]);
        $this->held($sheet, '2026-09-03', ['E001' => 'absent']);                                       // pénalité
        $this->held($sheet, '2026-09-04', ['E001' => 'absent'], '08:00', '08:25', false);              // §474 : enseignant arrivé 25 min après, sans prévenir
        $this->held($sheet, '2026-09-05', ['E001' => 'absent'], '08:00', '08:25', true);               // il avait prévenu : l'absence compte
        $this->flag($sheet, 'E001', 3, 20);

        $t = $this->overview($this->registrar())->json('data.flags.0.today');

        $this->assertSame([2, 1, 1, 3, 2], [$t['late'], $t['converted_lates'], $t['no_penalty'], $t['counted'], $t['absent']]);
    }

    public function test_unprocessed_flags_come_first_and_processed_ones_only_on_request(): void
    {
        [$sheet, $open] = $this->flagged();
        $done = $this->flag($sheet, 'E002', 4, 20, '2026-10-20 10:00:00', '2026-10-21 08:00:00');
        $older = $this->flag($sheet, 'E003', 4, 20, '2026-10-01 10:00:00');
        $reg = $this->registrar();

        $this->assertSame([$open, $older], array_column($this->overview($reg)->json('data.flags'), 'id'), 'les plus récents d’abord, traités masqués');
        $all = $this->overview($reg, '?processed=1')->json('data.flags');
        $this->assertSame([$open, $older, $done], array_column($all, 'id'), 'les traités à la fin');
        $this->assertSame('2026-10-21 08:00:00', $all[2]['processed_at']);
    }

    public function test_flags_are_filtered_by_year_but_the_pending_total_spans_all_years(): void
    {
        [$sheet] = $this->flagged();
        [, $c2, $u2] = $this->classroom();
        $other = $this->openSheet($c2, 20, 1, '2025 - 2026', $u2);
        $this->flag($other, 'E002', 5, 20);
        $reg = $this->registrar();

        $d = $this->overview($reg, '?year=2025+-+2026')->json('data');
        $this->assertSame(1, count($d['flags']));
        $this->assertSame($other, DB::table('t_presence_feuille')->where('cours_id', $d['flags'][0]['course_id'])->value('id'));
        $this->assertSame(2, $d['pending_total']);
        $this->assertSame(['2026 - 2027', '2025 - 2026'], $d['years']);
    }

    public function test_the_current_year_is_the_default_and_is_always_offered(): void
    {
        $d = $this->overview($this->registrar(), '?year=2031+-+2032')->json('data');
        $this->assertSame('2031 - 2032', $d['year']);
        $this->assertSame('2031 - 2032', $d['years'][0]);

        $this->assertSame(\App\Domain\Teacher\TeacherAttendance::currentYear(), $this->overview($this->registrar())->json('data.year'));
    }

    public function test_the_year_must_look_like_a_school_year(): void
    {
        $this->overview($this->registrar(), '?year=abc')->assertUnprocessable();
        $this->overview($this->registrar(), '?year='.urlencode("2026 - 2027' OR 1=1"))->assertUnprocessable();
    }

    // ------------------------------------------------------------------ retards des enseignants

    public function test_teacher_delays_are_only_those_over_ten_minutes(): void
    {
        [, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 20, 1, null, $uid);
        $this->held($sheet, '2026-09-01', [], '08:00', '08:10');          // 10 min : toléré
        $late = $this->held($sheet, '2026-09-02', [], '08:00', '08:11');   // 11 min : relevé
        $this->held($sheet, '2026-09-03', [], '08:00', '07:40');           // en avance
        $this->held($sheet, '2026-09-04', [], null, '09:00');              // pas d'heure prévue
        $this->held($sheet, '2026-09-05', [], '08:00', null);              // pas d'arrivée notée
        $warned = $this->held($sheet, '2026-09-06', [], '14:00:00', '15:30:00', true);

        $d = $this->overview($this->registrar())->json('data');

        $this->assertSame([$warned, $late], array_column($d['teacher_delays'], 'id'), 'la plus récente d’abord');
        $this->assertSame([90, true, '14:00', '15:30', 'Andry Rasoa'], [$d['teacher_delays'][0]['late_minutes'], $d['teacher_delays'][0]['warned'], $d['teacher_delays'][0]['planned_time'], $d['teacher_delays'][0]['arrival'], $d['teacher_delays'][0]['teacher']]);
        $this->assertSame([11, false], [$d['teacher_delays'][1]['late_minutes'], $d['teacher_delays'][1]['warned']]);
        $this->assertSame(10, $d['wait_minutes']);
    }

    public function test_delays_are_counted_by_teacher_the_most_late_first(): void
    {
        [, $c1, $u1] = $this->classroom();
        [, $u2] = $this->makeTeacher('Voahangy', 'Randria');
        $c2 = $this->makeCourse($u2);
        $s1 = $this->openSheet($c1, 20, 1, null, $u1);
        $s2 = $this->openSheet($c2, 20, 1, null, $u2);
        $this->held($s1, '2026-09-09', [], '08:00', '08:30');   // la plus récente : sans tri, Andry viendrait en premier
        $this->held($s2, '2026-09-01', [], '08:00', '08:30');
        $this->held($s2, '2026-09-02', [], '08:00', '08:30');
        $this->held($s2, '2026-09-03', [], '08:00', '08:30');

        $this->assertSame(
            [['teacher' => 'Voahangy Randria', 'count' => 3], ['teacher' => 'Andry Rasoa', 'count' => 1]],
            $this->overview($this->registrar())->json('data.delays_by_teacher'),
        );
    }

    public function test_a_course_without_teacher_is_grouped_under_a_neutral_name(): void
    {
        [, $course] = $this->classroom();
        DB::table('t_2023_cours')->where('id', $course)->update(['id_teacher' => null]);
        $sheet = $this->openSheet($course, 20);
        $this->held($sheet, '2026-09-01', [], '08:00', '09:00');

        $d = $this->overview($this->registrar())->json('data');

        $this->assertNull($d['teacher_delays'][0]['teacher']);
        $this->assertSame([['teacher' => 'Enseignant non renseigné', 'count' => 1]], $d['delays_by_teacher']);
    }

    public function test_other_years_delays_are_left_out(): void
    {
        [, $course, $uid] = $this->classroom();
        $old = $this->openSheet($course, 20, 1, '2024 - 2025', $uid);
        $this->held($old, '2025-03-01', [], '08:00', '09:00');

        $this->assertSame([], $this->overview($this->registrar())->json('data.teacher_delays'));
        $this->assertCount(1, $this->overview($this->registrar(), '?year=2024+-+2025')->json('data.teacher_delays'));
    }

    // ------------------------------------------------------------------ registres sans base

    public function test_registers_held_without_planned_sessions_are_listed(): void
    {
        [, $course, $uid] = $this->classroom();
        $noBase = $this->openSheet($course, null, 1, null, $uid);
        $this->held($noBase, '2026-09-01');
        $this->held($noBase, '2026-09-08');
        [, $c2, $u2] = $this->classroom();
        $this->openSheet($c2, null, 1, null, $u2);                                         // ouvert mais jamais tenu
        [, $c3, $u3] = $this->classroom();
        $this->held($this->openSheet($c3, 20, 1, null, $u3), '2026-09-01');            // base indiquée

        $list = $this->overview($this->registrar())->json('data.without_base');

        $this->assertCount(1, $list);
        $this->assertSame([$noBase, $course, 2, 'Andry Rasoa', 'Semestre 1'], [$list[0]['sheet_id'], $list[0]['course_id'], $list[0]['held_sessions'], $list[0]['teacher'], $list[0]['semester_label']]);
    }

    // ------------------------------------------------------------------ traiter

    public function test_processing_a_flag_stamps_who_and_when_and_leaves_a_trace(): void
    {
        [, $flag] = $this->flagged();
        $reg = $this->registrar();
        $this->travelTo('2026-11-02 14:30:00');

        $this->api($reg)->postJson("/api/v1/review/attendance/flags/{$flag}/process")->assertOk()
            ->assertJsonPath('data', ['id' => $flag, 'processed' => true, 'processed_at' => '2026-11-02 14:30:00']);

        $row = DB::table('t_presence_signalement')->where('id', $flag)->first();
        $this->assertSame(['2026-11-02 14:30:00', $reg->id], [$row->traite_le, $row->traite_par]);
        $log = DB::table('t_audit_requete')->where('libelle', 'Assiduité · signalement traité')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, DB::table('t_audit_modification')->where('requete_id', $log->id)->where('table_nom', 't_presence_signalement')->count());
        $this->assertSame(0, $this->overview($reg)->json('data.pending_total'));
    }

    public function test_processing_twice_changes_nothing_the_second_time(): void
    {
        [, $flag] = $this->flagged();
        $reg = $this->registrar();
        $this->travelTo('2026-11-02 14:30:00');
        $this->api($reg)->postJson("/api/v1/review/attendance/flags/{$flag}/process")->assertOk();
        $this->travelTo('2026-11-03 09:00:00');

        $other = User::factory()->staff(1, 'superadmin')->create();
        $this->api($other)->postJson("/api/v1/review/attendance/flags/{$flag}/process")->assertOk()
            ->assertJsonPath('data.processed', false)->assertJsonPath('data.processed_at', '2026-11-02 14:30:00');

        $row = DB::table('t_presence_signalement')->where('id', $flag)->first();
        $this->assertSame(['2026-11-02 14:30:00', $reg->id], [$row->traite_le, $row->traite_par], 'le premier traitement est conservé');
        $this->assertSame(1, DB::table('t_audit_modification')->where('table_nom', 't_presence_signalement')->count());
    }

    public function test_processing_an_unknown_flag_is_a_404_problem(): void
    {
        $this->api($this->registrar())->postJson('/api/v1/review/attendance/flags/999999/process')->assertNotFound()->assertJsonPath('code', 'attendance.flag_not_found');
    }

    public function test_processing_touches_only_the_chosen_flag(): void
    {
        [$sheet, $flag] = $this->flagged();
        $other = $this->flag($sheet, 'E002');

        $this->api($this->registrar())->postJson("/api/v1/review/attendance/flags/{$flag}/process")->assertOk();

        $this->assertNull(DB::table('t_presence_signalement')->where('id', $other)->value('traite_le'));
    }

    public function test_the_domain_checks_access_on_its_own(): void
    {
        [, $flag] = $this->flagged();
        $review = app(AttendanceReview::class);
        $admin = User::factory()->staff(2, 'admin')->create();

        foreach ([fn () => $review->overview($admin, null, false), fn () => $review->process($admin, $flag)] as $call) {
            try {
                $call();
                $this->fail('accès accordé à l’administration');
            } catch (\App\Exceptions\ApiProblem $e) {
                $this->assertSame(403, $e->status);
            }
        }
    }

    public function test_responses_are_never_cached(): void
    {
        $this->overview($this->registrar())->assertHeader('Cache-Control', 'no-store, private');
    }
}
