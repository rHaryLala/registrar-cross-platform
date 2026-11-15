<?php

namespace Tests\Feature\Enrollments;

use App\Domain\Student\StudentPhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** La photo de la fiche : chemins validés et confinés, image réduite, jamais celle d'un autre. */
class StudentPhotoTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'photos-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.DIRECTORY_SEPARATOR.'sous', 0777, true);
        config(['registrar.student_photos_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{*,*/*}', GLOB_BRACE) ?: [] as $f) {
            is_file($f) ? @unlink($f) : null;
        }
        @rmdir($this->dir.DIRECTORY_SEPARATOR.'sous');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function jpeg(string $relative, int $w = 800, int $h = 1000): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 160));
        $path = $this->dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        imagejpeg($img, $path, 80);

        return $path;
    }

    private function photos(): StudentPhoto
    {
        return new StudentPhoto;
    }

    // ------------------------------------------------------------------------------------------- validation

    public function test_a_plain_file_name_and_a_sub_folder_path_resolve_inside_the_folder(): void
    {
        $this->jpeg('a.jpg');
        $this->jpeg('sous/b (1).JPG');
        $this->jpeg('é-ç.jpeg');

        $this->assertNotNull($this->photos()->resolve('a.jpg'));
        $this->assertNotNull($this->photos()->resolve('sous/b (1).JPG'));
        $this->assertNotNull($this->photos()->resolve('é-ç.jpeg'));
        $this->assertNotNull($this->photos()->resolve('  a.jpg  '));
    }

    /** @return array<string, array{0: string}> */
    public static function hostile(): array
    {
        return [
            'remontée' => ['../secret.jpg'], 'remontée profonde' => ['sous/../../secret.jpg'], 'remontée au milieu' => ['sous/../a.jpg'],
            'chemin absolu' => ['/etc/passwd.jpg'], 'lecteur windows' => ['C:/Windows/x.jpg'], 'lecteur sans barre' => ['C:x.jpg'],
            'antislash' => ['sous\\b.jpg'], 'antislash de remontée' => ['..\\secret.jpg'], 'octet nul' => ["a.jpg\0.png"],
            'saut de ligne' => ["a\n.jpg"], 'segment vide' => ['sous//b.jpg'], 'point seul' => ['./a.jpg'], 'vide' => [''], 'espaces' => ['   '],
            'sans extension' => ['a'], 'extension interdite' => ['a.php'], 'double extension' => ['a.jpg.php'], 'extension en milieu' => ['a.jpg.txt'],
            'pdf' => ['a.pdf'], 'url' => ['http://example.test/a.jpg'], 'flux php' => ['php://filter/resource=a.jpg'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostile')]
    public function test_hostile_values_never_resolve(string $value): void
    {
        $this->jpeg('a.jpg');
        $this->jpeg('sous/b.jpg');
        file_put_contents(dirname($this->dir).DIRECTORY_SEPARATOR.'secret.jpg', 'x');

        $this->assertNull($this->photos()->resolve($value), json_encode($value));

        @unlink(dirname($this->dir).DIRECTORY_SEPARATOR.'secret.jpg');
    }

    public function test_a_symlink_pointing_outside_the_folder_is_refused(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'outside-'.bin2hex(random_bytes(3)).'.jpg';
        imagejpeg(imagecreatetruecolor(50, 50), $outside);
        if (! @symlink($outside, $this->dir.DIRECTORY_SEPARATOR.'lien.jpg')) {
            @unlink($outside);
            $this->markTestSkipped('Liens symboliques non disponibles ici.');
        }

        $this->assertNull($this->photos()->resolve('lien.jpg'));

        @unlink($this->dir.DIRECTORY_SEPARATOR.'lien.jpg');
        @unlink($outside);
    }

    public function test_the_real_type_is_read_from_the_file_not_from_its_extension(): void
    {
        file_put_contents($this->dir.'/faux.jpg', '<?php echo "pas une image"; ?>');
        file_put_contents($this->dir.'/vide.jpg', '');
        // Un PDF renommé en .jpg
        file_put_contents($this->dir.'/doc.jpg', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");

        foreach (['faux.jpg', 'vide.jpg', 'doc.jpg'] as $f) {
            $this->assertNull($this->photos()->resolve($f), $f);
        }
    }

    public function test_an_empty_configured_folder_never_falls_back_to_the_current_directory(): void
    {
        // realpath('') vaudrait le dossier courant de l'API : une image du dépôt serait alors « une photo d'étudiant ».
        file_put_contents(base_path('storage/app/intrus.jpg'), '');
        imagejpeg(imagecreatetruecolor(50, 50), base_path('storage/app/intrus.jpg'));
        foreach (['', '   '] as $empty) {
            config(['registrar.student_photos_path' => $empty]);
            $this->assertNull($this->photos()->resolve('storage/app/intrus.jpg'), json_encode($empty));
        }
        @unlink(base_path('storage/app/intrus.jpg'));
    }

    public function test_a_missing_file_or_folder_gives_null_not_an_error(): void
    {
        $this->assertNull($this->photos()->resolve('absent.jpg'));
        config(['registrar.student_photos_path' => $this->dir.'/n-existe-pas']);
        $this->jpeg('a.jpg');
        $this->assertNull($this->photos()->resolve('a.jpg'));
    }

    public function test_a_giant_image_is_refused_before_it_can_exhaust_memory(): void
    {
        $this->jpeg('geant.jpg', 4000, 3500);   // 14 millions de pixels

        $this->assertNull($this->photos()->resolve('geant.jpg'));
    }

    // ---------------------------------------------------------------------------------------------- réduction

    public function test_the_shrunk_photo_is_a_jpeg_that_fits_the_frame_and_keeps_its_proportions(): void
    {
        $this->jpeg('p.jpg', 1600, 2000);   // portrait 4:5
        $this->jpeg('l.jpg', 2000, 1000);   // paysage 2:1
        $this->makeStudent('3001');
        $this->makeStudent('3002', 'Autre', 'Etudiant');
        $this->setStudentFile('3001', ['image_student' => 'p.jpg']);
        $this->setStudentFile('3002', ['image_student' => 'l.jpg']);

        [$pw, $ph, $type] = getimagesizefromstring($this->photos()->jpeg('3001'));
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertLessThanOrEqual(420, $pw);
        $this->assertLessThanOrEqual(540, $ph);
        $this->assertEqualsWithDelta(0.8, $pw / $ph, 0.01);

        [$lw, $lh] = getimagesizefromstring($this->photos()->jpeg('3002'));
        $this->assertSame(420, $lw);
        $this->assertEqualsWithDelta(2.0, $lw / $lh, 0.02);
    }

    public function test_a_small_photo_is_never_enlarged(): void
    {
        $this->jpeg('petite.jpg', 100, 130);
        $this->makeStudent('3003');
        $this->setStudentFile('3003', ['image_student' => 'petite.jpg']);

        [$w, $h] = getimagesizefromstring($this->photos()->jpeg('3003'));

        $this->assertSame([100, 130], [$w, $h]);
    }

    public function test_a_transparent_png_gets_a_white_background(): void
    {
        $img = imagecreatetruecolor(60, 60);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagepng($img, $this->dir.'/t.png');
        $this->makeStudent('3004');
        $this->setStudentFile('3004', ['image_student' => 't.png']);

        $out = imagecreatefromstring($this->photos()->jpeg('3004'));
        $rgb = imagecolorat($out, 30, 30);

        $this->assertGreaterThan(240, ($rgb >> 16) & 255);
        $this->assertGreaterThan(240, ($rgb >> 8) & 255);
    }

    public function test_the_most_recent_usable_photo_wins_and_an_unusable_one_is_skipped(): void
    {
        $this->jpeg('ancienne.jpg', 200, 200);
        $this->jpeg('recente.jpg', 300, 300);
        $this->makeStudent('3005');   // ligne 2025-2026
        DB::table('tbl_2024_etudiant')->where('student_id', '3005')->update(['image_student' => 'recente.jpg']);
        DB::table('tbl_2024_etudiant')->insert(['student_id' => '3005', 'student_nom' => 'Rakoto', 'student_prenom' => 'Fanja', 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2024-2025', 'period' => 'S1', 'remove' => 0, 'image_student' => 'ancienne.jpg']);
        DB::table('tbl_2024_etudiant')->insert(['student_id' => '3005', 'student_nom' => 'Rakoto', 'student_prenom' => 'Fanja', 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2026-2027', 'period' => 'S1', 'remove' => 0, 'image_student' => '../evasion.jpg']);

        // La ligne la plus récente porte une valeur hostile : elle est ignorée, la suivante est utilisée.
        $this->assertStringEndsWith('recente.jpg', $this->photos()->path('3005'));
    }

    public function test_a_removed_file_row_does_not_provide_a_photo(): void
    {
        $this->jpeg('x.jpg');
        $this->makeStudent('3006');
        $this->setStudentFile('3006', ['image_student' => 'x.jpg', 'remove' => 1]);

        $this->assertNull($this->photos()->path('3006'));
    }

    // --------------------------------------------------------------------------------------------- endpoint

    public function test_my_photo_endpoint_serves_my_own_photo_privately(): void
    {
        $this->jpeg('moi.jpg', 800, 1000);
        $me = $this->makeStudent('3010');
        $this->setStudentFile('3010', ['image_student' => 'moi.jpg']);

        $res = $this->actingAs($me, 'web')->fromApp()->get('/api/v1/me/photo');

        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($res->getContent())[2]);
    }

    public function test_the_photo_endpoint_never_serves_another_students_photo(): void
    {
        $this->jpeg('autre.jpg', 800, 1000);
        $a = $this->makeStudent('3011');
        $b = $this->makeStudent('3012', 'Autre', 'Etudiant');
        $this->setStudentFile('3012', ['image_student' => 'autre.jpg']);

        $this->actingAs($a, 'web')->fromApp()->getJson('/api/v1/me/photo')->assertStatus(404)->assertJsonPath('code', 'photo.not_found');
        // Aucun paramètre ne permet de désigner un autre étudiant.
        $this->actingAs($a, 'web')->fromApp()->getJson('/api/v1/me/photo?student_id=3012')->assertStatus(404);
        unset($b);
    }

    public function test_the_photo_endpoint_is_for_students_and_logged_in_users_only(): void
    {
        $this->getJson('/api/v1/me/photo')->assertStatus(401);

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff, 'web')->fromApp()->getJson('/api/v1/me/photo')->assertStatus(403);
    }

    // ------------------------------------------------------------------------------------ fiche et PDF

    private function enrolled(string $id): int
    {
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll($id, $s);
        $this->finance($id, $s);

        return $s;
    }

    public function test_the_sheet_says_whether_a_photo_exists_without_exposing_the_file_name(): void
    {
        $this->jpeg('a.jpg');
        $with = $this->makeStudent('3020');
        $without = $this->makeStudent('3021', 'Autre', 'Etudiant');
        $this->setStudentFile('3020', ['image_student' => 'a.jpg']);
        $s1 = $this->enrolled('3020');
        $s2 = $this->enrolled('3021');

        $r1 = $this->actingAs($with, 'web')->fromApp()->getJson("/api/v1/me/enrollments/{$s1}")->assertOk();
        $r2 = $this->actingAs($without, 'web')->fromApp()->getJson("/api/v1/me/enrollments/{$s2}")->assertOk();

        $this->assertTrue($r1->json('data.student.has_photo'));
        $this->assertFalse($r2->json('data.student.has_photo'));
        $this->assertStringNotContainsString('a.jpg', $r1->getContent());
        $this->assertStringNotContainsString($this->dir, $r1->getContent());
    }

    public function test_the_pdf_embeds_the_photo_when_there_is_one_and_a_frame_when_there_is_not(): void
    {
        $this->jpeg('a.jpg', 800, 1000);
        $with = $this->makeStudent('3030');
        $without = $this->makeStudent('3031', 'Autre', 'Etudiant');
        $this->setStudentFile('3030', ['image_student' => 'a.jpg']);
        $s1 = $this->enrolled('3030');
        $s2 = $this->enrolled('3031');

        $pdfWith = $this->actingAs($with, 'web')->fromApp()->get("/api/v1/me/enrollments/{$s1}/pdf");
        $pdfWithout = $this->actingAs($without, 'web')->fromApp()->get("/api/v1/me/enrollments/{$s2}/pdf");

        $pdfWith->assertOk();
        $pdfWithout->assertOk();
        $this->assertStringContainsString('/Subtype /Image', $pdfWith->getContent());
        $this->assertStringNotContainsString('/Subtype /Image', $pdfWithout->getContent());
        $this->assertStringStartsWith('%PDF-', $pdfWithout->getContent());   // la fiche sans photo reste un PDF valide
    }

    public function test_the_pdf_never_embeds_a_photo_from_a_hostile_path(): void
    {
        file_put_contents(dirname($this->dir).DIRECTORY_SEPARATOR.'evasion.jpg', 'x');
        imagejpeg(imagecreatetruecolor(300, 300), dirname($this->dir).DIRECTORY_SEPARATOR.'evasion.jpg');
        $me = $this->makeStudent('3040');
        $this->setStudentFile('3040', ['image_student' => '../evasion.jpg']);
        $s = $this->enrolled('3040');

        $res = $this->actingAs($me, 'web')->fromApp()->get("/api/v1/me/enrollments/{$s}/pdf");

        $this->assertStringNotContainsString('/Subtype /Image', $res->getContent());
        $this->assertFalse($this->getJson('/api/v1/me/photo')->isOk());
        @unlink(dirname($this->dir).DIRECTORY_SEPARATOR.'evasion.jpg');
    }
}
