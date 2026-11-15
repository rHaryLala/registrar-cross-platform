<?php

namespace Tests\Feature\Grades;

use App\Domain\Grades\GradeStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GradeStatusTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: bool, 2: string, 3: ?float}> */
    public static function cases(): array
    {
        return [
            'réussite' => ['14.5', false, 'S', 14.5],
            'seuil exact' => ['10', false, 'S', 10.0],
            'juste sous le seuil' => ['9.99', false, 'E', 9.99],
            'virgule décimale' => ['12,5', false, 'S', 12.5],
            'point final' => ['16.', false, 'S', 16.0],
            'zéro = vraie note, échec' => ['0', false, 'E', 0.0],
            'validé sans note' => ['-2', false, 'V', null],
            'échec sans note' => ['-3', false, 'E', null],
            'pas encore noté' => ['-4', false, 'NN', null],
            'ancien code hérité' => ['-1', false, 'NN', null],
            'vide' => ['', false, 'NN', null],
            'null' => [null, false, 'NN', null],
            'texte' => ['abc', false, 'NN', null],
            'espaces' => ['  15 ', false, 'S', 15.0],
            'validation réussie' => ['12', true, 'V', null],
            'validation échouée' => ['9', true, 'E', null],
            'validation non notée' => ['-4', true, 'NN', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_status_rules(mixed $grade, bool $validation, string $code, ?float $value): void
    {
        $s = GradeStatus::from($grade, $validation);

        $this->assertSame($code, $s->code);
        $this->assertSame($value, $s->value);
    }

    /** Même résultat que la VRAIE fonction de l'ancien code (si le dépôt de l'ancienne application est présent). */
    #[DataProvider('cases')]
    public function test_matches_the_legacy_noteStatut_function(mixed $grade, bool $validation, string $code, ?float $value): void
    {
        $legacy = 'C:/laragon/www/registrar/data/notes.statut.php';
        if (! is_file($legacy)) {
            $this->markTestSkipped('Ancien dépôt absent : comparaison impossible.');
        }
        require_once $legacy;

        $old = noteStatut($grade, $validation);
        $new = GradeStatus::from($grade, $validation);

        $this->assertSame($old['code'], $new->code, 'code');
        $this->assertSame($old['valeur'], $new->value, 'valeur');
        $this->assertSame($old['titre'], $new->title, 'titre');
    }

    public function test_a_zero_is_displayed_but_does_not_count_by_default(): void
    {
        $zero = GradeStatus::from('0');

        $this->assertSame(0.0, $zero->value);
        $this->assertFalse($zero->countsInAverage());
    }

    public function test_the_registrar_can_make_zero_count(): void
    {
        config(['registrar.zero_counts_in_average' => true]);

        $this->assertTrue(GradeStatus::from('0')->countsInAverage());
        $this->assertFalse(GradeStatus::from('-4')->countsInAverage(), 'un code n\'est jamais une note');
    }

    public function test_codes_never_count(): void
    {
        foreach (['-2', '-3', '-4', '-1', '', 'abc'] as $g) {
            $this->assertFalse(GradeStatus::from($g)->countsInAverage(), "« {$g} »");
        }
        $this->assertFalse(GradeStatus::from('15', true)->countsInAverage(), 'cours de validation');
    }

    public function test_validation_course_detection(): void
    {
        $this->assertTrue(GradeStatus::isValidationCourse(5, 'X 1', 'Y'));
        $this->assertTrue(GradeStatus::isValidationCourse(1, 'RELP 291', 'Y'));
        $this->assertTrue(GradeStatus::isValidationCourse(1, 'X 1', 'La Formation Spirituelle II'));
        $this->assertFalse(GradeStatus::isValidationCourse(1, 'X 1', 'Algorithmique'));
    }

    public function test_level_and_category_labels(): void
    {
        $this->assertSame('Licence 1', GradeStatus::levelLabel(1));
        $this->assertSame('Licence 3', GradeStatus::levelLabel(3));
        $this->assertSame('Master 1', GradeStatus::levelLabel(4));
        $this->assertSame('Master 2', GradeStatus::levelLabel(5));
        $this->assertNull(GradeStatus::levelLabel(null));
        $this->assertNull(GradeStatus::levelLabel(0));

        $this->assertSame('Général', GradeStatus::categoryLabel(0));
        $this->assertSame('Majeur', GradeStatus::categoryLabel(1));
        $this->assertSame('Sélective', GradeStatus::categoryLabel(2));
        $this->assertSame('Sélective', GradeStatus::categoryLabel(-1));
        $this->assertSame('Additionnel', GradeStatus::categoryLabel(3));
        $this->assertSame('-', GradeStatus::categoryLabel(9));
        $this->assertSame('-', GradeStatus::categoryLabel(null));
    }
}
