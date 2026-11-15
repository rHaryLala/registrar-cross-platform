<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** « Mon assiduité » : cours par cours, seuil de 15 %, séances sans pénalité, isolation entre étudiants. */
class AttendanceEndpointTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;

    private function attendance(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp()->getJson('/api/v1/me/attendance');
    }

    public function test_a_student_with_no_record_gets_an_empty_but_valid_answer(): void
    {
        $u = $this->makeStudent('700');

        $this->attendance($u)->assertOk()->assertJsonPath('data.courses', [])->assertJsonPath('data.alerts', []);
    }

    public function test_courses_show_present_late_and_absent_with_counted_absences(): void
    {
        $u = $this->makeStudent('701');
        $sheet = $this->makeSheet('INF 201', 'Algorithmique II', 20); // 15 % de 20 = 3 absences permises
        $days = ['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11'];
        // 1 présence, 2 absences, 2 retards (7 et 6 min) = 1 absence de plus : 2 + 1 = 3 absences comptées = la limite.
        $status = [['present'], ['absent'], ['absent'], ['retard', 7], ['retard', 6]];
        foreach ($days as $i => $day) {
            $this->makeLine($this->makeMeeting($sheet, $day), '701', $status[$i][0], $status[$i][1] ?? null);
        }

        $res = $this->attendance($u)->assertOk();
        $c = $res->json('data.courses.0');
        $s = $c['summary'];

        $this->assertSame(['INF 201', 'Algorithmique II', 'Semestre 1'], [$c['course_code'], $c['course_title'], $c['semester_label']]);
        $this->assertEquals(['present' => 1, 'late' => 2, 'absent' => 2, 'recorded' => 5], array_intersect_key($s, array_flip(['present', 'late', 'absent', 'recorded'])));
        $this->assertSame([1, 3, 3, 'limit'], [$s['converted_lates'], $s['counted'], $s['allowed'], $s['state']]);
        $this->assertSame(['limit'], array_column($res->json('data.alerts'), 'state'));
    }

    public function test_just_under_the_limit_there_is_no_alert(): void
    {
        $u = $this->makeStudent('707');
        $sheet = $this->makeSheet('INF 202', 'Programmation', 20);
        foreach ([1, 2] as $d) {
            $this->makeLine($this->makeMeeting($sheet, '2026-09-0'.$d), '707', 'absent');
        }

        $res = $this->attendance($u)->assertOk();

        $this->assertSame('ok', $res->json('data.courses.0.summary.state'));
        $this->assertSame([], $res->json('data.alerts'));
    }

    public function test_exceeding_fifteen_percent_raises_an_alert(): void
    {
        $u = $this->makeStudent('702');
        $sheet = $this->makeSheet('MAT 110', 'Mathématiques', 20); // 3 permises
        foreach (range(1, 4) as $d) {
            $this->makeLine($this->makeMeeting($sheet, '2026-10-0'.$d), '702', 'absent');
        }

        $d = $this->attendance($u)->json('data');

        $this->assertSame('exceeded', $d['courses'][0]['summary']['state']);
        $this->assertSame(100, $d['courses'][0]['summary']['gauge']);
        $this->assertSame([['sheet_id' => $sheet, 'course_code' => 'MAT 110', 'state' => 'exceeded', 'late_pending' => false]], $d['alerts']);
    }

    public function test_an_unknown_planned_count_means_no_threshold_yet(): void
    {
        $u = $this->makeStudent('703');
        $sheet = $this->makeSheet('ANG 101', 'Anglais', null);
        $this->makeLine($this->makeMeeting($sheet, '2026-10-01'), '703', 'absent');

        $s = $this->attendance($u)->json('data.courses.0.summary');

        $this->assertSame(['unknown', null, null, 1], [$s['state'], $s['allowed'], $s['planned'], $s['absent']]);
        $this->assertSame([], $this->attendance($u)->json('data.alerts'), 'pas de seuil, donc pas d\'alerte');
    }

    public function test_a_late_teacher_without_warning_cancels_the_penalty(): void
    {
        $u = $this->makeStudent('704');
        $sheet = $this->makeSheet('INF 230', 'Réseaux', 20);
        // L'enseignant arrive 25 min en retard sans prévenir : l'absence ne compte pas (§474).
        $this->makeLine($this->makeMeeting($sheet, '2026-10-01', '08:00:00', '08:25:00', false), '704', 'absent');
        // Même retard, mais il avait prévenu : l'absence compte.
        $this->makeLine($this->makeMeeting($sheet, '2026-10-02', '08:00:00', '08:25:00', true), '704', 'absent');

        $d = $this->attendance($u)->json('data.courses.0');
        $byDate = collect($d['sessions'])->keyBy('date');

        $this->assertSame([1, 1], [$d['summary']['absent'], $d['summary']['no_penalty']]);
        $this->assertTrue($byDate['2026-10-01']['no_penalty']);
        $this->assertFalse($byDate['2026-10-02']['no_penalty']);
    }

    public function test_sessions_are_listed_newest_first_with_labels_and_justification(): void
    {
        $u = $this->makeStudent('705');
        $sheet = $this->makeSheet('BIO 101', 'Biologie', 20);
        $this->makeLine($this->makeMeeting($sheet, '2026-09-01', '08:00:00'), '705', 'present');
        $this->makeLine($this->makeMeeting($sheet, '2026-09-03', '14:30:00'), '705', 'retard', 12, 'medical');
        $this->makeLine($this->makeMeeting($sheet, '2026-09-02'), '705', null); // non pointé

        $sessions = $this->attendance($u)->json('data.courses.0.sessions');

        $this->assertSame(['2026-09-03', '2026-09-02', '2026-09-01'], array_column($sessions, 'date'));
        $this->assertSame(['Retard 12 min (compte absence)', 'Non pointé', 'Présent'], array_column($sessions, 'label'));
        $this->assertSame(['absence', null, 'present'], array_column($sessions, 'category'));
        $this->assertSame(['14:30', '08:00', '08:00'], [$sessions[0]['time'], $sessions[1]['time'], $sessions[2]['time']]);
        $this->assertSame('medical', $sessions[0]['justification']);
    }

    public function test_courses_are_sorted_year_descending_then_semester_then_code(): void
    {
        $u = $this->makeStudent('706');
        foreach ([['B 1', '2025 - 2026', 1], ['A 1', '2026 - 2027', 2], ['C 1', '2026 - 2027', 1], ['A 2', '2026 - 2027', 1]] as [$code, $year, $sem]) {
            $this->makeLine($this->makeMeeting($this->makeSheet($code, $code, 10, $year, $sem), '2026-10-01'), '706', 'present');
        }

        $this->assertSame(['A 2', 'C 1', 'A 1', 'B 1'], array_column($this->attendance($u)->json('data.courses'), 'course_code'));
    }

    public function test_a_student_only_sees_the_registers_where_they_appear(): void
    {
        $a = $this->makeStudent('800');
        $this->makeStudent('801');
        $mine = $this->makeSheet('MINE 1', 'Mon cours', 20);
        $other = $this->makeSheet('OTHER 1', 'Cours d\'un autre', 20);
        $this->makeLine($this->makeMeeting($mine, '2026-10-01'), '800', 'present');
        $this->makeLine($this->makeMeeting($other, '2026-10-01'), '801', 'absent');

        $res = $this->attendance($a)->assertOk();

        $this->assertSame(['MINE 1'], array_column($res->json('data.courses'), 'course_code'));
        $this->assertStringNotContainsString('OTHER', $res->getContent());
    }

    public function test_other_students_lines_in_the_same_meeting_never_leak_into_totals(): void
    {
        $a = $this->makeStudent('802');
        $this->makeStudent('803');
        $sheet = $this->makeSheet('SHARED 1', 'Cours partagé', 20);
        $meeting = $this->makeMeeting($sheet, '2026-10-01');
        $this->makeLine($meeting, '802', 'present');
        $this->makeLine($meeting, '803', 'absent');
        $this->makeLine($this->makeMeeting($sheet, '2026-10-02'), '803', 'absent');

        $s = $this->attendance($a)->json('data.courses.0');

        $this->assertSame([1, 0, 1], [$s['summary']['present'], $s['summary']['absent'], count($s['sessions'])]);
    }

    public function test_only_students_may_read_attendance(): void
    {
        $this->fromApp()->getJson('/api/v1/me/attendance')->assertStatus(401);
    }

    public function test_staff_cannot_read_student_attendance(): void
    {
        $this->attendance(User::factory()->staff(3, 'registrar')->create())->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');
    }
}
