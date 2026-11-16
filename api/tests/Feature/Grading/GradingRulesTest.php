<?php

namespace Tests\Feature\Grading;

use App\Domain\Grading\GradingRules as R;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Règles de la remise de notes : calcul de la note, code stocké, contrôle avant transmission, lecture des saisies. */
class GradingRulesTest extends TestCase
{
    private function weights(array $over = []): array
    {
        return $over + R::DEFAULT_WEIGHTS;
    }

    private function line(array $over = []): array
    {
        return $over + ['quiz' => null, 'recherche' => null, 'autres' => null, 'mi_semestre' => null, 'examen_final' => null, 'statut_special' => null, 'student_id' => 'E1', 'last_name' => 'Rabe', 'first_name' => 'Zo'];
    }

    private function full(array $over = []): array
    {
        return $over + $this->line(['quiz' => 10, 'recherche' => 12, 'autres' => 14, 'mi_semestre' => 16, 'examen_final' => 18]);
    }

    public function test_the_default_weights_are_those_of_the_table_columns(): void
    {
        $db = [];
        foreach (DB::select('SHOW COLUMNS FROM t_remise_notes') as $c) {
            if (str_starts_with($c->Field, 'poids_')) {
                $db[$c->Field] = (float) $c->Default;
            }
        }

        $this->assertSame($db, R::DEFAULT_WEIGHTS);
        $this->assertSame(100.0, R::totalWeight(R::DEFAULT_WEIGHTS));
    }

    public function test_the_note_is_the_weighted_average_of_the_components(): void
    {
        $calc = R::computeNote($this->full(), $this->weights());

        // (10×10 + 12×20 + 14×10 + 16×25 + 18×35) / 100 = 15,1
        $this->assertSame([15.1, 'calculated'], [$calc['value'], $calc['state']]);
    }

    public function test_a_component_without_weight_is_ignored_and_need_not_be_entered(): void
    {
        $w = $this->weights(['poids_quiz' => 0, 'poids_recherche' => 0, 'poids_autres' => 0, 'poids_mi_semestre' => 50, 'poids_final' => 50]);

        $calc = R::computeNote($this->line(['mi_semestre' => 10, 'examen_final' => 15]), $w);

        $this->assertSame([12.5, 'calculated'], [$calc['value'], $calc['state']]);
    }

    public function test_a_weighted_component_left_empty_makes_the_line_incomplete_never_a_prorata(): void
    {
        $calc = R::computeNote($this->full(['quiz' => null, 'examen_final' => '']), $this->weights());

        $this->assertSame([null, 'incomplete', 'Manque : Quiz, Examen final'], [$calc['value'], $calc['state'], $calc['detail']]);
    }

    public function test_the_note_is_rounded_to_two_decimals_from_the_weights_not_from_a_fixed_hundred(): void
    {
        $w = ['poids_quiz' => 1, 'poids_recherche' => 1, 'poids_autres' => 1, 'poids_mi_semestre' => 0, 'poids_final' => 0];

        $this->assertSame(10.33, R::computeNote($this->line(['quiz' => 10, 'recherche' => 10, 'autres' => 11]), $w)['value'], '31 / 3 = 10,3333…');
        $this->assertSame(10.67, R::computeNote($this->line(['quiz' => 10, 'recherche' => 11, 'autres' => 11]), $w)['value']);
    }

    public function test_a_note_above_twenty_blocks_even_just_above(): void
    {
        $r = R::check([$this->full(['student_id' => 'A', 'last_name' => 'Big', 'quiz' => 20.5, 'recherche' => 20.5, 'autres' => 20.5, 'mi_semestre' => 20.5, 'examen_final' => 20.5])], $this->weights());

        $this->assertSame('Note hors barème 0-20 : Big Zo (20.5).', $r['blockers'][0]);
        $this->assertSame(0, $r['ready']);
        $this->assertSame(1, R::check([$this->full(['quiz' => 20, 'recherche' => 20, 'autres' => 20, 'mi_semestre' => 20, 'examen_final' => 20])], $this->weights())['ready'], '20 pile est permis');
    }

    /** @return list<array{slot: int, label: string, weight: float}> */
    private function custom(): array
    {
        return [['slot' => 1007, 'label' => 'Devoir', 'weight' => 30.0], ['slot' => 3, 'label' => 'Projet', 'weight' => 20.0], ['slot' => 1001, 'label' => 'Examen', 'weight' => 50.0]];
    }

    public function test_free_columns_are_averaged_by_their_own_weights(): void
    {
        $calc = R::computeNoteFor([1007 => 10, 3 => 15, 1001 => 12], null, $this->custom());

        $this->assertSame([12.0, 'calculated'], [$calc['value'], $calc['state']]);   // 10×0,3 + 15×0,2 + 12×0,5 = 3 + 3 + 6
    }

    public function test_free_columns_report_the_missing_ones_by_title(): void
    {
        $calc = R::computeNoteFor([1007 => 10], null, $this->custom());

        $this->assertSame(['incomplete', 'Manque : Projet, Examen'], [$calc['state'], $calc['detail']]);
        $this->assertSame('empty', R::computeNoteFor([], null, $this->custom())['state']);
        $this->assertSame('special', R::computeNoteFor([1007 => 10, 3 => 15, 1001 => 12], 'V', $this->custom())['state']);
    }

    public function test_a_value_of_a_column_that_no_longer_exists_is_ignored(): void
    {
        $calc = R::computeNoteFor([1007 => 10, 3 => 10, 1001 => 10, 999 => 20], null, $this->custom());

        $this->assertSame(10.0, $calc['value']);
    }

    public function test_free_columns_work_with_a_single_column_or_twelve(): void
    {
        $this->assertSame(14.5, R::computeNoteFor([1 => 14.5], null, [['slot' => 1, 'label' => 'Unique', 'weight' => 100.0]])['value']);
        $twelve = array_map(fn ($i) => ['slot' => $i, 'label' => "C$i", 'weight' => $i <= 4 ? 9.0 : 8.0], range(1, 12));   // 4×9 + 8×8 = 100
        $this->assertSame(100.0, R::totalWeightOf($twelve));
        $this->assertSame(10.0, R::computeNoteFor(array_fill(1, 12, 10), null, $twelve)['value']);
    }

    public function test_the_check_on_free_columns_uses_their_titles_and_total(): void
    {
        $lines = [['student_id' => 'A', 'last_name' => 'Ok', 'first_name' => '', 'values' => [1007 => 10, 3 => 15, 1001 => 12]], ['student_id' => 'B', 'last_name' => 'Demi', 'first_name' => '', 'values' => [1007 => 10]]];

        $r = R::checkFor($lines, $this->custom());

        $this->assertSame([1, 2], [$r['ready'], $r['total']]);
        $this->assertSame(['1 étudiant(s) avec une composante pondérée non saisie : Demi.'], $r['blockers']);
        $short = $this->custom();
        $short[2]['weight'] = 40.0;
        $this->assertSame('La pondération totalise 90 % au lieu de 100 %.', R::checkFor($lines, $short)['blockers'][0]);
    }

    public function test_a_column_title_is_cleaned_and_bounded(): void
    {
        $this->assertSame(['Examen final', 'Tp 1', null, null, str_repeat('é', 40), null], [R::cleanLabel('  Examen   final '), R::cleanLabel("Tp\n\t1"), R::cleanLabel(''), R::cleanLabel(" \t "), R::cleanLabel(str_repeat('é', 40)), R::cleanLabel(str_repeat('é', 41))]);
    }

    public function test_the_legacy_bridge_gives_the_five_old_columns_as_slots_one_to_five(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], array_column(R::legacyComponents(R::DEFAULT_WEIGHTS), 'slot'));
        $this->assertSame(['Quiz', 'Recherche', 'Autres', 'Examen mi-sem.', 'Examen final'], array_column(R::legacyComponents(R::DEFAULT_WEIGHTS), 'label'));
        $this->assertSame([1 => 10, 2 => null, 3 => null, 4 => null, 5 => 18], R::legacyValues(['quiz' => 10, 'examen_final' => 18]));
    }

    public function test_zero_is_a_grade_not_a_missing_value(): void
    {
        $this->assertSame(0.0, R::computeNote($this->full(['quiz' => 0, 'recherche' => '0', 'autres' => 0.0, 'mi_semestre' => '0.00', 'examen_final' => 0]), $this->weights())['value']);
    }

    public function test_nothing_entered_or_no_weight_is_empty(): void
    {
        $this->assertSame(['empty', 'Aucune note saisie'], [R::computeNote($this->line(), $this->weights())['state'], R::computeNote($this->line(), $this->weights())['detail']]);
        $zero = array_fill_keys(array_keys(R::DEFAULT_WEIGHTS), 0);
        $this->assertSame(['empty', 'Aucune pondération définie'], [R::computeNote($this->full(), $zero)['state'], R::computeNote($this->full(), $zero)['detail']]);
    }

    public function test_a_special_status_wins_over_the_components(): void
    {
        foreach (['V', 'E', 'NN', ' v ', 'nn'] as $code) {
            $calc = R::computeNote($this->full(['statut_special' => $code]), $this->weights());
            $this->assertSame(['special', null, strtoupper(trim($code))], [$calc['state'], $calc['value'], $calc['detail']], $code);
        }
        $this->assertSame('calculated', R::computeNote($this->full(['statut_special' => '  ']), $this->weights())['state'], 'des espaces ne sont pas un statut');
    }

    public function test_the_stored_grade_code(): void
    {
        $w = $this->weights();

        $this->assertSame('15.1', R::gradeFor($this->full(), $w));
        $this->assertSame('14', R::gradeFor($this->full(['quiz' => 14, 'recherche' => 14, 'autres' => 14, 'mi_semestre' => 14, 'examen_final' => 14]), $w), '14.0 s’écrit « 14 »');
        $this->assertSame(['-2', '-3', '-4'], [R::gradeFor($this->line(['statut_special' => 'V']), $w), R::gradeFor($this->line(['statut_special' => 'E']), $w), R::gradeFor($this->line(['statut_special' => 'NN']), $w)]);
        $this->assertNull(R::gradeFor($this->line(['statut_special' => 'X']), $w));
        $this->assertNull(R::gradeFor($this->full(['quiz' => null]), $w), 'une ligne incomplète n’est pas transférable');
        $this->assertNull(R::gradeFor($this->line(), $w));
    }

    public function test_a_validation_course_is_never_written_in_digits(): void
    {
        $w = $this->weights();
        $at = fn (float $v) => $this->line(['quiz' => $v, 'recherche' => $v, 'autres' => $v, 'mi_semestre' => $v, 'examen_final' => $v]);

        $this->assertSame(['-3', '-2', '-2'], [R::gradeFor($at(9.99), $w, true), R::gradeFor($at(10), $w, true), R::gradeFor($at(20), $w, true)]);
    }

    public function test_the_total_weight_is_rounded(): void
    {
        $this->assertSame(100.0, R::totalWeight(['poids_quiz' => 33.33, 'poids_recherche' => 33.33, 'poids_autres' => 33.34]));
        $this->assertSame(0.0, R::totalWeight([]));
    }

    public function test_the_check_blocks_a_wrong_total_and_names_it_with_a_french_comma(): void
    {
        $r = R::check([$this->full()], $this->weights(['poids_final' => 34.5]));

        $this->assertSame(['La pondération totalise 99,5 % au lieu de 100 %.'], $r['blockers']);
        $r = R::check([$this->full()], $this->weights(['poids_final' => 25]));
        $this->assertSame('La pondération totalise 90 % au lieu de 100 %.', $r['blockers'][0]);
        $this->assertSame([], R::check([$this->full()], $this->weights())['blockers'], '100 % pile : rien à signaler');
        $this->assertSame([], R::check([$this->full()], $this->weights(['poids_final' => 35.004]))['blockers'], 'un total de 100,004 s’arrondit à 100');
    }

    public function test_the_check_blocks_an_empty_class(): void
    {
        $this->assertSame(['Aucun étudiant inscrit à ce cours pour cette année et ce semestre.'], R::check([], $this->weights())['blockers']);
    }

    public function test_the_check_counts_ready_lines_and_lists_what_blocks(): void
    {
        $lines = [
            $this->full(['student_id' => 'A', 'last_name' => 'Ready']),
            $this->line(['student_id' => 'B', 'last_name' => 'Special', 'statut_special' => 'V']),
            $this->line(['student_id' => 'C', 'last_name' => 'Half', 'quiz' => 10]),
            $this->line(['student_id' => 'D', 'last_name' => 'Nothing']),
            $this->full(['student_id' => 'E', 'last_name' => 'Big', 'quiz' => 25, 'recherche' => 25, 'autres' => 25, 'mi_semestre' => 25, 'examen_final' => 25]),
        ];

        $r = R::check($lines, $this->weights());

        $this->assertSame([2, 5], [$r['ready'], $r['total']]);
        $this->assertSame('Note hors barème 0-20 : Big Zo (25).', $r['blockers'][0]);
        $this->assertStringContainsString('1 étudiant(s) avec une composante pondérée non saisie : Half Zo.', $r['blockers'][1]);
        $this->assertSame(['1 étudiant(s) sans aucune note : Nothing Zo. Ils seront enregistrés « pas encore noté » (NN).'], $r['warnings']);
    }

    public function test_long_lists_are_shortened(): void
    {
        $lines = array_map(fn ($i) => $this->line(['student_id' => "S$i", 'last_name' => "N$i", 'first_name' => '', 'quiz' => 1]), range(1, 8));

        $this->assertStringContainsString('N1, N2, N3, N4, N5 et 3 autre(s).', R::check($lines, $this->weights())['blockers'][0]);
        $this->assertSame('a, b', R::shortList(['a', 'b']));
        $this->assertSame('a, b, c, d, e', R::shortList(['a', 'b', 'c', 'd', 'e']));
    }

    public function test_a_student_without_a_name_is_shown_by_matricule(): void
    {
        $lines = [$this->line(['student_id' => 'X99', 'last_name' => null, 'first_name' => null, 'quiz' => 1])];

        $this->assertStringContainsString('X99', R::check($lines, $this->weights())['blockers'][0]);
    }

    public function test_a_component_is_read_with_comma_or_point_between_zero_and_twenty(): void
    {
        $this->assertSame([12.5, 12.5, 0.0, 20.0, 12.35, null, null], [R::parseComponent('12,5'), R::parseComponent(' 12.5 '), R::parseComponent('0'), R::parseComponent('20'), R::parseComponent('12.345'), R::parseComponent(''), R::parseComponent('   ')]);
        foreach (['21', '20.01', '-1', '-0.5', 'abc', '12,5,5', '1e3', 'NaN'] as $bad) {
            $this->assertFalse(R::parseComponent($bad), $bad);
        }
    }

    public function test_a_weight_is_bounded_and_forgiving(): void
    {
        $this->assertSame([0.0, 0.0, 0.0, 100.0, 0.0, 12.35, 25.0, 7.5], [R::parseWeight(''), R::parseWeight('abc'), R::parseWeight('-5'), R::parseWeight('150'), R::parseWeight('  '), R::parseWeight('12,345'), R::parseWeight('25'), R::parseWeight('7.5')]);
    }
}
