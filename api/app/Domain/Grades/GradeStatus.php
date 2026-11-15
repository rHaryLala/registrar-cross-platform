<?php

namespace App\Domain\Grades;

/**
 * Statut d'une note (`t_2023_notes.grade`), port fidèle de data/notes.statut.php (noteStatut).
 *
 * La colonne `grade` est un VARCHAR qui mêle notes chiffrées et codes :
 *   -2 validé sans note → V        -3 échec sans note → E
 *   -4 pas encore noté → NN        -1 ancien code hérité → NN
 *   vide, NULL, non numérique → NN
 * Une virgule décimale (« 12,5 ») est acceptée.
 *
 * Les cours de validation (catégorie 5, RELP 291, formation spirituelle) ne s'expriment qu'en V ou E
 * et ne comptent jamais dans la moyenne.
 */
final class GradeStatus
{
    private function __construct(
        /** S (succès), E (échec), V (validé) ou NN (pas encore noté). */
        public readonly string $code,
        public readonly string $title,
        /** La note en nombre ; null pour un code. */
        public readonly ?float $value,
    ) {}

    public static function from(mixed $grade, bool $validationCourse = false): self
    {
        $raw = trim(str_replace(',', '.', (string) ($grade ?? '')));
        $value = is_numeric($raw) ? (float) $raw : null;

        if ($value === -2.0) {
            return new self('V', 'Validé', null);
        }
        if ($value === -3.0) {
            return new self('E', 'Échec', null);
        }
        if ($value === null || $value < 0) {
            return new self('NN', 'Pas encore noté', null);
        }
        if ($validationCourse) {
            return $value >= 10 ? new self('V', 'Validé', null) : new self('E', 'Échec', null);
        }

        return new self($value >= 10 ? 'S' : 'E', $value >= 10 ? 'Succès' : 'Échec', $value);
    }

    public function isNumeric(): bool
    {
        return $this->value !== null;
    }

    /**
     * Entre-t-elle dans la moyenne ?
     *
     * Règle de la page « Mes notes » actuelle : seule une note chiffrée > 0 compte, donc un 0/20 s'AFFICHE mais
     * n'entre pas dans la moyenne. Le fichier data/notes.statut.php dit au contraire qu'un 0 « compte dans la moyenne » :
     * l'ancien code se contredit (8 notes à 0 chez 7 étudiants au 12/11/2026). Décision du registraire attendue ;
     * en attendant, on garde ce que les étudiants voient aujourd'hui. Réglage : registrar.zero_counts_in_average.
     */
    public function countsInAverage(): bool
    {
        if (! $this->isNumeric()) {
            return false;
        }

        return $this->value > 0 || (bool) config('registrar.zero_counts_in_average', false);
    }

    /** Cours de validation : catégorie 5, RELP 291 ou « formation spirituelle ». */
    public static function isValidationCourse(mixed $category, ?string $code, ?string $title): bool
    {
        return (int) $category === 5
            || stripos((string) $code, 'RELP 291') !== false
            || stripos((string) $title, 'formation spirituelle') !== false;
    }

    public static function categoryLabel(mixed $category): string
    {
        if ($category === null || $category === '') {
            return '-'; // l'ancien affichage (match strict) donnait « - » à une catégorie vide, jamais « Général »
        }

        return match ((int) $category) {
            0 => 'Général',
            1 => 'Majeur',
            -1, 2 => 'Sélective',
            3 => 'Additionnel',
            default => '-',
        };
    }

    /** « Licence 2 » ou « Master 1 » (niveaux 1 à 3 : Licence ; au-delà : Master). Null si le niveau est inconnu. */
    public static function levelLabel(?int $yearLevel): ?string
    {
        if ($yearLevel === null || $yearLevel <= 0) {
            return null;
        }

        return $yearLevel <= 3 ? "Licence {$yearLevel}" : 'Master '.($yearLevel - 3);
    }
}
