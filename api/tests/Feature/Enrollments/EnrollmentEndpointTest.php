<?php

namespace Tests\Feature\Enrollments;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** « Mes inscriptions » et la fiche d'une session : visibilité, isolation entre étudiants, montants. */
class EnrollmentEndpointTest extends TestCase
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

    private function getAs(User $u, string $url)
    {
        return $this->actingAs($u, 'web')->fromApp()->getJson($url);
    }

    private function sheet(User $u, int $session)
    {
        return $this->getAs($u, "/api/v1/me/enrollments/{$session}");
    }

    // ------------------------------------------------------------------------------------------------ liste

    public function test_the_list_groups_by_year_most_recent_first_and_hides_old_licence_sheets(): void
    {
        $u = $this->makeStudent('1000');
        $old = $this->makeSession('Session 1', '2025 - 2026', 1);     // Licence : avant 2026, non visible
        $s1 = $this->makeSession('Session 1', '2026 - 2027', 1);
        $s2 = $this->makeSession('Session 2', '2026 - 2027', 2);
        foreach ([$old, $s1, $s2] as $s) {
            $this->enroll('1000', $s, 2);
        }
        $this->billedCourse('1000', $s1, null, 'INF 101', 3, 100000);
        $this->billedCourse('1000', $s1, null, 'INF 102', 3, 100000);
        $this->billedCourse('1000', $s1, null, 'INF 103', 3, 100000, ['remove' => 1]); // retiré : ne compte pas
        $this->finance('1000', $s1, ['cout_fraix_generaux' => 50000]);

        $res = $this->getAs($u, '/api/v1/me/enrollments')->assertOk();

        $this->assertTrue($res->json('data.visible'));
        $this->assertSame(['2026 - 2027'], array_column($res->json('data.years'), 'year'));
        $sessions = $res->json('data.years.0.sessions');
        $this->assertSame(['Session 2', 'Session 1'], array_column($sessions, 'session_name')); // la plus récente d'abord
        $this->assertSame([0, 2], array_column($sessions, 'courses'));
        $this->assertSame([false, true], array_column($sessions, 'has_fees'));
        $this->assertSame('Licence 2', $sessions[0]['level_label']);
        $this->assertSame('2026-09-01', $sessions[0]['enrolled_on']);
    }

    public function test_a_master_student_sees_sheets_from_2025_2026(): void
    {
        $u = $this->makeStudent('1001');
        $s = $this->makeSession('Session 1', '2025 - 2026', 1);
        $this->enroll('1001', $s, 4);

        $this->assertCount(1, $this->getAs($u, '/api/v1/me/enrollments')->json('data.years'));
    }

    public function test_the_hidden_rubric_hides_the_list_and_every_sheet(): void
    {
        $u = $this->makeStudent('1002');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1002', $s);
        $this->setRubric('fiches_inscription', false);

        $res = $this->getAs($u, '/api/v1/me/enrollments')->assertOk();
        $this->assertFalse($res->json('data.visible'));
        $this->assertSame([], $res->json('data.years'));
        $this->sheet($u, $s)->assertStatus(404);
        $this->actingAs($u, 'web')->fromApp()->get("/api/v1/me/enrollments/{$s}/pdf", ['Accept' => 'application/json'])->assertStatus(404);

        $this->setRubric('fiches_inscription', true);
        $this->sheet($u, $s)->assertOk();
    }

    public function test_removed_enrollments_do_not_appear_and_duplicates_collapse_to_the_latest(): void
    {
        $u = $this->makeStudent('1003');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1003', $s, 1, 'Ancienne mention');
        $this->enroll('1003', $s, 3, 'Récente mention');
        $this->enroll('1003', $this->makeSession('Session 2', '2026 - 2027', 2), 1, 'X', ['remove' => 1]);

        $sessions = $this->getAs($u, '/api/v1/me/enrollments')->json('data.years.0.sessions');

        $this->assertCount(1, $sessions);
        $this->assertSame(['Licence 3', 'Récente mention'], [$sessions[0]['level_label'], $sessions[0]['mention']]);
    }

    public function test_a_student_without_enrollment_gets_an_empty_but_valid_list(): void
    {
        $u = $this->makeStudent('1004');

        $this->getAs($u, '/api/v1/me/enrollments')->assertOk()->assertJsonPath('data.years', [])->assertJsonPath('data.visible', true);
    }

    // ----------------------------------------------------------------------------------------------- accès

    public function test_a_session_of_another_student_unknown_or_too_old_is_simply_not_found(): void
    {
        $a = $this->makeStudent('1010');
        $b = $this->makeStudent('1011', 'Autre', 'Etudiant');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $old = $this->makeSession('Session 1', '2024 - 2025', 1);
        $this->enroll('1010', $s);
        $this->enroll('1010', $old);
        $this->finance('1010', $s, ['cout_fraix_generaux' => 123456]);

        $this->sheet($a, $s)->assertOk();
        $own = $this->sheet($b, $s)->assertStatus(404)->assertJsonPath('code', 'enrollment.not_found');
        $this->assertStringNotContainsString('123', $own->getContent());   // rien de la fiche d'un autre
        $this->sheet($a, 99999999)->assertStatus(404);
        $this->sheet($a, $old)->assertStatus(404);                          // trop ancienne : pas non plus par son adresse
    }

    public function test_staff_and_anonymous_are_kept_out(): void
    {
        $this->getJson('/api/v1/me/enrollments')->assertStatus(401);
        $this->getJson('/api/v1/me/enrollments/1')->assertStatus(401);
        $this->getJson('/api/v1/me/enrollments/1/pdf')->assertStatus(401);

        $staff = User::factory()->staff()->create();
        $this->getAs($staff, '/api/v1/me/enrollments')->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');
        $this->getAs($staff, '/api/v1/me/enrollments/1')->assertStatus(403);
    }

    public function test_a_non_numeric_session_is_not_a_route(): void
    {
        $u = $this->makeStudent('1012');

        $this->getAs($u, '/api/v1/me/enrollments/abc')->assertStatus(404);
        $this->getAs($u, '/api/v1/me/enrollments/1;DROP')->assertStatus(404);
    }

    // ---------------------------------------------------------------------------------------------- montants

    private function licenceSheet(): array
    {
        $this->setStudentFile('1020', ['etude_envisage' => 'Informatique', 'annee_etude' => 2, 'abonment' => 1, 'status' => 'Externe']);
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1020', $s, 2);
        $lab1 = $this->catalogCourse('INF 110', 1, 1, 40000);
        $lab2 = $this->catalogCourse('INF 120', 1, 1, 30000);
        $lab3 = $this->catalogCourse('INF 130', 1, 1, 20000);   // le 3e laboratoire est offert
        $plain = $this->catalogCourse('MAT 100', 0);
        $this->billedCourse('1020', $s, $lab1, 'INF 110', 3, 300000);
        $this->billedCourse('1020', $s, $lab2, 'INF 120', 3, 300000);
        $this->billedCourse('1020', $s, $lab3, 'INF 130', 2, 200000);
        $this->billedCourse('1020', $s, $plain, 'MAT 100', 4, 400000);
        $this->finance('1020', $s, [
            'cout_fraix_generaux' => 100000, 'cout_voyage' => 50000, 'cout_logement' => 0, 'cout_abonment' => 80000,
            'cout_fondDepot_dortoir' => 60000, 'mode_payement' => 'B',
        ]);
        $this->dueDate($s, 'B', 1, '2026-10-01');
        $this->dueDate($s, 'B', 2, '2026-12-15');

        return [$this->makeUserFor('1020'), $s];
    }

    private function makeUserFor(string $id): User
    {
        return User::query()->where('student_id', $id)->firstOrFail();
    }

    public function test_courses_totals_laboratory_rule_and_categories(): void
    {
        $this->makeStudent('1020');
        [$u, $s] = $this->licenceSheet();

        $d = $this->sheet($u, $s)->assertOk()->json('data');

        $this->assertSame(['INF 110', 'INF 120', 'INF 130', 'MAT 100'], array_column($d['courses'], 'code'));
        $this->assertSame(['Majeur', 'Majeur', 'Majeur', 'Général'], array_column($d['courses'], 'category'));
        $this->assertSame([4, 1200000, 70000], [$d['course_totals']['count'], (int) $d['course_totals']['cost'], (int) $d['course_totals']['lab']]);
        $this->assertEquals(12, $d['course_totals']['credits']);
        // Seuls les DEUX premiers laboratoires sont facturés (40 000 + 30 000) ; le troisième est offert.
        $this->assertSame([true, true, false, null], array_map(fn ($c) => $c['lab']['counted'] ?? null, $d['courses']));
    }

    public function test_fees_total_and_payment_schedule(): void
    {
        $this->makeStudent('1020');
        [$u, $s] = $this->licenceSheet();

        $f = $this->sheet($u, $s)->json('data.fees');

        // Scolarité : cours 1 200 000 + labo 70 000 + logement 0 + cafétéria 80 000 + graduation 0 = 1 350 000.
        // (le fonds de dépôt de 60 000 est dans « frais généraux + fonds de dépôt » des deux côtés : il s'annule)
        $this->assertSame(1350000, $f['tuition']['subtotal']);
        $this->assertSame(['Total des cours', 'Total de laboratoire', 'Frais de logement', 'Frais de cafétéria', 'Frais de graduation'], array_column($f['tuition']['rows'], 'label'));
        // Total général = généraux 100 000 + voyage 50 000 + scolarité 1 350 000.
        $this->assertSame(1500000, $f['grand_total']);
        $this->assertSame(['Frais généraux', 'Voyage d’étude'], array_column($f['general_rows'], 'label'));
        $this->assertSame('B', $f['payment']['mode']);
        $this->assertSame([[50, 675000, '1er octobre 2026'], [50, 675000, '15 décembre 2026']],
            array_map(fn ($i) => [$i['percent'], $i['amount'], $i['due_label']], $f['payment']['installments']));
    }

    public function test_dates_not_yet_set_are_left_empty_not_invented(): void
    {
        $this->makeStudent('1020');
        [$u, $s] = $this->licenceSheet();
        DB::table('t_paiement_echeance')->where('session_id', $s)->where('tranche', 2)->delete();

        $i = $this->sheet($u, $s)->json('data.fees.payment.installments');

        $this->assertSame('1er octobre 2026', $i[0]['due_label']);
        $this->assertNull($i[1]['due_label']);
        $this->assertNull($i[1]['due_date']);
    }

    public function test_graduation_fee_only_counts_in_the_second_semester_for_a_graduating_student(): void
    {
        foreach ([[2, 1, 300000], [1, 1, 0], [2, 0, 0]] as $i => [$semester, $graduating, $expected]) {
            $id = '103'.$i;
            $this->makeStudent($id);
            $this->setStudentFile($id, ['graduated' => $graduating, 'annee_etude' => 3]);
            $s = $this->makeSession('S'.$i, '2026 - 2027', $semester);
            $this->enroll($id, $s, 3);
            $this->finance($id, $s, ['cout_frais_graduation' => 300000]);

            $rows = $this->sheet($this->makeUserFor($id), $s)->json('data.fees.tuition.rows');

            $this->assertSame($expected, collect($rows)->firstWhere('label', 'Frais de graduation')['amount'], "semestre {$semester}, diplômant {$graduating}");
        }
    }

    public function test_costume_is_shown_for_theology_year_one_and_whenever_it_is_counted(): void
    {
        $this->makeStudent('1040');
        $this->setStudentFile('1040', ['etude_envisage' => 'Théologie', 'annee_etude' => 1]);
        $this->makeStudent('1041', 'Autre', 'Etudiant');
        $this->setStudentFile('1041', ['etude_envisage' => 'Informatique', 'annee_etude' => 2]);
        $this->makeStudent('1042', 'Autre', 'Troisieme');
        $this->setStudentFile('1042', ['etude_envisage' => 'Informatique', 'annee_etude' => 2]);
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        foreach ([['1040', 1, 70000], ['1041', 2, 70000], ['1042', 2, 0]] as [$id, $level, $costume]) {
            $this->enroll($id, $s, $level);
            $this->finance($id, $s, ['cout_costume' => $costume, 'cout_voyage' => 10000]);
        }

        $labels = fn (string $id) => array_column($this->sheet($this->makeUserFor($id), $s)->json('data.fees.general_rows'), 'label');

        $this->assertSame(['Frais généraux', 'Frais costume', 'Colloque'], $labels('1040'));         // Théologie : « Colloque »
        $this->assertSame(['Frais généraux', 'Frais costume', 'Voyage d’étude'], $labels('1041'));    // compté dans le total : donc montré
        $this->assertSame(['Frais généraux', 'Voyage d’étude'], $labels('1042'));                      // à 0 et hors Théologie L1 : rien

        // Le total égale la somme des lignes montrées : 0 + 70 000 + 10 000.
        $this->assertSame(80000, $this->sheet($this->makeUserFor('1041'), $s)->json('data.fees.grand_total'));
    }

    public function test_extra_fees_are_listed_and_added_to_the_total(): void
    {
        $u = $this->makeStudent('1050');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1050', $s);
        $this->finance('1050', $s, ['cout_fraix_generaux' => 100000]);
        $this->extraFee('1050', $s, 'Alliance française', 25000);
        $this->extraFee('1050', $s, 'Retiré', 99999);
        DB::table('t_2024_etudiant_frais_perso')->where('libelle', 'Retiré')->update(['remove' => 1]);

        $f = $this->sheet($u, $s)->json('data.fees');

        $this->assertContains('Alliance française', array_column($f['general_rows'], 'label'));
        $this->assertNotContains('Retiré', array_column($f['general_rows'], 'label'));
        $this->assertSame(125000, $f['grand_total']);
    }

    public function test_no_finance_line_is_named_not_hidden(): void
    {
        $u = $this->makeStudent('1060');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1060', $s);
        $this->billedCourse('1060', $s, null, 'INF 101', 3, 100000);

        $d = $this->sheet($u, $s)->assertOk()->json('data');

        $this->assertNull($d['fees']);
        $this->assertSame(1, $d['course_totals']['count']);
        $this->assertFalse($this->getAs($u, '/api/v1/me/enrollments')->json('data.years.0.sessions.0.has_fees'));
    }

    public function test_among_duplicate_finance_lines_the_one_with_a_payment_mode_wins(): void
    {
        $u = $this->makeStudent('1070');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1070', $s);
        $this->finance('1070', $s, ['cout_fraix_generaux' => 111000, 'mode_payement' => 'A']);   // ancienne, avec mode
        $this->finance('1070', $s, ['cout_fraix_generaux' => 222000, 'mode_payement' => '']);     // récente, sans mode
        $this->finance('1070', $s, ['cout_fraix_generaux' => 333000, 'mode_payement' => 'A', 'remove' => 1]); // retirée

        $f = $this->sheet($u, $s)->json('data.fees');

        $this->assertSame(111000, $f['general_rows'][0]['amount']);
        $this->assertSame('A', $f['payment']['mode']);
    }

    public function test_among_duplicate_finance_lines_with_equal_mode_the_latest_wins(): void
    {
        $u = $this->makeStudent('1071');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1071', $s);
        $this->finance('1071', $s, ['cout_fraix_generaux' => 111000, 'mode_payement' => 'A']);
        $this->finance('1071', $s, ['cout_fraix_generaux' => 222000, 'mode_payement' => 'A']);

        $this->assertSame(222000, $this->sheet($u, $s)->json('data.fees.general_rows.0.amount'));
    }

    public function test_master_sheet_drops_zero_lines_and_the_payment_block_when_nothing_is_due(): void
    {
        $u = $this->makeStudent('1080');
        $this->setStudentFile('1080', ['annee_etude' => 4, 'status' => 'Interne', 'abonment' => 1]);
        $s = $this->makeSession('Session 1', '2025 - 2026', 1);
        $this->enroll('1080', $s, 4);
        $this->finance('1080', $s, ['cout_fraix_generaux' => 100000, 'mode_payement' => 'A']);
        DB::table('t_work_education')->insert(['session_id' => $s, 'student_id' => '1080', 'tache' => 'Tâche attribuée par erreur']);

        $d = $this->sheet($u, $s)->assertOk()->json('data');

        $this->assertTrue($d['is_master']);
        $this->assertNull($d['fees']['tuition']);
        $this->assertNull($d['fees']['payment']);
        $this->assertFalse($d['fees']['grand_total_includes_tuition']);
        $this->assertSame(100000, $d['fees']['grand_total']);
        $this->assertNull($d['work_education']);
        $this->assertNull($d['student']['residence']);
        $this->assertNull($d['student']['cafeteria']);
    }

    public function test_master_sheet_keeps_only_the_non_zero_tuition_lines(): void
    {
        $u = $this->makeStudent('1081');
        $this->setStudentFile('1081', ['annee_etude' => 5]);
        $s = $this->makeSession('Session 1', '2025 - 2026', 1);
        $this->enroll('1081', $s, 5);
        $this->billedCourse('1081', $s, null, 'MST 501', 3, 500000);
        $this->finance('1081', $s, ['mode_payement' => 'A']);

        $t = $this->sheet($u, $s)->json('data.fees.tuition');

        $this->assertSame(['Total des cours'], array_column($t['rows'], 'label'));
        $this->assertSame(500000, $t['subtotal']);
    }

    public function test_licence_sheet_keeps_every_line_even_at_zero_and_shows_work_education(): void
    {
        $u = $this->makeStudent('1090');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1090', $s, 1);
        $this->finance('1090', $s);
        DB::table('t_work_education')->insert(['session_id' => $s, 'student_id' => '1090', 'tache' => 'Entretien de la bibliothèque']);

        $d = $this->sheet($u, $s)->json('data');

        $this->assertCount(5, $d['fees']['tuition']['rows']);
        $this->assertSame('Entretien de la bibliothèque', $d['work_education']);
        $this->assertFalse($d['is_master']);
    }

    public function test_a_licence_sheet_shows_residence_and_cafeteria_from_the_file(): void
    {
        $u = $this->makeStudent('1091');
        $this->setStudentFile('1091', ['status' => 'Interne', 'abonment' => 1]);
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1091', $s, 2);

        $st = $this->sheet($u, $s)->json('data.student');

        $this->assertSame('Interne', $st['residence']);
        $this->assertTrue($st['cafeteria']);
    }

    public function test_removed_courses_are_not_on_the_sheet_nor_in_its_totals(): void
    {
        $u = $this->makeStudent('1092');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1092', $s);
        $this->billedCourse('1092', $s, null, 'KEEP 1', 3, 100000);
        $this->billedCourse('1092', $s, null, 'GONE 1', 3, 777000, ['remove' => 1]);
        $this->finance('1092', $s);

        $d = $this->sheet($u, $s)->json('data');

        $this->assertSame(['KEEP 1'], array_column($d['courses'], 'code'));
        $this->assertSame(100000, (int) $d['course_totals']['cost']);
        $this->assertSame(100000, $d['fees']['tuition']['subtotal']);
    }

    public function test_courses_of_other_students_never_enter_my_sheet(): void
    {
        $u = $this->makeStudent('1093');
        $this->makeStudent('1094', 'Autre', 'Etudiant');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1093', $s);
        $this->enroll('1094', $s);
        $this->billedCourse('1093', $s, null, 'MINE 1', 3, 100000);
        $this->billedCourse('1094', $s, null, 'THEIRS', 3, 555000);
        $this->finance('1093', $s);

        $d = $this->sheet($u, $s)->json('data');

        $this->assertSame(['MINE 1'], array_column($d['courses'], 'code'));
        $this->assertSame(100000, $d['fees']['tuition']['subtotal']);
    }

    public function test_the_level_is_the_one_of_the_enrollment_not_the_current_file(): void
    {
        $u = $this->makeStudent('1100');
        $this->setStudentFile('1100', ['annee_etude' => 4]);   // aujourd'hui en Master 1
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1100', $s, 3);                           // mais cette session était en Licence 3
        $this->finance('1100', $s);

        $d = $this->sheet($u, $s)->json('data');

        $this->assertSame('Licence 3', $d['student']['level_label']);
        $this->assertFalse($d['is_master']);
        $this->assertCount(5, $d['fees']['tuition']['rows']);
    }

    public function test_a_course_missing_from_the_catalogue_is_unknown_not_general(): void
    {
        $u = $this->makeStudent('1110');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1110', $s);
        $this->billedCourse('1110', $s, 987654, 'OLD 100', 3, 150000);
        $this->finance('1110', $s);

        $c = $this->sheet($u, $s)->json('data.courses.0');

        $this->assertSame('—', $c['category']);
        $this->assertNull($c['lab']);
    }

    public function test_the_sheet_carries_identity_but_never_the_password_or_other_students(): void
    {
        $u = $this->makeStudent('1120', 'Rakoto', 'Fanja');
        $this->setStudentFile('1120', ['student_email' => 'f@example.test', 'student_tel' => '0340000000', 'sponsor_nom' => 'Bourse', 'sponsor_prenom' => 'Eglise']);
        $other = $this->makeStudent('1121', 'Secret', 'Voisin');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1120', $s);
        $this->enroll('1121', $s);
        $this->billedCourse('1121', $s, null, 'LEAK 1', 3, 999000);

        $res = $this->sheet($u, $s)->assertOk();
        $d = $res->json('data');

        $this->assertSame('RAKOTO Fanja', $d['student']['name']);
        $this->assertSame('Bourse Eglise', $d['student']['sponsor']);
        $this->assertSame([], $d['courses']);
        $this->assertStringNotContainsString('LEAK', $res->getContent());
        $this->assertStringNotContainsString('Secret', $res->getContent());
        $this->assertStringNotContainsString('password', $res->getContent());
        $this->assertSame('Fanja', explode(' ', $d['student']['name'])[1]);
        unset($other);
    }

    // ---------------------------------------------------------------------------------------------------- PDF

    public function test_the_pdf_is_a_real_pdf_with_a_safe_name_and_is_never_cached(): void
    {
        $this->makeStudent('1020');
        [$u, $s] = $this->licenceSheet();

        $res = $this->actingAs($u, 'web')->fromApp()->get("/api/v1/me/enrollments/{$s}/pdf");

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="1020-fiche-inscription-Session-1-2026-2027.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $body = $res->getContent();
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertGreaterThan(3000, strlen($body));
    }

    public function test_the_pdf_of_another_students_session_is_not_found(): void
    {
        $a = $this->makeStudent('1130');
        $b = $this->makeStudent('1131', 'Autre', 'Etudiant');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1130', $s);

        $this->actingAs($b, 'web')->fromApp()->getJson("/api/v1/me/enrollments/{$s}/pdf")->assertStatus(404);
        unset($a);
    }

    public function test_the_pdf_template_prints_the_figures_and_the_missing_fees_warning(): void
    {
        $this->makeStudent('1020');
        [$u, $s] = $this->licenceSheet();
        $withFees = view('pdf.enrollment-sheet', ['s' => $this->sheet($u, $s)->json('data')])->render();

        $nbsp = "\u{00A0}";
        $this->assertStringContainsString("1{$nbsp}500{$nbsp}000{$nbsp}Ar", $withFees);       // total général
        $this->assertStringContainsString("675{$nbsp}000{$nbsp}Ar", $withFees);               // une tranche
        $this->assertStringContainsString('1er octobre 2026', $withFees);
        $this->assertStringContainsString('ENGAGEMENT', $withFees);
        $this->assertStringContainsString('Je soussigné(e)', $withFees);
        $this->assertStringContainsString('Résidence', $withFees);
        $this->assertStringNotContainsString('Frais de scolarité non disponibles', $withFees);

        $u2 = $this->makeStudent('1140');
        $s2 = $this->makeSession('Session 2', '2026 - 2027', 2);
        $this->enroll('1140', $s2);
        $without = view('pdf.enrollment-sheet', ['s' => $this->sheet($u2, $s2)->json('data')])->render();
        $this->assertStringContainsString('Frais de scolarité non disponibles', $without);
        $this->assertStringContainsString('ne peut pas servir de justificatif de paiement', $without);
    }

    public function test_hostile_text_in_the_data_cannot_break_out_of_the_pdf_template(): void
    {
        $this->makeStudent('1150');
        $this->setStudentFile('1150', ['student_nom' => '<script>alert(1)</script>', 'student_adresse' => '<img src=x onerror=1>']);
        $u = $this->makeUserFor('1150');
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('1150', $s);
        $this->billedCourse('1150', $s, null, '<b>X</b>', 3, 1000);

        $html = view('pdf.enrollment-sheet', ['s' => $this->sheet($u, $s)->json('data')])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>X</b>', $html);
        $this->assertStringContainsStringIgnoringCase('&lt;script&gt;', $html);
    }
}
