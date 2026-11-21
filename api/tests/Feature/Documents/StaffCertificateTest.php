<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Certificate;
use App\Domain\Documents\StaffCertificates;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Le certificat de scolarité pour le personnel : droits (dont le chef de mention, pour sa mention), contenu, dates, PDF, logo. */
class StaffCertificateTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00', 'Indian/Antananarivo'));
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

    private function student(string $id = '9000', array $file = []): int
    {
        $this->makeStudent($id, 'Rakoto', 'Fanja');
        $this->setStudentFile($id, array_merge([
            'annee_etude' => '2', 'etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux', 'annee_scolaire' => '2026-2027',
            'dateNaissance' => '2001-03-05', 'lieuNaissance' => 'Antsirabe', 'father_name' => 'RAKOTO Jean', 'mother_name' => 'RASOA Marie', 'student_adresse' => 'Lot 12, Antsirabe',
        ], $file));

        return (int) DB::table('tbl_2024_etudiant')->where('student_id', $id)->value('id');
    }

    private function pdfAs(User $u, int $id, string $query = '')
    {
        return $this->actingAs($u, 'web')->fromApp()->get("/api/v1/staff/students/{$id}/certificate/pdf{$query}", ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------------------------------------ droits

    public function test_levels_one_to_three_print_the_others_get_403_before_any_lookup(): void
    {
        $id = $this->student();

        foreach ([$this->staff(1, 'superadmin'), $this->staff(2, 'admin'), $this->staff(3, 'registrar')] as $u) {
            $this->pdfAs($u, $id)->assertOk();
        }
        foreach ([$this->staff(0, 'registrar'), $this->staff(4, 'comptabilite'), $this->staff(5, 'media'), $this->staff(7, 'teacher'), $this->staff(9, 'work_education'),
            User::factory()->staff(3, 'registrar')->inactive()->create(), $this->makeStudent('9001')] as $u) {
            $this->pdfAs($u, $id)->assertStatus(403)->assertJsonPath('code', 'forbidden.documents');
            $this->pdfAs($u, 999999)->assertStatus(403);
        }
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $id = $this->student();

        $this->fromApp()->get("/api/v1/staff/students/{$id}/certificate/pdf", ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_a_head_of_programme_prints_only_for_their_own_mention(): void
    {
        $id = $this->student();                       // mention Informatique
        $gestion = $this->student('9002', ['etude_envisage' => 'Gestion']);

        $chef = $this->staff(6, 'chef_mention', ['perimetre_mention' => 'Informatique']);
        $this->pdfAs($chef, $id)->assertOk();
        $this->pdfAs($chef, $gestion)->assertStatus(404)->assertJsonPath('code', 'document.not_found');

        $without = $this->staff(6, 'chef_mention');   // aucune mention attribuée : rien
        $this->pdfAs($without, $id)->assertStatus(404);

        // Le registrariat n'est pas limité par la mention.
        $this->pdfAs($this->staff(3, 'registrar'), $gestion)->assertOk();
    }

    public function test_a_cycle_limited_account_does_not_reach_another_cycle_nor_removed_students(): void
    {
        $id = $this->student();                       // licence 2

        $this->pdfAs($this->staff(3, 'registrar', ['perimetre_cycle' => 'M']), $id)->assertStatus(404);
        $this->pdfAs($this->staff(3, 'registrar', ['perimetre_cycle' => 'L']), $id)->assertOk();
        $this->pdfAs($this->staff(3, 'registrar'), 999999)->assertStatus(404);
        DB::table('tbl_2024_etudiant')->where('id', $id)->update(['remove' => 1]);
        $this->pdfAs($this->staff(3, 'registrar'), $id)->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------------------ contenu

    public function test_the_certificate_carries_the_students_data_and_the_signers_name_and_title(): void
    {
        $id = $this->student('9000', ['student_nom' => 'Rasolo', 'student_prenom' => 'Éléonore Mia']);
        $signer = $this->staff(3, 'registrar', ['nom' => 'Rabe', 'prenom' => 'Sahondra', 'post' => 'Registraire']);
        $row = DB::table('tbl_2024_etudiant')->where('id', $id)->first();

        $c = app(Certificate::class)->build($row, $signer);

        $this->assertSame('RASOLO Éléonore Mia', $c['name']);
        $this->assertSame(['9000', '05 mars 2001', 'Antsirabe', 'RAKOTO Jean', 'RASOA Marie', 'Lot 12, Antsirabe', 'Informatique', 'Réseaux', '2026-2027'],
            [$c['student_id'], $c['birth_date'], $c['birth_place'], $c['father'], $c['mother'], $c['address'], $c['mention'], $c['track'], $c['year']]);
        $this->assertSame('21 novembre 2026', $c['issued_on']);
        $this->assertSame(['post' => 'Registraire', 'name' => 'RABE Sahondra'], $c['signer']);

        $html = view('pdf.certificate', ['c' => $c, 'logo' => null])->render();
        foreach (['CERTIFICAT DE SCOLARITÉ', 'RASOLO Éléonore Mia', '05 mars 2001', 'Antsirabe', 'RAKOTO Jean', 'RASOA Marie', 'Lot 12, Antsirabe', 'Informatique', 'Réseaux', '2026-2027', '9000',
            'Sambaina, le 21 novembre 2026', 'Registraire', 'RABE Sahondra', 'valoir et servir ce que de droit'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_missing_data_is_a_dash_and_an_empty_title_is_not_invented(): void
    {
        $id = $this->student('9000', ['dateNaissance' => null, 'lieuNaissance' => '', 'father_name' => null, 'mother_name' => '  ']);
        $signer = $this->staff(3, 'registrar', ['nom' => 'Rabe', 'prenom' => 'Sahondra', 'post' => '']);
        $row = DB::table('tbl_2024_etudiant')->where('id', $id)->first();

        $c = app(Certificate::class)->build($row, $signer);
        $html = view('pdf.certificate', ['c' => $c, 'logo' => null])->render();

        $this->assertNull($c['birth_date']);
        $this->assertNull($c['birth_place']);
        $this->assertNull($c['father']);
        $this->assertNull($c['mother']);
        $this->assertSame('', $c['signer']['post']);
        $this->assertStringNotContainsString('Secrétaire', $html);
        $this->assertStringNotContainsString('Utilisateur', $html);
        $this->assertSame(4, substr_count($html, '<td class="v">—</td>'));    // naissance, lieu, père, mère
    }

    public function test_dates_are_written_in_french_and_impossible_ones_are_refused(): void
    {
        $this->assertSame('05 mars 2001', Certificate::dateLabel('2001-03-05'));
        $this->assertSame('31 décembre 1999', Certificate::dateLabel('1999-12-31 00:00:00'));
        $this->assertSame('01 janvier 1900', Certificate::dateLabel('1900-01-01'));
        $this->assertSame('29 février 2000', Certificate::dateLabel('2000-02-29'));
        foreach ([null, '', 'abc', '0000-00-00', '0002-09-13', '1899-12-31', '2001-02-30', '2001-13-01', 20010305, ['2001-03-05']] as $bad) {
            $this->assertNull(Certificate::dateLabel($bad), json_encode($bad));
        }
        foreach (['2001-01-15' => 'janvier', '2001-02-15' => 'février', '2001-03-15' => 'mars', '2001-04-15' => 'avril', '2001-05-15' => 'mai', '2001-06-15' => 'juin',
            '2001-07-15' => 'juillet', '2001-08-15' => 'août', '2001-09-15' => 'septembre', '2001-10-15' => 'octobre', '2001-11-15' => 'novembre', '2001-12-15' => 'décembre'] as $iso => $month) {
            $this->assertSame("15 {$month} 2001", Certificate::dateLabel($iso));
        }
    }

    public function test_everything_comes_from_the_database_never_from_the_request(): void
    {
        $id = $this->student();
        $res = $this->pdfAs($this->staff(3, 'registrar'), $id, '?etude_option=Piratage&student_nom=Faux&annee_scolaire=1900')->assertOk();

        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $row = DB::table('tbl_2024_etudiant')->where('id', $id)->first();
        $c = app(Certificate::class)->build($row, $this->staff(3, 'registrar'));
        $this->assertSame('Réseaux', $c['track']);
        $this->assertSame('2026-2027', $c['year']);
    }

    // ------------------------------------------------------------------------------------------------ PDF

    public function test_the_pdf_is_a_real_never_cached_attachment(): void
    {
        $id = $this->student();

        $res = $this->pdfAs($this->staff(3, 'registrar'), $id)->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('attachment; filename="9000-certificat-scolarite.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertSame('AB-12-certificat-scolarite.pdf', StaffCertificates::filename('A/B ..-12'));
    }

    public function test_the_logo_comes_from_the_legacy_files_folder_and_its_absence_is_not_an_error(): void
    {
        $id = $this->student();
        $reg = $this->staff(3, 'registrar');
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-file-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $img = imagecreatetruecolor(200, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
        imagejpeg($img, $dir.DIRECTORY_SEPARATOR.'UAZ Official Black Logo.jpg', 80);

        try {
            config(['registrar.legacy_files_path' => $dir]);
            $this->assertStringContainsString('/Subtype /Image', $this->pdfAs($reg, $id)->assertOk()->getContent());

            config(['registrar.legacy_files_path' => $dir.DIRECTORY_SEPARATOR.'absent']);
            $this->assertStringNotContainsString('/Subtype /Image', $this->pdfAs($reg, $id)->assertOk()->getContent());

            file_put_contents($dir.DIRECTORY_SEPARATOR.'UAZ Official Black Logo.jpg', 'pas une image');
            config(['registrar.legacy_files_path' => $dir]);
            $this->assertStringNotContainsString('/Subtype /Image', $this->pdfAs($reg, $id)->assertOk()->getContent());
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_the_pdf_route_is_throttled(): void
    {
        $id = $this->student();
        $reg = $this->staff(3, 'registrar');
        for ($i = 0; $i < 30; $i++) {
            $this->pdfAs($reg, $id)->assertOk();
        }
        $this->pdfAs($reg, $id)->assertStatus(429);
    }
}
