<?php

namespace Tests\Feature\Teacher;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/** Registres de présence, côté enseignant : qui entre, quels cours, ce que montre le registre — et que lire n'écrit jamais. */
class AttendanceReadTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;
    use MakesTeacherData;

    private function api(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp();
    }

    public function test_a_student_cannot_enter_the_teacher_space(): void
    {
        $this->api(User::factory()->create())->getJson('/api/v1/teacher/courses')->assertForbidden()->assertJsonPath('code', 'forbidden.teacher_only');
    }

    public function test_an_anonymous_visitor_is_asked_to_log_in(): void
    {
        $this->getJson('/api/v1/teacher/courses')->assertUnauthorized();
    }

    public function test_an_inactive_teacher_is_refused(): void
    {
        [$user] = $this->makeTeacher();
        $user->update(['etat' => 0]);

        $this->api($user)->getJson('/api/v1/teacher/courses')->assertStatus(403);
    }

    public function test_the_course_list_holds_only_my_courses_with_the_register_state(): void
    {
        [$user, $uid] = $this->makeTeacher();
        [, $otherUid] = $this->makeTeacher('Naina', 'Rabe');
        $mine = $this->makeCourse($uid, 'MAT 101', 'Analyse');
        $theirs = $this->makeCourse($otherUid, 'PHY 101', 'Mécanique');
        $this->enrol($mine, ['E001' => ['A', 'a'], 'E002' => ['B', 'b']]);
        $this->enrol($theirs, ['E003' => ['C', 'c']]);
        $sheet = $this->openSheet($mine, 30, 1, null, $uid);
        $this->makeMeeting($sheet, '2026-09-07');

        $res = $this->api($user)->getJson('/api/v1/teacher/courses?year='.rawurlencode($this->year))->assertOk();

        $this->assertSame(1, count($res->json('data.courses')));
        $c = $res->json('data.courses.0');
        $this->assertSame([$mine, 2, 1, 30, 0], [$c['id'], $c['students'], $c['held_sessions'], $c['planned_sessions'], $c['flagged']]);
        $this->assertStringStartsWith('MAT 101', $c['code']);
        $this->assertContains($this->year, $res->json('data.years'));
    }

    public function test_the_display_code_wins_over_the_raw_code(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);
        DB::table('t_2023_cours')->where('id', $course)->update(['sigle_affiche' => 'ALIAS-9']);
        $this->enrol($course, ['E001' => ['A', 'a']]);

        $this->assertSame('ALIAS-9', $this->api($user)->getJson('/api/v1/teacher/courses?year='.rawurlencode($this->year))->json('data.courses.0.code'));
    }

    public function test_suspended_and_withdrawn_students_are_not_counted(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);
        $this->enrol($course, ['E001' => ['A', 'a'], 'E002' => ['B', 'b'], 'E003' => ['C', 'c']], 1, null, ['E002' => ['suspended' => 1], 'E003' => ['retrait_universite' => 1]]);

        $res = $this->api($user)->getJson('/api/v1/teacher/courses/'.$course.'/register?year='.rawurlencode($this->year).'&semester=1')->assertOk();

        $this->assertSame(['E001'], array_column($res->json('data.students'), 'id'));
    }

    public function test_an_unlinked_teacher_gets_an_empty_list_and_a_flag(): void
    {
        $user = User::factory()->staff(7, 'teacher')->create(['nom' => 'Inconnu', 'prenom' => 'Personne', 'mail' => 'personne@exemple.test', 'teacher_uid' => null]);

        $this->api($user)->getJson('/api/v1/teacher/courses')->assertOk()->assertJsonPath('data.linked', false)->assertJsonPath('data.courses', []);
    }

    public function test_an_unlinked_account_is_matched_by_name_then_mail_only_when_unambiguous(): void
    {
        [$user, $uid] = $this->makeTeacher('Hery', 'Rakoto', false);
        $course = $this->makeCourse($uid);
        $this->enrol($course, ['E001' => ['A', 'a']]);
        $url = '/api/v1/teacher/courses?year='.rawurlencode($this->year);

        $this->assertSame(1, count($this->api($user)->getJson($url)->json('data.courses')), 'le nom (prénom + nom) retrouve la fiche');

        // Un homonyme : on ne devine plus, aucun cours n'est donné à l'un des deux.
        DB::table('teacher')->insert(['uid' => 799999, 'name' => 'Hery', 'lastName' => 'Rakoto', 'sex' => 0, 'remove' => 0]);
        $this->assertSame([], $this->api($user)->getJson($url)->json('data.courses'));
    }

    public function test_the_register_of_a_course_of_another_teacher_is_not_found_not_forbidden(): void
    {
        [$user] = $this->makeTeacher();
        [, $otherUid] = $this->makeTeacher('Naina', 'Rabe');
        $theirs = $this->makeCourse($otherUid);
        $this->enrol($theirs, ['E001' => ['A', 'a']]);
        $url = '/api/v1/teacher/courses/COURSE/register?year='.rawurlencode($this->year).'&semester=1';

        $own = $this->api($user)->getJson(str_replace('COURSE', (string) $theirs, $url));
        $missing = $this->api($user)->getJson(str_replace('COURSE', '999999', $url));

        $own->assertNotFound()->assertJsonPath('code', 'presence.not_found');
        $this->assertSame($missing->json('code'), $own->json('code'), 'un cours d’autrui est indiscernable d’un cours inexistant');
    }

    public function test_the_register_lists_students_sessions_and_balances_without_writing_anything(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 20, 1, null, $uid);   // 3 absences permises
        $m1 = $this->makeMeeting($sheet, '2026-09-07');
        $m2 = $this->makeMeeting($sheet, '2026-09-14');
        foreach (['E001', 'E002', 'E003'] as $sid) {
            $this->makeLine($m1, $sid, $sid === 'E001' ? 'absent' : 'present');
            $this->makeLine($m2, $sid, $sid === 'E001' ? 'absent' : null);
        }
        $before = [DB::table('t_presence_feuille')->count(), DB::table('t_presence_seance')->count(), DB::table('t_presence_ligne')->count(), DB::table('t_audit_requete')->count()];

        $res = $this->api($user)->getJson('/api/v1/teacher/courses/'.$course.'/register?year='.rawurlencode($this->year).'&semester=1')->assertOk();

        $this->assertSame(['E001', 'E002', 'E003'], array_column($res->json('data.students'), 'id'));
        $this->assertSame(['Andria', 'Zo'], [$res->json('data.students.0.last_name'), $res->json('data.students.0.first_name')]);
        $this->assertSame([2, 'ok'], [$res->json('data.students.0.summary.counted'), $res->json('data.students.0.summary.state')]);
        $this->assertSame([20, 3], [$res->json('data.sheet.planned_sessions'), $res->json('data.sheet.allowed_absences')]);
        $this->assertSame(['2026-09-14', '2026-09-07'], array_column($res->json('data.sessions'), 'date'), 'la plus récente d’abord');
        $this->assertSame([2, 0, 1], [$res->json('data.sessions.1.absent') + $res->json('data.sessions.0.absent'), $res->json('data.sessions.1.late'), $res->json('data.sessions.1.absent')]);
        $this->assertSame(2, $res->json('data.sessions.0.to_mark'), 'E002 et E003 ne sont pas pointés le 14');
        $this->assertSame(0, $res->json('data.sessions.1.to_mark'));
        $this->assertSame($before, [DB::table('t_presence_feuille')->count(), DB::table('t_presence_seance')->count(), DB::table('t_presence_ligne')->count(), DB::table('t_audit_requete')->count()], 'une lecture n’écrit rien');
    }

    public function test_reading_an_unopened_register_does_not_create_it(): void
    {
        [$user, $course] = $this->classroom();

        $res = $this->api($user)->getJson('/api/v1/teacher/courses/'.$course.'/register?year='.rawurlencode($this->year).'&semester=1')->assertOk();

        $this->assertNull($res->json('data.sheet.id'));
        $this->assertSame([], $res->json('data.sessions'));
        $this->assertSame(3, count($res->json('data.students')));
        $this->assertSame('unknown', $res->json('data.students.0.summary.state'));
        $this->assertSame(0, DB::table('t_presence_feuille')->where('cours_id', $course)->count());
    }

    public function test_a_session_shows_every_enrolled_student_even_without_a_line_yet(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 30, 1, null, $uid);
        $m = $this->makeMeeting($sheet, '2026-09-07', '08:00:00', '08:25:00');
        $this->makeLine($m, 'E001', 'retard', 12, 'medical');

        $res = $this->api($user)->getJson('/api/v1/teacher/sessions/'.$m)->assertOk();

        $this->assertSame(['E001', 'E002', 'E003'], array_column($res->json('data.lines'), 'student_id'));
        $l = $res->json('data.lines.0');
        $this->assertSame(['retard', 12, 'medical', 'absence'], [$l['status'], $l['minutes_late'], $l['justification'], $l['category']]);
        $this->assertNull($res->json('data.lines.1.status'));
        $this->assertSame([25, true], [$res->json('data.session.teacher_late_minutes'), $res->json('data.session.no_penalty')], 'l’enseignant a 25 min de retard sans prévenir : §474');
    }

    public function test_a_session_of_another_teacher_is_not_found(): void
    {
        [$user] = $this->makeTeacher();
        [, $course, $uid] = $this->classroom();
        $m = $this->makeMeeting($this->openSheet($course, 30, 1, null, $uid), '2026-09-07');

        $this->api($user)->getJson('/api/v1/teacher/sessions/'.$m)->assertNotFound()->assertJsonPath('code', 'presence.not_found');
    }

    public function test_registrariat_staff_can_open_any_register(): void
    {
        [, $course] = $this->classroom();
        $staff = User::factory()->staff(3, 'registrar')->create();

        $this->api($staff)->getJson('/api/v1/teacher/courses/'.$course.'/register?year='.rawurlencode($this->year).'&semester=1')->assertOk();
    }

    public function test_the_year_must_look_like_a_school_year(): void
    {
        [$user, $course] = $this->classroom();

        $this->api($user)->getJson('/api/v1/teacher/courses/'.$course.'/register?year=n-importe-quoi')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_responses_are_never_cached(): void
    {
        [$user] = $this->makeTeacher();

        $this->assertStringContainsString('no-store', (string) $this->api($user)->getJson('/api/v1/teacher/courses')->headers->get('Cache-Control'));
    }

    public function test_removed_enrolments_other_semesters_and_other_years_stay_out_of_the_roll(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);
        $this->enrol($course, ['E001' => ['A', 'a'], 'E002' => ['B', 'b']], 1);
        $this->enrol($course, ['E003' => ['C', 'c']], 2);                      // un autre semestre
        $this->enrol($course, ['E004' => ['D', 'd']], 1, '2025 - 2026');       // une autre année
        DB::table('t_2023_notes')->where('student_id', 'E002')->update(['remove' => 1]);   // inscription retirée
        $url = fn (string $year, int $sem) => "/api/v1/teacher/courses/{$course}/register?year=".rawurlencode($year)."&semester={$sem}";

        $this->assertSame(['E001'], array_column($this->api($user)->getJson($url($this->year, 1))->json('data.students'), 'id'));
        $this->assertSame(['E003'], array_column($this->api($user)->getJson($url($this->year, 2))->json('data.students'), 'id'));
        $this->assertSame(['E004'], array_column($this->api($user)->getJson($url('2025 - 2026', 1))->json('data.students'), 'id'));
    }

    public function test_removed_courses_and_other_years_are_not_listed(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $kept = $this->makeCourse($uid, 'KEP 101');
        $removed = $this->makeCourse($uid, 'REM 101');
        $old = $this->makeCourse($uid, 'OLD 101');
        $this->enrol($kept, ['E001' => ['A', 'a']]);
        $this->enrol($removed, ['E001' => ['A', 'a']]);
        $this->enrol($old, ['E001' => ['A', 'a']], 1, '2025 - 2026');
        DB::table('t_2023_cours')->where('id', $removed)->update(['remove' => 1]);

        $list = fn (string $y) => array_column($this->api($user)->getJson('/api/v1/teacher/courses?year='.rawurlencode($y))->json('data.courses'), 'id');

        $this->assertSame([$kept], $list($this->year));
        $this->assertSame([$old], $list('2025 - 2026'));
        $this->assertSame([], $list('2019 - 2020'));
        $this->api($user)->getJson("/api/v1/teacher/courses/{$removed}/register?year=".rawurlencode($this->year).'&semester=1')->assertNotFound();
    }

    public function test_the_default_year_is_the_current_one_or_else_the_latest_with_courses(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);
        $this->enrol($course, ['E001' => ['A', 'a']], 1, '2024 - 2025');

        $res = $this->api($user)->getJson('/api/v1/teacher/courses')->assertOk();

        $this->assertSame('2024 - 2025', $res->json('data.year'), 'aucun cours cette année : on ouvre la dernière année qui en a');
        $this->assertContains(\App\Domain\Teacher\TeacherAttendance::currentYear(), $res->json('data.years'));
    }

    public function test_the_school_year_changes_in_july(): void
    {
        $y = fn (string $d) => \App\Domain\Teacher\TeacherAttendance::currentYear(\Illuminate\Support\Carbon::parse($d));

        $this->assertSame(['2025 - 2026', '2026 - 2027', '2026 - 2027', '2026 - 2027', '2026 - 2027'], [$y('2026-06-30'), $y('2026-07-01'), $y('2026-12-31'), $y('2027-01-01'), $y('2027-06-30')]);
    }

    public function test_a_session_still_lists_a_marked_student_who_was_suspended_since(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $m = $this->makeMeeting($this->openSheet($course, 30, 1, null, $uid), '2026-09-07');
        $this->makeLine($m, 'E001', 'absent');
        DB::table('tbl_2024_etudiant')->where('student_id', 'E001')->update(['suspended' => 1]);

        $session = $this->api($user)->getJson('/api/v1/teacher/sessions/'.$m)->assertOk();
        $register = $this->api($user)->getJson("/api/v1/teacher/courses/{$course}/register?year=".rawurlencode($this->year).'&semester=1')->assertOk();

        $this->assertContains('E001', array_column($session->json('data.lines'), 'student_id'), 'son pointage compte encore : on le voit');
        $this->assertSame(['E002', 'E003'], array_column($register->json('data.students'), 'id'));
        $this->assertSame(2, $register->json('data.sessions.0.to_mark'), 'à pointer = les deux inscrits restants, le suspendu ne diminue rien');
    }

    public function test_a_course_removed_from_the_catalogue_is_no_longer_the_teachers(): void
    {
        [$user, $course] = $this->classroom();
        DB::table('t_2023_cours')->where('id', $course)->update(['remove' => 1]);

        $this->api($user)->postJson("/api/v1/teacher/courses/{$course}/sessions", $this->base() + ['date' => now()->format('Y-m-d')])->assertNotFound();
    }

    public function test_an_unlinked_account_is_matched_with_first_and_last_name_swapped(): void
    {
        // Le registrariat range parfois le nom de famille dans `name` et les prénoms dans `lastName` : l'ancien code testait les deux ordres.
        DB::table('teacher')->insert(['uid' => 798001, 'name' => 'Rakoto', 'lastName' => 'Hery Jean', 'sex' => 0, 'remove' => 0]);
        $user = User::factory()->staff(7, 'teacher')->create(['nom' => 'Rakoto', 'prenom' => 'Hery Jean', 'teacher_uid' => null, 'mail' => 'autre@exemple.test']);
        $course = $this->makeCourse(798001);
        $this->enrol($course, ['E001' => ['A', 'a']]);

        $this->assertSame([$course], array_column($this->api($user)->getJson('/api/v1/teacher/courses?year='.rawurlencode($this->year))->json('data.courses'), 'id'));
    }

    public function test_an_unlinked_account_is_matched_by_mail_when_names_differ(): void
    {
        DB::table('teacher')->insert(['uid' => 798002, 'name' => 'Autre', 'lastName' => 'Nom', 'email' => 'Prof.Mail@Exemple.test', 'sex' => 0, 'remove' => 0]);
        $user = User::factory()->staff(7, 'teacher')->create(['nom' => 'Sans', 'prenom' => 'Correspondance', 'teacher_uid' => null, 'mail' => ' prof.mail@exemple.TEST ']);
        $course = $this->makeCourse(798002);
        $this->enrol($course, ['E001' => ['A', 'a']]);

        $this->assertSame([$course], array_column($this->api($user)->getJson('/api/v1/teacher/courses?year='.rawurlencode($this->year))->json('data.courses'), 'id'));
    }

    public function test_a_removed_teacher_record_is_never_matched(): void
    {
        DB::table('teacher')->insert(['uid' => 798003, 'name' => 'Parti', 'lastName' => 'Depuis', 'email' => 'parti@exemple.test', 'sex' => 0, 'remove' => 1]);
        $user = User::factory()->staff(7, 'teacher')->create(['nom' => 'Depuis', 'prenom' => 'Parti', 'teacher_uid' => null, 'mail' => 'parti@exemple.test']);
        $course = $this->makeCourse(798003);
        $this->enrol($course, ['E001' => ['A', 'a']]);

        $this->assertSame([], $this->api($user)->getJson('/api/v1/teacher/courses?year='.rawurlencode($this->year))->json('data.courses'));
    }
}
