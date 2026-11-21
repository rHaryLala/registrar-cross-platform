<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\StaffEnrollmentSheets;
use App\Domain\Student\Enrollments;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** La fiche d'inscription demandée par le personnel : droits, périmètre, toutes les années, même document que l'étudiant. */
class StaffEnrollmentSheetsTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Indian/Antananarivo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(int $level, string $type, array $attrs = []): User
    {
        return User::factory()->staff($level, $type)->create($attrs);
    }

    private function rowId(string $student): int
    {
        return (int) DB::table('tbl_2024_etudiant')->where('student_id', $student)->value('id');
    }

    private function listAs(User $u, int $id)
    {
        return $this->actingAs($u, 'web')->fromApp()->getJson("/api/v1/staff/students/{$id}/enrollment-sheets");
    }

    private function pdfAs(User $u, int $id, int $session)
    {
        return $this->actingAs($u, 'web')->fromApp()->get("/api/v1/staff/students/{$id}/enrollment-sheets/{$session}/pdf", ['Accept' => 'application/json']);
    }

    /** Un étudiant licence 2 inscrit à une session récente et à une ancienne (que l'étudiant ne voit pas). @return array{0: int, 1: int, 2: int} */
    private function scene(string $matricule = '7000'): array
    {
        $this->makeStudent($matricule, 'Rakoto', 'Fanja');
        $this->setStudentFile($matricule, ['annee_etude' => '2', 'etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux']);
        $old = $this->makeSession('Session 1', '2024 - 2025', 1);
        $new = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll($matricule, $old, 2);
        $this->enroll($matricule, $new, 2);
        $this->billedCourse($matricule, $new, null, 'INF 101', 3, 100000);
        $this->finance($matricule, $new, ['cout_fraix_generaux' => 50000, 'mode_payement' => 'A']);

        return [$this->rowId($matricule), $old, $new];
    }

    // ------------------------------------------------------------------------------------------------ droits

    public function test_levels_one_to_four_can_list_and_print(): void
    {
        [$id, , $new] = $this->scene();
        $users = [
            $this->staff(1, 'superadmin'), $this->staff(2, 'admin'), $this->staff(3, 'registrar'), $this->staff(4, 'comptabilite'),
        ];
        foreach ($users as $u) {
            $this->listAs($u, $id)->assertOk();
            $this->pdfAs($u, $id, $new)->assertOk();
        }
    }

    public function test_other_roles_inactive_accounts_and_students_are_refused_with_403(): void
    {
        [$id, , $new] = $this->scene();
        $refused = [
            $this->staff(0, 'registrar'), $this->staff(5, 'media'), $this->staff(6, 'chef_mention'), $this->staff(7, 'teacher'), $this->staff(9, 'work_education'),
            User::factory()->staff(3, 'registrar')->inactive()->create(), $this->makeStudent('7001'),
        ];
        foreach ($refused as $u) {
            $this->listAs($u, $id)->assertStatus(403)->assertJsonPath('code', 'forbidden.documents');
            $this->pdfAs($u, $id, $new)->assertStatus(403);
        }
    }

    public function test_the_right_is_checked_before_the_student_is_looked_up(): void
    {
        // Un compte sans droit ne peut pas distinguer un étudiant qui existe d'un étudiant qui n'existe pas.
        $media = $this->staff(5, 'media');
        $this->listAs($media, 999999)->assertStatus(403);
        $this->pdfAs($media, 999999, 1)->assertStatus(403);
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        [$id, , $new] = $this->scene();
        $this->fromApp()->getJson("/api/v1/staff/students/{$id}/enrollment-sheets")->assertStatus(401);
        $this->fromApp()->get("/api/v1/staff/students/{$id}/enrollment-sheets/{$new}/pdf", ['Accept' => 'application/json'])->assertStatus(401);
    }

    // ------------------------------------------------------------------------------------------------ périmètre

    public function test_a_cycle_limited_account_does_not_reach_a_student_of_another_cycle(): void
    {
        [$id, , $new] = $this->scene();               // licence 2
        $master = $this->staff(3, 'registrar', ['perimetre_cycle' => 'M']);
        $licence = $this->staff(3, 'registrar', ['perimetre_cycle' => 'L']);

        $this->listAs($master, $id)->assertStatus(404)->assertJsonPath('code', 'document.not_found');
        $this->pdfAs($master, $id, $new)->assertStatus(404);
        $this->listAs($licence, $id)->assertOk();
        $this->pdfAs($licence, $id, $new)->assertOk();
    }

    public function test_a_student_without_a_level_is_hidden_from_a_cycle_limited_account(): void
    {
        $this->makeStudent('7002');
        $id = $this->rowId('7002');
        $this->listAs($this->staff(3, 'registrar', ['perimetre_cycle' => 'L']), $id)->assertStatus(404);
        $this->listAs($this->staff(3, 'registrar'), $id)->assertOk();
    }

    public function test_unknown_and_removed_students_are_not_found(): void
    {
        [$id] = $this->scene();
        $reg = $this->staff(3, 'registrar');
        $this->listAs($reg, 999999)->assertStatus(404);
        DB::table('tbl_2024_etudiant')->where('id', $id)->update(['remove' => 1]);
        $this->listAs($reg, $id)->assertStatus(404);
        $this->pdfAs($reg, $id, 1)->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------------------ contenu

    public function test_the_list_shows_every_enrolment_including_years_the_student_cannot_see(): void
    {
        [$id, $old, $new] = $this->scene();
        $student = User::factory()->create(['student_id' => '7000']);

        $data = $this->listAs($this->staff(3, 'registrar'), $id)->assertOk()->json('data');

        $this->assertSame('7000', $data['student_id']);
        $this->assertSame('RAKOTO Fanja', $data['name']);
        $this->assertSame([$new, $old], array_column($data['sessions'], 'session_id'));   // la plus récente d'abord
        $this->assertSame(['2026 - 2027', '2024 - 2025'], array_column($data['sessions'], 'year'));
        $this->assertSame([true, false], array_column($data['sessions'], 'has_fees'));
        $this->assertSame([1, 0], array_column($data['sessions'], 'courses'));
        $this->assertSame('Licence 2', $data['sessions'][0]['level_label']);
        // L'étudiant, lui, ne voit pas la session de 2024-2025 (Licence : fiches à partir de 2026-2027).
        $mine = $this->actingAs($student, 'web')->fromApp()->getJson('/api/v1/me/enrollments')->json('data.years');
        $this->assertSame(['2026 - 2027'], array_column($mine, 'year'));
    }

    public function test_the_list_skips_removed_enrolments_and_keeps_one_line_per_session(): void
    {
        [$id, , $new] = $this->scene();
        $this->enroll('7000', $new, 3, 'Récente mention');                                                // doublon : la plus récente gagne
        $this->enroll('7000', $this->makeSession('Session 2', '2026 - 2027', 2), 2, 'X', ['remove' => 1]); // retirée

        $sessions = $this->listAs($this->staff(3, 'registrar'), $id)->json('data.sessions');

        $this->assertCount(2, $sessions);
        $this->assertSame(['Licence 3', 'Récente mention'], [$sessions[0]['level_label'], $sessions[0]['mention']]);
    }

    public function test_the_staff_sheet_is_the_students_sheet_when_both_can_see_it(): void
    {
        [, , $new] = $this->scene();
        $svc = app(Enrollments::class);

        $this->assertSame($svc->sheet('7000', $new), $svc->sheetForStaff('7000', $new));
    }

    public function test_the_staff_sheet_exists_for_a_year_the_student_does_not_see_and_when_the_rubric_is_hidden(): void
    {
        [, $old, $new] = $this->scene();
        $svc = app(Enrollments::class);
        $this->assertNull($svc->sheet('7000', $old));                  // masquée à l'étudiant (année trop ancienne)
        $this->assertNotNull($svc->sheetForStaff('7000', $old));

        $this->setRubric('fiches_inscription', false);
        $this->assertNull($svc->sheet('7000', $new));                  // rubrique masquée dans l'espace étudiant
        $this->assertNotNull($svc->sheetForStaff('7000', $new));
        $this->pdfAs($this->staff(3, 'registrar'), $this->rowId('7000'), $new)->assertOk();
    }

    public function test_the_staff_sheet_still_requires_a_real_enrolment_of_this_student(): void
    {
        [$id, , $new] = $this->scene();
        $this->makeStudent('7003');
        $this->setStudentFile('7003', ['annee_etude' => '2']);
        $other = $this->makeSession('Session X', '2026 - 2027', 2);
        $this->enroll('7003', $other, 2);
        $reg = $this->staff(3, 'registrar');

        $this->pdfAs($reg, $id, $other)->assertStatus(404)->assertJsonPath('code', 'document.not_found');   // la session d'un AUTRE étudiant
        $this->pdfAs($reg, $id, 999999)->assertStatus(404);
        $this->pdfAs($reg, $this->rowId('7003'), $new)->assertStatus(404);
        $this->assertNull(app(Enrollments::class)->sheetForStaff('7000', $other));
        $this->assertNull(app(Enrollments::class)->sheetForStaff('inconnu', $new));
    }

    // ------------------------------------------------------------------------------------------------ PDF

    public function test_the_pdf_is_a_real_never_cached_attachment_named_like_the_students(): void
    {
        [$id, , $new] = $this->scene();

        $res = $this->pdfAs($this->staff(4, 'comptabilite'), $id, $new)->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('attachment; filename="7000-fiche-inscription-Session-1-2026-2027.pdf"', $res->headers->get('Content-Disposition'));
        // Même fabrique que celle de l'espace étudiant : exactement le document de la fiche.
        $mine = $this->actingAs(User::factory()->create(['student_id' => '7000']), 'web')->fromApp()->get("/api/v1/me/enrollments/{$new}/pdf", ['Accept' => 'application/json']);
        $this->assertSame($mine->headers->get('Content-Disposition'), $res->headers->get('Content-Disposition'));
        $this->assertSame(substr_count($mine->getContent(), '/Type /Page'), substr_count($res->getContent(), '/Type /Page'));
    }

    public function test_the_students_photo_is_embedded_in_the_pdf(): void
    {
        [$id, , $new] = $this->scene();
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'photos-staff-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        config(['registrar.student_photos_path' => $dir]);
        $img = imagecreatetruecolor(300, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 160));
        imagejpeg($img, $dir.DIRECTORY_SEPARATOR.'7000.jpg', 80);
        $this->setStudentFile('7000', ['image_student' => '7000.jpg']);

        try {
            $withPhoto = $this->pdfAs($this->staff(3, 'registrar'), $id, $new)->assertOk()->getContent();
            $this->assertStringContainsString('/Subtype /Image', $withPhoto);
            $this->setStudentFile('7000', ['image_student' => '']);
            $without = $this->pdfAs($this->staff(3, 'registrar'), $id, $new)->assertOk()->getContent();
            $this->assertStringNotContainsString('/Subtype /Image', $without);
        } finally {
            @unlink($dir.DIRECTORY_SEPARATOR.'7000.jpg');
            @rmdir($dir);
        }
    }

    public function test_a_session_without_fees_is_still_printed_and_says_so(): void
    {
        [$id, $old] = $this->scene();

        $this->pdfAs($this->staff(3, 'registrar'), $id, $old)->assertOk();
        $this->assertNull(app(Enrollments::class)->sheetForStaff('7000', $old)['fees']);
    }

    public function test_the_domain_checks_the_right_itself(): void
    {
        [$id, , $new] = $this->scene();
        $svc = app(StaffEnrollmentSheets::class);

        $this->assertTrue(StaffEnrollmentSheets::allows($this->staff(4, 'comptabilite')));
        $this->assertFalse(StaffEnrollmentSheets::allows($this->staff(5, 'media')));
        $file = $svc->pdf($this->staff(2, 'admin'), $id, $new);
        $this->assertStringStartsWith('%PDF-', $file['content']);
        $this->assertStringEndsWith('.pdf', $file['filename']);
    }

    public function test_the_pdf_route_is_throttled(): void
    {
        [$id, , $new] = $this->scene();
        $reg = $this->staff(3, 'registrar');
        for ($i = 0; $i < 30; $i++) {
            $this->pdfAs($reg, $id, $new)->assertOk();
        }
        $this->pdfAs($reg, $id, $new)->assertStatus(429);
    }
}
