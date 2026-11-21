<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\StaffTranscripts;
use App\Domain\Documents\Transcript;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Le relevé semestriel pour le personnel : droits, périmètre, règles de calcul de l'ancien relevé, filtres, PDF. */
class StaffTranscriptTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    private int $s1;

    private int $s2;

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

    private function rowId(string $student): int
    {
        return (int) DB::table('tbl_2024_etudiant')->where('student_id', $student)->value('id');
    }

    private function row(string $student): object
    {
        return DB::table('tbl_2024_etudiant')->where('student_id', $student)->first();
    }

    /** @return array<string, mixed> */
    private function build(string $student, array $filters = []): array
    {
        return app(Transcript::class)->build($this->row($student), $filters);
    }

    private function pdfAs(User $u, int $id, string $query = '')
    {
        return $this->actingAs($u, 'web')->fromApp()->get("/api/v1/staff/students/{$id}/transcript/pdf{$query}", ['Accept' => 'application/json']);
    }

    private function optionsAs(User $u, int $id)
    {
        return $this->actingAs($u, 'web')->fromApp()->getJson("/api/v1/staff/students/{$id}/transcript");
    }

    /** Deux sessions : 2025 - 2026 (semestre 1) puis 2026 - 2027 (semestre 1). */
    private function sessions(): void
    {
        $this->s1 = $this->makeSession('Session 1', '2025 - 2026', 1);
        $this->s2 = $this->makeSession('Session 2', '2026 - 2027', 1);
    }

    private function student(string $id = '8000'): string
    {
        $this->makeStudent($id, 'Rakoto', 'Fanja');
        $this->setStudentFile($id, ['annee_etude' => '2', 'etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux']);

        return $id;
    }

    // ------------------------------------------------------------------------------------------------ droits

    public function test_levels_one_to_four_can_list_and_print_the_others_get_403_before_any_lookup(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14');
        $row = $this->rowId($id);

        foreach ([$this->staff(1, 'superadmin'), $this->staff(2, 'admin'), $this->staff(3, 'registrar'), $this->staff(4, 'comptabilite')] as $u) {
            $this->optionsAs($u, $row)->assertOk();
            $this->pdfAs($u, $row)->assertOk();
        }
        foreach ([$this->staff(0, 'registrar'), $this->staff(5, 'media'), $this->staff(6, 'chef_mention'), $this->staff(7, 'teacher'), User::factory()->staff(3, 'registrar')->inactive()->create(), $this->makeStudent('8001')] as $u) {
            $this->optionsAs($u, $row)->assertStatus(403)->assertJsonPath('code', 'forbidden.documents');
            $this->pdfAs($u, $row)->assertStatus(403);
            $this->pdfAs($u, 999999)->assertStatus(403);   // même réponse qu'un étudiant qui existe
        }
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->sessions();
        $id = $this->student();
        $row = $this->rowId($id);

        $this->fromApp()->getJson("/api/v1/staff/students/{$row}/transcript")->assertStatus(401);
        $this->fromApp()->get("/api/v1/staff/students/{$row}/transcript/pdf", ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_a_cycle_limited_account_does_not_reach_another_cycle(): void
    {
        $this->sessions();
        $id = $this->student();                       // licence 2
        $this->makeNote($id, $this->s1, 'Algo', '14');
        $row = $this->rowId($id);

        $this->optionsAs($this->staff(3, 'registrar', ['perimetre_cycle' => 'M']), $row)->assertStatus(404)->assertJsonPath('code', 'document.not_found');
        $this->pdfAs($this->staff(3, 'registrar', ['perimetre_cycle' => 'M']), $row)->assertStatus(404);
        $this->pdfAs($this->staff(3, 'registrar', ['perimetre_cycle' => 'L']), $row)->assertOk();
        $this->pdfAs($this->staff(3, 'registrar'), 999999)->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------------------ choix

    public function test_the_options_list_the_sessions_with_notes_oldest_first_without_removed_or_dropped_courses(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s2, 'B', '12');
        $this->makeNote($id, $this->s1, 'A', '14');
        $this->makeNote($id, $this->s1, 'A2', '15');
        $this->makeNote($id, $this->s1, 'Retiré', '9', 3, ['ajout' => 0]);     // cours retiré : la reprise le remplace
        $this->makeNote($id, $this->s1, 'Supprimé', '9', 3, ['remove' => 1]);   // supprimé
        $s3 = $this->makeSession('Session 3', '2027 - 2028', 1);
        $this->makeNote($id, $s3, 'Vide', '9', 3, ['ajout' => 0]);               // session sans aucun cours retenu
        $s4 = $this->makeSession('Session 4', '2028 - 2029', 1);
        $this->makeNote($id, $s4, 'Supprimé seul', '9', 3, ['remove' => 1]);     // session dont le seul cours est supprimé

        $data = $this->optionsAs($this->staff(3, 'registrar'), $this->rowId($id))->assertOk()->json('data');

        $this->assertSame('8000', $data['student_id']);
        $this->assertSame('RAKOTO Fanja', $data['name']);
        $this->assertSame([$this->s1, $this->s2], array_column($data['sessions'], 'session_id'));
        $this->assertSame([2, 1], array_column($data['sessions'], 'courses'));
        $this->assertSame(['2025 - 2026', '2026 - 2027'], array_column($data['sessions'], 'year'));
    }

    // ------------------------------------------------------------------------------------------------ calcul

    public function test_each_course_state_and_the_session_totals_follow_the_old_transcript(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14', 3, ['Sigle' => 'INF 101', 'cours_category' => 1]);          // majeur, validé
        $this->makeNote($id, $this->s1, 'Maths', '8', 2, ['Sigle' => 'MAT 101', 'cours_category' => 0]);          // général, échec
        $this->makeNote($id, $this->s1, 'Stage', '-4', 3, ['Sigle' => 'STG 101', 'cours_category' => 1]);          // pas encore noté
        $this->makeNote($id, $this->s1, 'Zéro', '0', 3, ['Sigle' => 'ZER 101', 'cours_category' => 0]);            // 0 : en attente, hors moyenne
        $this->makeNote($id, $this->s1, 'Sans note validé', '-2', 4, ['Sigle' => 'SNV 101', 'cours_category' => 0]); // V sans note
        $this->makeNote($id, $this->s1, 'Sans note échec', '-3', 1, ['Sigle' => 'SNE 101', 'cours_category' => 0]);  // E sans note
        $this->makeNote($id, $this->s1, 'Spirituelle', '12', 1, ['Sigle' => 'REL 100', 'cours_category' => 5]);     // validation : V
        $this->makeNote($id, $this->s1, 'Formation spirituelle', '6', 2, ['Sigle' => 'FSP 100', 'cours_category' => 0]); // validation : E
        $this->makeNote($id, $this->s1, 'Virgule', '12,5', 2, ['Sigle' => 'VIR 101', 'cours_category' => 2]);       // virgule décimale
        $this->makeNote($id, $this->s1, 'Texte', 'abc', 1, ['Sigle' => 'TXT 101', 'cours_category' => 3]);          // texte : pas encore noté
        $this->makeNote($id, $this->s1, 'Vide', '', 1, ['Sigle' => 'VID 101', 'cours_category' => null]);          // vide, catégorie vide
        $this->makeNote($id, $this->s1, 'Validation dix', '10', 1, ['Sigle' => 'VAL 010', 'cours_category' => 5]);  // validation à 10 pile : V
        $this->makeNote($id, $this->s1, 'Validation un', '1', 1, ['Sigle' => 'VAL 001', 'cours_category' => 5]);    // validation à 1 : E
        $this->makeNote($id, $this->s1, 'Inconnue', '15', 1, ['Sigle' => 'CAT 004', 'cours_category' => 4]);        // catégorie inconnue

        $s = $this->build($id)['sessions'][0];
        $by = array_column($s['courses'], null, 'code');

        $this->assertSame(['14', 14.0 * 3, 'valid', 'Majeur'], [$by['INF 101']['note'], $by['INF 101']['points'], $by['INF 101']['state'], $by['INF 101']['category']]);
        $this->assertSame(['8', 16.0, 'failed', 'Général'], [$by['MAT 101']['note'], $by['MAT 101']['points'], $by['MAT 101']['state'], $by['MAT 101']['category']]);
        $this->assertSame(['NN', null, 'incomplete'], [$by['STG 101']['note'], $by['STG 101']['points'], $by['STG 101']['state']]);
        $this->assertSame(['--', null, 'incomplete'], [$by['ZER 101']['note'], $by['ZER 101']['points'], $by['ZER 101']['state']]);
        $this->assertSame(['V', null, 'valid'], [$by['SNV 101']['note'], $by['SNV 101']['points'], $by['SNV 101']['state']]);
        $this->assertSame(['E', null, 'failed'], [$by['SNE 101']['note'], $by['SNE 101']['points'], $by['SNE 101']['state']]);
        $this->assertSame(['V', null, 'valid', 'validation'], [$by['REL 100']['note'], $by['REL 100']['points'], $by['REL 100']['state'], $by['REL 100']['category']]);
        $this->assertSame(['E', null, 'failed', 'validation'], [$by['FSP 100']['note'], $by['FSP 100']['points'], $by['FSP 100']['state'], $by['FSP 100']['category']]);
        $this->assertSame(['12.5', 25.0, 'valid', 'Sélective'], [$by['VIR 101']['note'], $by['VIR 101']['points'], $by['VIR 101']['state'], $by['VIR 101']['category']]);

        $this->assertSame(['NN', null, 'incomplete'], [$by['TXT 101']['note'], $by['TXT 101']['points'], $by['TXT 101']['state']]);
        $this->assertSame(['NN', '-', 'incomplete'], [$by['VID 101']['note'], $by['VID 101']['category'], $by['VID 101']['state']]);
        $this->assertSame(['V', 'valid'], [$by['VAL 010']['note'], $by['VAL 010']['state']]);
        $this->assertSame(['E', 'failed'], [$by['VAL 001']['note'], $by['VAL 001']['state']]);
        $this->assertSame(['-', 'Additionnel'], [$by['CAT 004']['category'], $by['TXT 101']['category']]);

        // Entrent dans la moyenne : INF 101 (3 × 14), MAT 101 (2 × 8), VIR 101 (2 × 12,5) et CAT 004 (1 × 15) : 42 + 16 + 25 + 15 = 98 points pour 8 crédits.
        $this->assertSame(98.0, $s['totals']['points']);
        $this->assertSame(49.5, $s['totals']['grades']);     // 14 + 8 + 12,5 + 15
        $this->assertSame(26, $s['totals']['credits']);      // tous les crédits, même hors moyenne
        $this->assertSame(14, $s['totals']['courses']);
        $this->assertSame(round(98 / 8, 2), $s['averages']['general']);
        $this->assertSame(14.0, $s['averages']['major']);    // seul INF 101 est majeur ET compte (STG 101 est NN)
        $expected = ['valid_courses' => 6, 'valid_credits' => 12, 'failed_courses' => 4, 'failed_credits' => 6, 'incomplete_courses' => 4, 'incomplete_credits' => 8];
        $this->assertSame($expected, $s['recap']);
        // Le récapitulatif général d'une seule session reprend les mêmes nombres.
        $sum = $this->build($id)['summary'];
        $this->assertSame([6, 12, 4, 6, 4, 8, 26, 14, 1], [$sum['valid_courses'], $sum['valid_credits'], $sum['failed_courses'], $sum['failed_credits'], $sum['incomplete_courses'], $sum['incomplete_credits'], $sum['credits'], $sum['courses'], $sum['sessions']]);
    }

    public function test_an_average_without_any_counted_grade_is_absent_not_zero(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Stage', '-4', 3);

        $t = $this->build($id);

        $this->assertNull($t['sessions'][0]['averages']['general']);
        $this->assertNull($t['sessions'][0]['averages']['major']);
        $this->assertNull($t['cumulative']['general']);
        $this->assertNull($t['cumulative']['major']);
        $this->assertSame(0.0, $t['summary']['success_rate']);
    }

    public function test_the_session_level_is_the_majority_level_and_the_higher_one_on_a_tie(): void
    {
        $this->sessions();
        $id = $this->student();
        foreach ([1, 1, 2] as $l) {
            $this->makeNote($id, $this->s1, 'A', '12', 3, ['yearlevel' => $l]);
        }
        foreach ([1, 2] as $l) {
            $this->makeNote($id, $this->s2, 'B', '12', 3, ['yearlevel' => $l]);
        }
        $s3 = $this->makeSession('Session 3', '2027 - 2028', 1);
        $this->makeNote($id, $s3, 'C', '12', 3, ['yearlevel' => 4]);
        $s4 = $this->makeSession('Session 4', '2028 - 2029', 1);
        $this->makeNote($id, $s4, 'D', '12', 3, ['yearlevel' => 0]);
        $s5 = $this->makeSession('Session 5', '2029 - 2030', 1);
        $this->makeNote($id, $s5, 'E', '12', 3, ['yearlevel' => 3]);

        $levels = array_column($this->build($id)['sessions'], 'level_label');

        $this->assertSame(['Licence 1', 'Licence 2', 'Master 1', 'Remise à niveau', 'Licence 3'], $levels);   // égalité 1/2 : le plus élevé
    }

    public function test_sessions_are_ordered_by_year_then_semester_and_the_cumulative_adds_credits_and_points_not_averages(): void
    {
        $a = $this->makeSession('B', '2025 - 2026', 2);
        $b = $this->makeSession('A', '2025 - 2026', 1);
        $c = $this->makeSession('C', '2024 - 2025', 2);
        $id = $this->student();
        $this->makeNote($id, $a, 'X', '10', 1);     // 1 crédit à 10
        $this->makeNote($id, $b, 'Y', '20', 9);     // 9 crédits à 20 : la moyenne de moyennes serait 15, la pondérée 19
        $this->makeNote($id, $c, 'Z', '10', 0);     // 0 crédit

        $t = $this->build($id);

        $this->assertSame([$c, $b, $a], array_column(array_column($t['sessions'], 'session'), 'id'));
        $this->assertSame(round((10 + 180) / 10, 2), $t['cumulative']['general']);
        $this->assertSame(19.0, $t['cumulative']['major']);       // catégorie 1 par défaut : mêmes points et crédits que la générale
        $this->assertSame(10, $t['summary']['credits']);
        $this->assertSame(3, $t['summary']['sessions']);
        $this->assertSame(3, $t['summary']['courses']);
        $this->assertSame(100.0, $t['summary']['success_rate']);
    }

    public function test_removed_and_dropped_courses_never_appear(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Gardé', '14');
        $this->makeNote($id, $this->s1, 'Supprimé', '3', 3, ['remove' => 1]);
        $this->makeNote($id, $this->s1, 'Retiré', '3', 3, ['ajout' => 0]);
        $this->makeNote('9999', $this->s1, 'Autre étudiant', '3');

        $s = $this->build($id)['sessions'][0];

        $this->assertSame(['Gardé'], array_column($s['courses'], 'title'));
    }

    // ------------------------------------------------------------------------------------------------ promotion

    public function test_promotion_grades_use_the_first_row_and_the_cumulative_counts_only_sessions_where_they_are_filled(): void
    {
        $this->sessions();
        $id = $this->student('8000');
        $this->makeNote($id, $this->s1, 'A', '12');
        $this->makeNote($id, $this->s2, 'B', '12');
        $this->makePromotion(8000, $this->s1, 16.0, 11.0, 14.0);
        $this->makePromotion(8000, $this->s1, 1.0, 1.0, 1.0);        // doublon : seule la première ligne compte, comme l'ancien fetch()
        $this->makePromotion(8000, $this->s2, 0.0, 12.0, 18.0);      // work à 0 : ignoré du cumul (comme l'ancien !empty)
        $s3 = $this->makeSession('Session 3', '2027 - 2028', 1);
        $this->makeNote($id, $s3, 'C', '12');
        $this->makePromotion(8000, $s3, 14.0, 10.0, 0.0);            // chapelle à 0 : ignorée du cumul

        $t = $this->build($id);

        $this->assertSame(16.0, $t['sessions'][0]['promotion']['work_education']);
        $this->assertSame(14.0, $t['sessions'][0]['promotion']['chapel']);
        $this->assertSame(round((16 + 14) / 2, 2), $t['cumulative']['work_education']);   // deux sessions renseignées (la deuxième est à 0)
        $this->assertSame(round((14 + 18) / 2, 2), $t['cumulative']['chapel']);            // deux sessions renseignées (la troisième est à 0)
    }

    public function test_a_matricule_that_is_not_numeric_never_reads_another_students_promotion_grades(): void
    {
        $this->sessions();
        $id = $this->student('20023MG');
        $this->makeNote($id, $this->s1, 'A', '12');
        $this->makePromotion(20023, $this->s1, 16.0, 11.0, 14.0);        // celle d'un AUTRE étudiant (matricule 20023)

        $t = $this->build($id);

        $this->assertNull($t['sessions'][0]['promotion']);
        $this->assertNull($t['cumulative']['work_education']);
    }

    // ------------------------------------------------------------------------------------------------ filtres

    public function test_the_filters_narrow_the_sessions_like_the_old_transcript(): void
    {
        $a = $this->makeSession('S1', '2025 - 2026', 1);
        $b = $this->makeSession('S2', '2025 - 2026', 2);
        $c = $this->makeSession('S3', '2026 - 2027', 1);
        $id = $this->student();
        $this->makeNote($id, $a, 'A', '12', 3, ['yearlevel' => 1]);
        $this->makeNote($id, $b, 'B', '12', 3, ['yearlevel' => 2]);
        $this->makeNote($id, $c, 'C', '12', 3, ['yearlevel' => 2]);
        $ids = fn (array $f) => array_column(array_column($this->build($id, $f)['sessions'], 'session'), 'id');

        $this->assertSame([$a, $b, $c], $ids([]));
        $this->assertSame([$a, $b], $ids(['year' => '2025 - 2026']));
        $this->assertSame([$a, $c], $ids(['semester' => 1]));
        $this->assertSame([$b, $c], $ids(['level' => 2]));
        $this->assertSame([$c], $ids(['session' => $c]));
        $this->assertSame([$a], $ids(['year' => '2025 - 2026', 'semester' => 1]));
        $this->assertSame([], $ids(['year' => '1999 - 2000']));
        // Le niveau filtre les sessions, pas leurs cours : la session B garde tous ses cours.
        $this->assertSame(['B'], array_column($this->build($id, ['level' => 2, 'session' => $b])['sessions'][0]['courses'], 'title'));
    }

    // ------------------------------------------------------------------------------------------------ PDF

    public function test_the_pdf_is_a_real_never_cached_attachment_named_after_the_year(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14');
        $row = $this->rowId($id);
        $reg = $this->staff(3, 'registrar');

        $all = $this->pdfAs($reg, $row)->assertOk();
        $this->assertSame('application/pdf', $all->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $all->getContent());
        $this->assertStringContainsString('no-store', $all->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $all->headers->get('X-Content-Type-Options'));
        $this->assertSame('attachment; filename="8000-releve-semestriel.pdf"', $all->headers->get('Content-Disposition'));

        $year = $this->pdfAs($reg, $row, '?year='.urlencode('2025 - 2026'))->assertOk();
        $this->assertSame('attachment; filename="8000-releve-semestriel-2025-2026.pdf"', $year->headers->get('Content-Disposition'));
    }

    public function test_an_empty_result_is_still_a_valid_pdf_that_says_so(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14');

        $res = $this->pdfAs($this->staff(3, 'registrar'), $this->rowId($id), '?year='.urlencode('1999 - 2000'))->assertOk();

        $this->assertStringStartsWith('%PDF-', $res->getContent());
    }

    public function test_unreadable_filters_are_refused_with_422_instead_of_printing_a_complete_transcript(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14');
        $row = $this->rowId($id);
        $reg = $this->staff(3, 'registrar');

        foreach (['?year=2O25', '?year=2025', '?semester=abc', '?semester=9', '?level=9', '?level=-1', '?session=0', '?session=1x', '?year[]=x'] as $q) {
            $this->pdfAs($reg, $row, $q)->assertStatus(422)->assertJsonPath('code', 'validation.transcript');
        }
        foreach (['?year=all&semester=all&level=all', '?year=&semester=', '?semester=1', '?level=0', '?session=5'] as $q) {
            $this->pdfAs($reg, $row, $q)->assertOk();
        }
    }

    public function test_the_footer_carries_the_configured_name_and_signature_never_a_default_image(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14');
        $row = $this->rowId($id);
        $reg = $this->staff(3, 'registrar');

        config(['registrar.signature_file' => null]);
        $without = $this->pdfAs($reg, $row)->assertOk()->getContent();
        $this->assertStringNotContainsString('/Subtype /Image', $without);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sig-test-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 40, 120));
        imagepng($img, $dir.DIRECTORY_SEPARATOR.'signature.png');
        try {
            config(['registrar.signature_file' => $dir.DIRECTORY_SEPARATOR.'signature.png', 'registrar.registrar_name' => 'Madame Test']);
            $with = $this->pdfAs($reg, $row)->assertOk()->getContent();
            $this->assertStringContainsString('/Subtype /Image', $with);

            config(['registrar.signature_file' => $dir.DIRECTORY_SEPARATOR.'absent.png']);                 // fichier absent : pas d'image
            $this->assertStringNotContainsString('/Subtype /Image', $this->pdfAs($reg, $row)->getContent());
            file_put_contents($dir.DIRECTORY_SEPARATOR.'faux.png', 'pas une image');
            config(['registrar.signature_file' => $dir.DIRECTORY_SEPARATOR.'faux.png']);                    // pas une image : refusée
            $this->assertStringNotContainsString('/Subtype /Image', $this->pdfAs($reg, $row)->getContent());
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_the_students_photo_is_embedded_and_an_oversized_signature_is_refused(): void
    {
        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14');
        $row = $this->rowId($id);
        $reg = $this->staff(3, 'registrar');
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'photos-tr-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $photo = imagecreatetruecolor(300, 400);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 30, 120, 160));
        imagejpeg($photo, $dir.DIRECTORY_SEPARATOR.'8000.jpg', 80);
        $big = imagecreatetruecolor(3500, 3500);                      // 12,25 millions de pixels : au-dessus du plafond
        imagepng($big, $dir.DIRECTORY_SEPARATOR.'grande.png', 9);
        $this->setStudentFile($id, ['image_student' => '8000.jpg']);
        config(['registrar.student_photos_path' => $dir, 'registrar.signature_file' => $dir.DIRECTORY_SEPARATOR.'grande.png']);

        try {
            $this->assertStringContainsString('/Subtype /Image', $this->pdfAs($reg, $row)->assertOk()->getContent());   // la photo, pas la signature
            $this->setStudentFile($id, ['image_student' => '']);
            $this->assertStringNotContainsString('/Subtype /Image', $this->pdfAs($reg, $row)->assertOk()->getContent()); // ni photo ni signature
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_the_pdf_route_is_throttled_and_dates_are_written_in_french(): void
    {
        $this->assertSame('21 novembre 2026', StaffTranscripts::dateLabel(Carbon::parse('2026-11-21')));
        $this->assertSame('1 février 2027', StaffTranscripts::dateLabel(Carbon::parse('2027-02-01')));
        $this->assertSame('8000-releve-semestriel-2025-2026.pdf', StaffTranscripts::filename('8000', ['year' => '2025 - 2026', 'semester' => null, 'level' => null, 'session' => null]));
        $this->assertSame('AB-12-releve-semestriel.pdf', StaffTranscripts::filename('A/B ..-12', ['year' => null, 'semester' => null, 'level' => null, 'session' => null]));

        $this->sessions();
        $id = $this->student();
        $this->makeNote($id, $this->s1, 'Algo', '14');
        $reg = $this->staff(3, 'registrar');
        for ($i = 0; $i < 30; $i++) {
            $this->pdfAs($reg, $this->rowId($id))->assertOk();
        }
        $this->pdfAs($reg, $this->rowId($id))->assertStatus(429);
    }
}
