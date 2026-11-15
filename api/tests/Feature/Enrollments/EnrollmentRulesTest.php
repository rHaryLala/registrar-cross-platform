<?php

namespace Tests\Feature\Enrollments;

use App\Domain\Student\EnrollmentRules as R;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Règles de la fiche d'inscription, comparées aux VRAIES fonctions de l'ancien code quand il est présent. */
class EnrollmentRulesTest extends TestCase
{
    private const LEGACY = 'C:/laragon/www/registrar/data/';

    private function loadLegacy(): void
    {
        if (! is_file(self::LEGACY.'espace.etudiant.php')) {
            $this->markTestSkipped('Ancien dépôt absent : comparaison impossible.');
        }
        foreach (['finance.bareme', 'paiement.echeances', 'espace.etudiant'] as $f) {
            require_once self::LEGACY.$f.'.php';
        }
    }

    /** @return array<string, array{0: string, 1: int, 2: int}> */
    public static function visibility(): array
    {
        $cases = [];
        foreach (['2023 - 2024', '2024 - 2025', '2025 - 2026', '2026 - 2027', '2027 - 2028'] as $year) {
            foreach ([[0, 0], [1, 0], [3, 0], [4, 0], [5, 0], [0, 4], [0, 2], [2, 5]] as [$enrollment, $file]) {
                $cases["$year / inscription $enrollment / dossier $file"] = [$year, $enrollment, $file];
            }
        }

        return $cases;
    }

    #[DataProvider('visibility')]
    public function test_sheet_visibility_matches_the_legacy_rule(string $year, int $enrollment, int $file): void
    {
        $this->loadLegacy();

        $this->assertSame(espaceEtudiantFicheVisible($year, $enrollment, $file), R::sheetVisible($year, $enrollment, $file));
    }

    public function test_visibility_boundaries_are_licence_2026_and_master_2025(): void
    {
        $this->assertFalse(R::sheetVisible('2025 - 2026', 3, 0));
        $this->assertTrue(R::sheetVisible('2026 - 2027', 3, 0));
        $this->assertFalse(R::sheetVisible('2024 - 2025', 4, 0));
        $this->assertTrue(R::sheetVisible('2025 - 2026', 4, 0));
        // Niveau d'inscription jamais saisi (0) : on prend celui du dossier.
        $this->assertTrue(R::sheetVisible('2025 - 2026', 0, 5));
        $this->assertFalse(R::sheetVisible('2025 - 2026', 0, 2));
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: mixed}> */
    public static function graduation(): array
    {
        return [
            'semestre 2 diplômant' => [250000, 2, 1], 'semestre 1 diplômant' => [250000, 1, 1], 'semestre 2 non diplômant' => [250000, 2, 0],
            'semestre 2 en texte' => ['250000', '2', '1'], 'montant nul' => [0, 2, 1], 'semestre inconnu' => [250000, null, 1],
        ];
    }

    #[DataProvider('graduation')]
    public function test_graduation_fee_matches_the_legacy_rule(mixed $amount, mixed $semester, mixed $graduating): void
    {
        $this->loadLegacy();

        $this->assertSame(baremeFraisGraduation($amount, $semester, $graduating), R::graduationFee($amount, $semester, $graduating));
    }

    /** @return array<string, array{0: string}> */
    public static function dates(): array
    {
        return ['1er du mois' => ['2026-05-01'], 'fin de mois' => ['2026-04-30'], 'décembre' => ['2025-12-25'], 'janvier' => ['2027-01-02'], 'août' => ['2026-08-15']];
    }

    #[DataProvider('dates')]
    public function test_due_date_label_matches_the_legacy_function(string $ymd): void
    {
        $this->loadLegacy();

        $this->assertSame(echeanceDate($ymd), R::dateLabel($ymd));
    }

    public function test_due_date_label_never_invents_a_date(): void
    {
        $this->assertNull(R::dateLabel(null));
        $this->assertNull(R::dateLabel(''));
        $this->assertNull(R::dateLabel('pas une date'));
        $this->assertNull(R::dateLabel('2026-13-40'));
    }

    public function test_payment_types_match_the_legacy_ones(): void
    {
        $this->loadLegacy();

        foreach (echeancesTypes() as $code => $type) {
            $this->assertSame($type['parts'], R::paymentTypes()[$code]['parts'], "type {$code}");
            $this->assertSame($type['libelle'], R::paymentTypes()[$code]['label'], "type {$code}");
        }
        $this->assertSame(array_keys(echeancesTypes()), array_keys(R::paymentTypes()));
    }

    public function test_every_payment_type_adds_up_to_one_hundred_percent(): void
    {
        foreach (R::paymentTypes() as $code => $type) {
            $this->assertSame(100, array_sum($type['parts']), "type {$code}");
        }
    }

    public function test_levels_match_the_legacy_ones(): void
    {
        $this->loadLegacy();

        foreach (baremeNiveaux() as $n) {
            $this->assertSame($n['libelle'], R::levels()[$n['niveau']]['label']);
            $this->assertSame($n['categorie'], R::levels()[$n['niveau']]['cycle']);
        }
    }

    public function test_level_comes_from_the_enrollment_then_from_the_student_file(): void
    {
        $this->assertSame(2, R::level(2, 4));   // la session dit Licence 2, le dossier dit Master 1 aujourd'hui
        $this->assertSame(4, R::level(0, 4));   // inscription sans niveau : le dossier
        $this->assertSame(0, R::level(0, 0));
        $this->assertNull(R::level(9, 9));      // valeur hors liste : pas de libellé inventé
        $this->assertTrue(R::isMaster(5));
        $this->assertFalse(R::isMaster(3));
        $this->assertFalse(R::isMaster(null));
    }

    public function test_course_categories(): void
    {
        $this->assertSame(['Général', 'Majeur', 'Sélective', 'Sélective', 'Additionnel', '—', '—'], [
            R::courseCategory(0), R::courseCategory(1), R::courseCategory(2), R::courseCategory(-1), R::courseCategory(3), R::courseCategory(5), R::courseCategory(99),
        ]);
        // Cours disparu du catalogue : inconnu, et surtout PAS « Général » (null == 0 en PHP).
        $this->assertSame('—', R::courseCategory(null));
    }

    public function test_pdf_filename_is_plain_and_safe(): void
    {
        $this->assertSame('1234-fiche-inscription-Session-1-2026-2027.pdf', R::pdfFilename('1234', 'Session 1', '2026 - 2027'));
        $this->assertSame('M7-fiche-inscription-Ete-2026-2027.pdf', R::pdfFilename('M7', 'Été', '2026 - 2027'));
        // Un matricule saisi à la main ne peut pas sortir du nom de fichier.
        $name = R::pdfFilename('../../etc/passwd"; x=', 'Session 1', '2026 - 2027');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\.pdf$/', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('"', $name);
    }
}
