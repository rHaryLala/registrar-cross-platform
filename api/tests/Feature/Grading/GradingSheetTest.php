<?php

namespace Tests\Feature\Grading;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/** Remise de notes, côté enseignant : lecture de la feuille, saisie cellule par cellule, pondération, transmission. */
class GradingSheetTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;
    use MakesTeacherData;

    private function api(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp();
    }

    private function sheetUrl(int $course, ?int $semester = 1): string
    {
        return "/api/v1/teacher/grading/courses/{$course}/sheet?year=".rawurlencode($this->year).($semester ? "&semester={$semester}" : '');
    }

    private function cell(User $u, int $course, string $student, string $field, mixed $value, array $extra = [])
    {
        return $this->api($u)->putJson("/api/v1/teacher/grading/courses/{$course}/cells/{$student}", $this->base() + ['field' => $field, 'value' => $value] + $extra);
    }

    private function fillAll(User $u, int $course, string $student, array $values = [10, 12, 14, 16, 18]): void
    {
        foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $i => $f) {
            $this->cell($u, $course, $student, $f, $values[$i])->assertOk();
        }
    }

    private function remise(int $course): ?object
    {
        return DB::table('t_remise_notes')->where('cours_id', $course)->first();
    }

    // ------------------------------------------------------------------ accès

    public function test_a_student_cannot_enter(): void
    {
        $this->api(User::factory()->create())->getJson('/api/v1/teacher/grading/courses')->assertForbidden();
    }

    public function test_an_anonymous_visitor_is_asked_to_log_in(): void
    {
        $this->getJson('/api/v1/teacher/grading/courses')->assertUnauthorized();
    }

    public function test_a_course_of_another_teacher_is_not_found_like_a_missing_one(): void
    {
        [$user] = $this->makeTeacher();
        [, $course] = $this->classroom();

        $own = $this->api($user)->getJson($this->sheetUrl($course))->assertNotFound()->assertJsonPath('code', 'grading.not_found');
        $missing = $this->api($user)->getJson($this->sheetUrl(999999));
        $this->assertSame($missing->json('code'), $own->json('code'));
        $this->cell($user, $course, 'E001', 'c1', 10)->assertNotFound();
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertNotFound();

        $this->assertSame(0, DB::table('t_remise_notes')->count());
        $this->assertSame(0, DB::table('t_audit_requete')->count());
    }

    public function test_registrariat_staff_can_open_any_sheet(): void
    {
        [, $course] = $this->classroom();

        $this->api(User::factory()->staff(3, 'registrar')->create())->getJson($this->sheetUrl($course))->assertOk();
    }

    // ------------------------------------------------------------------ liste

    public function test_the_course_list_shows_credits_ungraded_students_and_the_sheet_state(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $c1 = $this->makeCourse($uid, 'MAT 101', 'Analyse');
        $c2 = $this->makeCourse($uid, 'PHY 101', 'Mécanique');
        DB::table('t_2023_cours')->where('id', $c1)->update(['nb_crd' => 4]);
        $this->enrol($c1, ['E001' => ['A', 'a'], 'E002' => ['B', 'b']]);
        $this->enrol($c2, ['E001' => ['A', 'a']]);
        DB::table('t_2023_notes')->where('id_cours', (string) $c1)->where('student_id', 'E001')->update(['grade' => '14']);   // noté
        DB::table('t_2023_notes')->where('id_cours', (string) $c2)->update(['grade' => '-4']);                                // « pas encore noté »
        DB::table('t_remise_notes')->insert(['cours_id' => $c1, 'teacher_uid' => $uid, 'annee_scolaire' => $this->year, 'semestre' => 1, 'statut' => 'rejetee', 'motif_rejet' => 'Recompter le quiz', 'modifie_le' => '2026-11-01 10:00:00']);

        $res = $this->api($user)->getJson('/api/v1/teacher/grading/courses?year='.rawurlencode($this->year))->assertOk();

        $rows = collect($res->json('data.courses'))->keyBy('id');
        $this->assertSame([4, 2, 1, 'rejetee', 'Recompter le quiz'], [$rows[$c1]['credits'], $rows[$c1]['students'], $rows[$c1]['without_grade'], $rows[$c1]['status'], $rows[$c1]['reject_reason']]);
        $this->assertSame([1, 1, 'brouillon', null], [$rows[$c2]['students'], $rows[$c2]['without_grade'], $rows[$c2]['status'], $rows[$c2]['modified_at']]);
    }

    public function test_the_list_holds_only_my_courses_and_an_unlinked_teacher_gets_a_flag(): void
    {
        [$user] = $this->makeTeacher();
        [, $course] = $this->classroom();
        $stranger = User::factory()->staff(7, 'teacher')->create(['nom' => 'Inconnu', 'prenom' => 'Personne', 'mail' => 'p@exemple.test', 'teacher_uid' => null]);

        $this->assertSame([], $this->api($user)->getJson('/api/v1/teacher/grading/courses?year='.rawurlencode($this->year))->json('data.courses'));
        $this->api($stranger)->getJson('/api/v1/teacher/grading/courses')->assertOk()->assertJsonPath('data.linked', false)->assertJsonPath('data.courses', []);
        $this->assertNotNull($course);
    }

    // ---------------------------------------------------------------- lecture

    public function test_reading_an_unopened_sheet_shows_every_student_and_writes_nothing(): void
    {
        [$user, $course] = $this->classroom();

        $res = $this->api($user)->getJson($this->sheetUrl($course))->assertOk();

        $this->assertSame(['Andria', 'Bema', 'Rabe'], array_column($res->json('data.lines'), 'last_name'), 'triés par nom');
        $this->assertSame([null, 'brouillon', true], [$res->json('data.remise.id'), $res->json('data.remise.status'), $res->json('data.remise.editable')]);
        $this->assertSame([10, 20, 10, 25, 35], array_map('intval', array_column($res->json('data.components'), 'weight')));
        $this->assertSame([100, 'empty'], [(int) $res->json('data.total_weight'), $res->json('data.lines.0.note.state')]);
        $this->assertSame([0, 0, 0, 0], [DB::table('t_remise_notes')->count(), DB::table('t_remise_notes_ligne')->count(), DB::table('t_remise_notes_journal')->count(), DB::table('t_audit_requete')->count()]);
        $this->assertSame(0, $res->json('data.check.ready'));
        $this->assertSame([], $res->json('data.check.blockers'), '100 % de pondération, des étudiants : seul « aucune note » avertit');
    }

    public function test_suspended_and_withdrawn_students_are_not_on_the_sheet(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);
        $this->enrol($course, ['E001' => ['A', 'a'], 'E002' => ['B', 'b'], 'E003' => ['C', 'c']], 1, null, ['E002' => ['suspended' => 1], 'E003' => ['retrait_universite' => 1]]);

        $this->assertSame(['E001'], array_column($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'), 'student_id'));
    }

    public function test_the_sheet_carries_the_course_card_and_the_validation_course_flag(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid, 'RELP 291', 'Formation spirituelle');
        DB::table('t_2023_cours')->where('id', $course)->update(['nb_crd' => 2, 'yearlevel' => 2]);
        $this->enrol($course, ['E001' => ['A', 'a']]);
        $plain = $this->makeCourse($uid, 'INF 101', 'Algorithmique');
        $this->enrol($plain, ['E001' => ['A', 'a']]);

        $res = $this->api($user)->getJson($this->sheetUrl($course))->assertOk();

        $this->assertSame([2, 2, true, 'Andry Rasoa'], [$res->json('data.course.credits'), $res->json('data.course.level'), $res->json('data.course.validation_course'), $res->json('data.course.teacher')]);
        $this->assertFalse($this->api($user)->getJson($this->sheetUrl($plain))->json('data.course.validation_course'));
        DB::table('t_2023_cours')->where('id', $plain)->update(['category' => 5]);
        $this->assertTrue($this->api($user)->getJson($this->sheetUrl($plain))->json('data.course.validation_course'), 'catégorie 5');
    }

    public function test_students_who_exceed_fifteen_percent_absences_are_flagged_on_the_sheet(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $sheet = $this->openSheet($course, 10, 1, null, $uid);   // 1 absence permise
        foreach (['2026-09-07', '2026-09-14'] as $date) {
            $m = $this->makeMeeting($sheet, $date);
            $this->makeLine($m, 'E001', 'absent');
            $this->makeLine($m, 'E002', 'present');
        }

        $lines = collect($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'))->keyBy('student_id');

        $this->assertSame([true, false, false], [$lines['E001']['attendance_exceeded'], $lines['E002']['attendance_exceeded'], $lines['E003']['attendance_exceeded']]);
    }

    // ----------------------------------------------------------------- saisie

    public function test_the_first_write_opens_the_sheet_with_one_line_per_enrolled_student(): void
    {
        [$user, $course, $uid] = $this->classroom();

        $this->cell($user, $course, 'E002', 'c1', 12)->assertOk();

        $r = $this->remise($course);
        $this->assertSame(['brouillon', $uid, $user->id, $this->year, 1], [$r->statut, (int) $r->teacher_uid, (int) $r->cree_par, $r->annee_scolaire, (int) $r->semestre]);
        $this->assertStringStartsWith('INF 101', $r->sigle);
        $lines = DB::table('t_remise_notes_ligne')->where('remise_id', $r->id)->orderBy('student_id')->get();
        $this->assertSame(['E001', 'E002', 'E003'], $lines->pluck('student_id')->all());
        $this->assertSame(3, $lines->whereNotNull('note_id')->count(), 'chaque ligne vise sa note, cible de la validation');
        $this->assertSame([null, '12.00'], [$lines[0]->modifie_le, $lines[1]->quiz], 'seule la ligne saisie a une date de saisie');
        $this->assertNull($lines[0]->modifie_le);
        $this->assertSame(['creation', 'saisie'], DB::table('t_remise_notes_journal')->orderBy('id')->pluck('action')->all());
    }

    public function test_all_components_give_the_calculated_note_and_the_check_follows(): void
    {
        [$user, $course] = $this->classroom();

        $this->fillAll($user, $course, 'E001');
        $last = $this->cell($user, $course, 'E001', 'c5', 18)->assertOk();

        $this->assertSame([15.1, 'calculated'], [$last->json('data.note.value'), $last->json('data.note.state')]);
        $this->assertSame([1, 3], [$last->json('data.check.ready'), $last->json('data.check.total')]);
        $half = $this->cell($user, $course, 'E002', 'c1', 10)->assertOk();
        $this->assertSame(['incomplete', 'Manque : Recherche, Autres, Examen mi-sem., Examen final'], [$half->json('data.note.state'), $half->json('data.note.detail')]);
        $this->assertStringContainsString('1 étudiant(s) avec une composante pondérée non saisie', implode(' ', $half->json('data.check.blockers')));
    }

    public function test_a_grade_is_read_with_a_comma_and_rounded_to_two_decimals(): void
    {
        [$user, $course] = $this->classroom();

        $this->cell($user, $course, 'E001', 'c1', '12,5')->assertOk()->assertJsonPath('data.value', 12.5);
        $this->cell($user, $course, 'E001', 'c2', ' 7.345 ')->assertOk()->assertJsonPath('data.value', 7.35);
        $this->cell($user, $course, 'E001', 'c3', 0)->assertOk()->assertJsonPath('data.value', 0);
        $line = DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->first();
        $this->assertSame(['12.50', '7.35', '0.00'], [$line->quiz, $line->recherche, $line->autres]);
    }

    public function test_an_invalid_grade_is_refused_and_nothing_is_written(): void
    {
        [$user, $course] = $this->classroom();

        foreach (['21', '20.01', '-1', 'abc', '1e3', '12,5,5'] as $bad) {
            $this->cell($user, $course, 'E001', 'c1', $bad)->assertStatus(422)->assertJsonPath('code', 'grading.invalid_value');
        }
        $this->assertSame(0, DB::table('t_remise_notes')->count());
        $this->assertSame(0, DB::table('t_audit_requete')->count());
    }

    public function test_the_final_note_cannot_be_typed_and_unknown_fields_are_refused(): void
    {
        [$user, $course] = $this->classroom();

        foreach (['note_finale', 'note_forcee', 'student_id', 'modifie_le', 'quiz', 'c', 'c1; DROP TABLE x', 'c123456', 'c-1', ''] as $field) {
            $this->cell($user, $course, 'E001', $field, 15)->assertStatus(422);
        }
        $this->assertSame(0, DB::table('t_remise_notes_ligne')->count());
    }

    public function test_special_status_and_comment(): void
    {
        [$user, $course] = $this->classroom();

        $this->cell($user, $course, 'E001', 'statut_special', 'nn')->assertOk()->assertJsonPath('data.value', 'NN')->assertJsonPath('data.note.state', 'special');
        $this->cell($user, $course, 'E001', 'statut_special', 'X')->assertStatus(422)->assertJsonPath('code', 'grading.invalid_value');
        $this->cell($user, $course, 'E001', 'commentaire', str_repeat('é', 300))->assertOk();
        $this->assertSame(255, mb_strlen(DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('commentaire')));
        $this->cell($user, $course, 'E001', 'statut_special', '')->assertOk()->assertJsonPath('data.value', null);
        $this->assertNull(DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('statut_special'));
    }

    public function test_clearing_a_cell_and_rewriting_the_same_value(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 14)->assertOk();
        $before = DB::table('t_remise_notes_journal')->where('action', 'saisie')->count();

        $this->cell($user, $course, 'E001', 'c1', '14')->assertOk();   // même valeur
        $this->assertSame($before, DB::table('t_remise_notes_journal')->where('action', 'saisie')->count(), 'une valeur identique n’est pas journalisée');

        $this->cell($user, $course, 'E001', 'c1', '')->assertOk()->assertJsonPath('data.value', null);
        $this->assertNull(DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('quiz'));
        $j = DB::table('t_remise_notes_journal')->where('action', 'saisie')->orderByDesc('id')->first();
        $this->assertSame(['Quiz', '14.00', null, 'E001', $user->id], [$j->champ, $j->ancienne_valeur, $j->nouvelle_valeur, $j->student_id, (int) $j->auteur_id]);
    }

    public function test_the_journal_names_who_and_when(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 14)->assertOk();

        $j = DB::table('t_remise_notes_journal')->where('action', 'saisie')->first();

        $this->assertSame([trim($user->nom.' '.$user->prenom), 'teacher'], [$j->auteur_nom, $j->auteur_role]);
        $this->assertNotNull($j->date_action);
        $this->assertNotNull($j->ligne_id);
        $this->assertSame('14', $this->api($user)->getJson($this->sheetUrl($course))->json('data.journal.0.new'));
    }

    public function test_a_student_who_is_not_in_the_course_cannot_be_graded(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $other = $this->makeCourse($uid, 'AUT 101');
        $this->enrol($other, ['Z777' => ['Ailleurs', 'Lui']]);

        $this->cell($user, $course, 'Z777', 'c1', 10)->assertNotFound()->assertJsonPath('code', 'grading.student_not_listed');
        $this->cell($user, $course, 'NOBODY', 'c1', 10)->assertNotFound();
    }

    public function test_a_late_enrolled_student_gets_a_line_on_first_write(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        $this->enrol($course, ['E004' => ['Zafy', 'Mia']]);   // inscrit après l'ouverture de la feuille

        $sheet = $this->api($user)->getJson($this->sheetUrl($course))->json('data.lines');
        $this->assertContains('E004', array_column($sheet, 'student_id'), 'il apparaît sur la feuille sans attendre');
        $this->cell($user, $course, 'E004', 'c1', 11)->assertOk();
        $this->assertSame('11.00', DB::table('t_remise_notes_ligne')->where('student_id', 'E004')->value('quiz'));
    }

    public function test_a_comment_that_looks_like_a_number_is_still_a_change(): void
    {
        [$user, $course] = $this->classroom();

        $this->cell($user, $course, 'E001', 'commentaire', '12')->assertOk();
        $this->cell($user, $course, 'E001', 'commentaire', '12.0')->assertOk();

        $this->assertSame(['12', '12.0'], DB::table('t_remise_notes_journal')->where('champ', 'commentaire')->orderBy('id')->pluck('nouvelle_valeur')->all());
    }

    public function test_students_are_sorted_by_name_not_by_matricule(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);
        $this->enrol($course, ['E001' => ['Zafy', 'Mia'], 'E002' => ['Alpha', 'Zo'], 'E003' => ['Alpha', 'Ana']]);

        $ids = array_column($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'), 'student_id');

        $this->assertSame(['E003', 'E002', 'E001'], $ids, 'nom, puis prénom, puis matricule');
        $this->cell($user, $course, 'E001', 'c1', 5)->assertOk();
        $this->assertSame($ids, array_column($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'), 'student_id'), 'même ordre une fois la feuille ouverte');
    }

    public function test_a_frozen_sheet_does_not_gain_late_enrolled_students(): void
    {
        [$user, $course] = $this->classroom();
        foreach (['E001', 'E002', 'E003'] as $s) {
            $this->fillAll($user, $course, $s);
        }
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();
        $this->enrol($course, ['E004' => ['Zafy', 'Mia']]);

        $ids = array_column($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'), 'student_id');

        $this->assertSame(['E001', 'E002', 'E003'], $ids, 'une feuille transmise ne bouge plus');
    }

    public function test_responses_are_never_cached(): void
    {
        [$user, $course] = $this->classroom();

        $this->assertStringContainsString('no-store', (string) $this->api($user)->getJson($this->sheetUrl($course))->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->api($user)->getJson('/api/v1/teacher/grading/courses')->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->cell($user, $course, 'E001', 'c1', 5)->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------- colonnes et pondération

    private function components(User $u, int $course, array $items, array $extra = [])
    {
        return $this->api($u)->putJson("/api/v1/teacher/grading/courses/{$course}/components", $this->base() + ['components' => $items] + $extra);
    }

    /** Les cinq colonnes par défaut, avec une pondération donnée (titres inchangés). @return list<array{slot: int, label: string, weight: mixed}> */
    private function five(array $weights): array
    {
        $labels = ['Quiz', 'Recherche', 'Autres', 'Examen mi-sem.', 'Examen final'];

        return array_map(fn ($i) => ['slot' => $i + 1, 'label' => $labels[$i], 'weight' => $weights[$i]], range(0, 4));
    }

    public function test_a_new_sheet_proposes_the_five_default_columns(): void
    {
        [$user, $course] = $this->classroom();

        $res = $this->api($user)->getJson($this->sheetUrl($course))->assertOk();

        $this->assertSame([[1, 'Quiz', 10], [2, 'Recherche', 20], [3, 'Autres', 10], [4, 'Examen mi-sem.', 25], [5, 'Examen final', 35]], array_map(fn ($c) => [$c['slot'], $c['label'], $c['weight']], $res->json('data.components')));
        $this->assertSame(['max_components' => 12, 'max_label' => 40], $res->json('data.limits'));
    }

    public function test_the_teacher_chooses_the_number_titles_and_weights_of_the_columns(): void
    {
        [$user, $course] = $this->classroom();

        $res = $this->components($user, $course, [
            ['label' => 'Devoir 1', 'weight' => '20'], ['label' => 'Devoir 2', 'weight' => '20'], ['label' => 'Projet', 'weight' => '10'], ['label' => 'TP', 'weight' => '10'],
            ['label' => 'Oral', 'weight' => '10'], ['label' => 'Partiel', 'weight' => '10'], ['label' => 'Examen final', 'weight' => '20'],
        ])->assertOk();

        $this->assertSame(['Devoir 1', 'Devoir 2', 'Projet', 'TP', 'Oral', 'Partiel', 'Examen final'], array_column($res->json('data.components'), 'label'));
        $this->assertEquals(100, $res->json('data.total_weight'));
        $this->assertSame([], $res->json('data.check.blockers'));
        $sheet = $this->api($user)->getJson($this->sheetUrl($course))->json('data.components');
        $this->assertCount(7, $sheet);
        $slots = array_column($sheet, 'slot');
        $this->assertCount(7, array_unique($slots), 'chaque colonne a sa propre identité');
        $this->assertSame([], array_filter($slots, fn ($x) => $x <= 1000), 'les colonnes nouvelles reçoivent des identités neuves, distinctes des cinq de l’ancienne feuille');
    }

    public function test_notes_are_entered_in_custom_columns_and_the_note_follows_the_chosen_weights(): void
    {
        [$user, $course] = $this->classroom();
        $res = $this->components($user, $course, [['label' => 'Devoir', 'weight' => 40], ['label' => 'Examen', 'weight' => 60]])->assertOk();
        [$devoir, $examen] = array_column($res->json('data.components'), 'slot');

        $this->cell($user, $course, 'E001', "c{$devoir}", 10)->assertOk();
        $last = $this->cell($user, $course, 'E001', "c{$examen}", 15)->assertOk();

        $this->assertEquals([13, 'calculated'], [$last->json('data.note.value'), $last->json('data.note.state')]);   // 10×0,4 + 15×0,6
        $half = $this->cell($user, $course, 'E002', "c{$devoir}", 8)->assertOk();
        $this->assertSame('Manque : Examen', $half->json('data.note.detail'));
        $line = collect($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'))->keyBy('student_id');
        $this->assertEquals([10, 15], [$line['E001']['values'][(string) $devoir], $line['E001']['values'][(string) $examen]]);
    }

    public function test_a_single_column_worth_a_hundred_percent_is_enough(): void
    {
        [$user, $course] = $this->classroom();
        $res = $this->components($user, $course, [['label' => 'Note unique', 'weight' => 100]])->assertOk();
        $slot = $res->json('data.components.0.slot');

        $this->assertEquals(14.5, $this->cell($user, $course, 'E001', "c{$slot}", '14,5')->assertOk()->json('data.note.value'));
    }

    public function test_the_total_must_be_one_hundred_to_submit_but_not_to_save(): void
    {
        [$user, $course] = $this->classroom();

        $res = $this->components($user, $course, [['label' => 'A', 'weight' => 30], ['label' => 'B', 'weight' => 30]])->assertOk();

        $this->assertStringContainsString('60 %', $res->json('data.check.blockers.0'));
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertStatus(422)->assertJsonPath('code', 'grading.not_ready');
    }

    public function test_removing_a_column_removes_its_notes_and_says_so_in_the_journal(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        $this->cell($user, $course, 'E002', 'c1', 12)->assertOk();
        $this->cell($user, $course, 'E001', 'c2', 11)->assertOk();

        $res = $this->components($user, $course, [['slot' => 2, 'label' => 'Recherche', 'weight' => 100]])->assertOk();

        $this->assertSame([2], array_column($res->json('data.components'), 'slot'));
        $this->assertSame(1, DB::table('t_remise_notes_valeur')->count(), 'les notes des colonnes supprimées partent avec elles');
        $j = DB::table('t_remise_notes_journal')->where('action', 'ponderation')->where('champ', 'Quiz')->orderByDesc('id')->first();
        $this->assertSame(['Quiz (10 %) — 2 note(s) supprimée(s)', null], [$j->ancienne_valeur, $j->nouvelle_valeur]);
    }

    public function test_renaming_reweighting_and_reordering_keep_the_notes(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        $this->cell($user, $course, 'E001', 'c5', 18)->assertOk();

        $res = $this->components($user, $course, [['slot' => 5, 'label' => 'Final', 'weight' => 60], ['slot' => 1, 'label' => 'Contrôle continu', 'weight' => 40]])->assertOk();

        $this->assertSame([[5, 'Final'], [1, 'Contrôle continu']], array_map(fn ($c) => [$c['slot'], $c['label']], $res->json('data.components')));
        $this->assertEquals(14.8, $res->json('data.notes.E001.value'));   // 18×0,6 + 10×0,4
        $this->assertEquals([10, 18], [DB::table('t_remise_notes_valeur')->where('slot', 1)->value('valeur'), DB::table('t_remise_notes_valeur')->where('slot', 5)->value('valeur')]);
    }

    public function test_a_column_removed_then_added_again_never_inherits_the_old_slot(): void
    {
        [$user, $course] = $this->classroom();
        $first = $this->components($user, $course, [['slot' => 1, 'label' => 'Quiz', 'weight' => 50], ['label' => 'Extra', 'weight' => 50]])->assertOk()->json('data.components.1.slot');
        $this->cell($user, $course, 'E001', "c{$first}", 9)->assertOk();
        $this->components($user, $course, [['slot' => 1, 'label' => 'Quiz', 'weight' => 100]])->assertOk();

        $second = $this->components($user, $course, [['slot' => 1, 'label' => 'Quiz', 'weight' => 50], ['label' => 'Extra', 'weight' => 50]])->assertOk()->json('data.components.1.slot');

        $this->assertNotSame($first, $second, 'une saisie en retard visant l’ancienne colonne ne doit pas tomber dans la nouvelle');
        $this->cell($user, $course, 'E001', "c{$first}", 5)->assertStatus(422);
    }

    public function test_invalid_column_lists_are_refused_and_change_nothing(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        $before = DB::table('t_remise_notes_composante')->count();

        $this->components($user, $course, [])->assertStatus(422);
        $this->components($user, $course, array_map(fn ($i) => ['label' => "C$i", 'weight' => 5], range(1, 13)))->assertStatus(422);                       // plus de 12
        $this->components($user, $course, [['label' => '   ', 'weight' => 100]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->components($user, $course, [['label' => str_repeat('é', 41), 'weight' => 100]])->assertStatus(422)->assertJsonPath('code', 'grading.invalid_components');
        $this->components($user, $course, [['label' => 'Test', 'weight' => 50], ['label' => ' test ', 'weight' => 50]])->assertStatus(422)->assertJsonPath('code', 'grading.invalid_components');
        $this->components($user, $course, [['slot' => 999, 'label' => 'Fantôme', 'weight' => 100]])->assertStatus(422)->assertJsonPath('code', 'grading.invalid_components');
        $this->components($user, $course, [['slot' => 1, 'label' => 'A', 'weight' => 50], ['slot' => 1, 'label' => 'B', 'weight' => 50]])->assertStatus(422);
        $this->components($user, $course, [['label' => 'Sans poids']])->assertStatus(422);

        $this->assertSame($before, DB::table('t_remise_notes_composante')->count());
    }

    public function test_the_maximum_of_twelve_columns_is_allowed_and_a_weight_is_bounded(): void
    {
        [$user, $course] = $this->classroom();

        $res = $this->components($user, $course, array_map(fn ($i) => ['label' => "Colonne $i", 'weight' => $i === 1 ? '150' : ($i === 2 ? 'abc' : '12,345')], range(1, 12)))->assertOk();

        $this->assertCount(12, $res->json('data.components'));
        $this->assertEquals([100, 0, 12.35], [$res->json('data.components.0.weight'), $res->json('data.components.1.weight'), $res->json('data.components.2.weight')]);
    }

    public function test_the_five_first_columns_are_copied_to_the_historical_columns_for_the_legacy_validation(): void
    {
        [$user, $course] = $this->classroom();
        $res = $this->components($user, $course, [['label' => 'Devoir', 'weight' => 40], ['label' => 'Examen', 'weight' => 60]])->assertOk();
        [$a, $b] = array_column($res->json('data.components'), 'slot');
        $this->cell($user, $course, 'E001', "c{$a}", 10)->assertOk();
        $this->cell($user, $course, 'E001', "c{$b}", 15)->assertOk();

        $r = DB::table('t_remise_notes')->first();
        $l = DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->first();

        $this->assertSame([40.0, 60.0, 0.0, 0.0, 0.0], [(float) $r->poids_quiz, (float) $r->poids_recherche, (float) $r->poids_autres, (float) $r->poids_mi_semestre, (float) $r->poids_final]);
        $this->assertSame(['10.00', '15.00', null, null, null], [$l->quiz, $l->recherche, $l->autres, $l->mi_semestre, $l->examen_final]);
        // L'ancienne validation recalculerait la même note : 10×0,4 + 15×0,6 = 13.
        $legacy = \App\Domain\Grading\GradingRules::computeNote((array) $l, ['poids_quiz' => $r->poids_quiz, 'poids_recherche' => $r->poids_recherche, 'poids_autres' => $r->poids_autres, 'poids_mi_semestre' => $r->poids_mi_semestre, 'poids_final' => $r->poids_final]);
        $this->assertEquals(13, $legacy['value']);
    }

    public function test_beyond_five_columns_the_legacy_copy_cannot_reach_a_hundred_so_the_old_validation_blocks(): void
    {
        [$user, $course] = $this->classroom();
        $this->components($user, $course, array_map(fn ($i) => ['label' => "C$i", 'weight' => $i <= 3 ? 20 : 10], range(1, 7)))->assertOk();   // 3×20 + 4×10 = 100

        $r = DB::table('t_remise_notes')->first();
        $copy = (float) $r->poids_quiz + $r->poids_recherche + $r->poids_autres + $r->poids_mi_semestre + $r->poids_final;

        $this->assertSame(80.0, round($copy, 2), 'seules les cinq premières colonnes sont recopiées : l’ancienne validation voit 80 % et bloque');
    }

    public function test_legacy_values_already_in_the_old_columns_are_kept_when_the_sheet_is_first_edited_here(): void
    {
        [$user, $course, $uid] = $this->classroom();
        $remise = DB::table('t_remise_notes')->insertGetId(['cours_id' => $course, 'teacher_uid' => $uid, 'annee_scolaire' => $this->year, 'semestre' => 1, 'statut' => 'brouillon', 'poids_quiz' => 50, 'poids_recherche' => 50, 'poids_autres' => 0, 'poids_mi_semestre' => 0, 'poids_final' => 0]);
        DB::table('t_remise_notes_ligne')->insert(['remise_id' => $remise, 'student_id' => 'E001', 'quiz' => 12, 'recherche' => 14]);

        $before = collect($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'))->keyBy('student_id')['E001'];
        $this->cell($user, $course, 'E002', 'c1', 5)->assertOk();   // première écriture ici : la structure passe dans les nouvelles tables
        $after = collect($this->api($user)->getJson($this->sheetUrl($course))->json('data.lines'))->keyBy('student_id')['E001'];

        $this->assertEquals([12, 14], [$before['values']['1'], $before['values']['2']]);
        $this->assertEquals([13, 'calculated'], [$after['note']['value'], $after['note']['state']]);
        $this->assertSame(5, DB::table('t_remise_notes_composante')->where('remise_id', $remise)->count());
    }

    public function test_changing_the_columns_of_a_submitted_sheet_is_refused(): void
    {
        [$user, $course] = $this->classroom();
        foreach (['E001', 'E002', 'E003'] as $s) {
            $this->fillAll($user, $course, $s);
        }
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();

        $this->components($user, $course, [['label' => 'Tout', 'weight' => 100]])->assertStatus(409)->assertJsonPath('code', 'grading.frozen');
        $this->assertSame(5, DB::table('t_remise_notes_composante')->count());
    }

    public function test_the_domain_itself_bounds_the_number_of_columns(): void
    {
        [$user, $course] = $this->classroom();
        $grading = app(\App\Domain\Teacher\TeacherGrading::class);
        $try = function (array $items) use ($grading, $user, $course) {
            try {
                $grading->setComponents($user, $course, $this->year, 1, $items, null, false);
                $this->fail('liste refusée attendue');
            } catch (\App\Exceptions\ApiProblem $e) {
                return $e->problemCode;
            }
        };

        $this->assertSame('grading.invalid_components', $try([]));
        $this->assertSame('grading.invalid_components', $try(array_map(fn ($i) => ['label' => "C$i", 'weight' => 5], range(1, 13))));
        $this->assertSame(0, DB::table('t_remise_notes_composante')->count());
    }

    public function test_a_new_column_gets_an_identity_far_from_the_five_old_ones(): void
    {
        [$user, $course] = $this->classroom();

        $this->components($user, $course, [['slot' => 1, 'label' => 'Quiz', 'weight' => 50], ['label' => 'Neuve', 'weight' => 50]])->assertOk();

        $row = DB::table('t_remise_notes_composante')->where('libelle', 'Neuve')->first();
        $this->assertSame(1000 + (int) $row->id, (int) $row->slot);
    }

    public function test_submitting_refreshes_the_historical_copy_the_old_validation_reads(): void
    {
        [$user, $course] = $this->classroom();
        foreach (['E001', 'E002', 'E003'] as $s) {
            $this->fillAll($user, $course, $s);
        }
        DB::table('t_remise_notes')->update(['poids_quiz' => 0]);   // un autre outil a déréglé la copie
        DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->update(['quiz' => null]);

        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();

        $this->assertSame([10.0, '10.00'], [(float) DB::table('t_remise_notes')->value('poids_quiz'), DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('quiz')]);
    }

    public function test_a_cell_in_a_column_that_does_not_exist_is_refused(): void
    {
        [$user, $course] = $this->classroom();

        $this->cell($user, $course, 'E001', 'c42', 10)->assertStatus(422)->assertJsonPath('code', 'grading.unknown_field');
    }

    // ------------------------------------------------------------ transmission

    public function test_a_sheet_cannot_be_submitted_while_something_blocks(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();   // incomplet
        $url = "/api/v1/teacher/grading/courses/{$course}/submit";

        $res = $this->api($user)->postJson($url, $this->base())->assertStatus(422)->assertJsonPath('code', 'grading.not_ready');

        $this->assertNotEmpty($res->json('check.blockers'));
        $this->assertSame('brouillon', $this->remise($course)->statut);
        $this->components($user, $course, $this->five([10, 20, 10, 25, 20]))->assertOk();
        $this->assertStringContainsString('85 %', implode(' ', $this->api($user)->postJson($url, $this->base())->json('check.blockers')));
    }

    public function test_a_complete_sheet_is_submitted_and_freezes(): void
    {
        [$user, $course] = $this->classroom();
        foreach (['E001', 'E002'] as $s) {
            $this->fillAll($user, $course, $s);
        }
        $this->cell($user, $course, 'E003', 'statut_special', 'NN')->assertOk();
        $url = "/api/v1/teacher/grading/courses/{$course}/submit";
        DB::table('t_remise_notes')->update(['motif_rejet' => 'Ancien motif']);

        $res = $this->api($user)->postJson($url, $this->base())->assertOk();

        $this->assertSame(['soumise', 3, 3], [$res->json('data.status'), $res->json('data.check.ready'), $res->json('data.check.total')]);
        $r = $this->remise($course);
        $this->assertSame(['soumise', $user->id, null], [$r->statut, (int) $r->soumis_par, $r->motif_rejet]);
        $this->assertNotNull($r->soumis_le);
        $j = DB::table('t_remise_notes_journal')->where('action', 'soumission')->first();
        $this->assertSame(['statut', 'brouillon', 'soumise'], [$j->champ, $j->ancienne_valeur, $j->nouvelle_valeur]);
        $this->assertFalse($this->api($user)->getJson($this->sheetUrl($course))->json('data.remise.editable'));
        // Rien n'a touché aux notes des étudiants : seule la validation du registrariat le fera.
        $this->assertSame(0, DB::table('t_2023_notes')->whereNotNull('grade')->where('grade', '!=', '')->count());
    }

    public function test_a_submitted_sheet_is_read_only_and_cannot_be_submitted_twice(): void
    {
        [$user, $course] = $this->classroom();
        foreach (['E001', 'E002', 'E003'] as $s) {
            $this->fillAll($user, $course, $s);
        }
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();

        $this->cell($user, $course, 'E001', 'c1', 1)->assertStatus(409)->assertJsonPath('code', 'grading.frozen');
        $this->cell($user, $course, 'E001', 'c1', 1, ['force' => true])->assertStatus(409)->assertJsonPath('code', 'grading.frozen');
        $this->components($user, $course, $this->five([1, 20, 10, 25, 44]))->assertStatus(409)->assertJsonPath('code', 'grading.frozen');
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertStatus(409)->assertJsonPath('code', 'grading.already_submitted');
        $this->assertSame('10.00', DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('quiz'));
    }

    public function test_a_returned_sheet_can_be_corrected_and_submitted_again(): void
    {
        [$user, $course] = $this->classroom();
        foreach (['E001', 'E002', 'E003'] as $s) {
            $this->fillAll($user, $course, $s);
        }
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();
        DB::table('t_remise_notes')->update(['statut' => 'rejetee', 'motif_rejet' => 'Corriger le quiz de E001', 'traite_le' => '2026-11-10 09:00:00']);   // le registrariat la renvoie

        $sheet = $this->api($user)->getJson($this->sheetUrl($course))->assertOk();
        $this->assertSame([true, 'Corriger le quiz de E001'], [$sheet->json('data.remise.editable'), $sheet->json('data.remise.reject_reason')]);
        $this->cell($user, $course, 'E001', 'c1', 11)->assertOk();
        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();

        $this->assertSame(['soumise', null], [$this->remise($course)->statut, $this->remise($course)->motif_rejet]);
    }

    public function test_an_all_blank_sheet_may_be_submitted_with_a_warning(): void
    {
        [$user, $course] = $this->classroom();

        $res = $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertOk();

        $this->assertSame([], $res->json('data.check.blockers'));
        $this->assertStringContainsString('3 étudiant(s) sans aucune note', $res->json('data.check.warnings.0'));
    }

    public function test_an_empty_class_cannot_be_submitted(): void
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);

        $this->api($user)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertStatus(422)->assertJsonPath('check.blockers.0', 'Aucun étudiant inscrit à ce cours pour cette année et ce semestre.');
    }

    public function test_another_teacher_cannot_touch_my_sheet(): void
    {
        [$user, $course] = $this->classroom();
        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        [$intruder] = $this->makeTeacher('Naina', 'Rabe');

        $this->cell($intruder, $course, 'E001', 'c1', 1)->assertNotFound();
        $this->api($intruder)->postJson("/api/v1/teacher/grading/courses/{$course}/submit", $this->base())->assertNotFound();
        $this->api($intruder)->getJson($this->sheetUrl($course))->assertNotFound();
        $this->assertSame('10.00', DB::table('t_remise_notes_ligne')->where('student_id', 'E001')->value('quiz'));
    }

    public function test_one_sheet_per_course_year_and_semester(): void
    {
        [$user, $course] = $this->classroom();
        $this->enrol($course, ['E009' => ['Autre', 'Semestre']], 2);

        $this->cell($user, $course, 'E001', 'c1', 10)->assertOk();
        $this->cell($user, $course, 'E001', 'c2', 11)->assertOk();
        $this->api($user)->putJson("/api/v1/teacher/grading/courses/{$course}/cells/E009", ['year' => $this->year, 'semester' => 2, 'field' => 'c1', 'value' => 9])->assertOk();

        $this->assertSame(2, DB::table('t_remise_notes')->where('cours_id', $course)->count());
        $this->assertSame(['E001', 'E002', 'E003'], array_column($this->api($user)->getJson($this->sheetUrl($course, 1))->json('data.lines'), 'student_id'));
        $this->assertSame(['E009'], array_column($this->api($user)->getJson($this->sheetUrl($course, 2))->json('data.lines'), 'student_id'));
    }
}
