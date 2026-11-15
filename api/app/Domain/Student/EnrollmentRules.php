<?php

namespace App\Domain\Student;

/**
 * Règles de la fiche d'inscription, portées de l'ancien code (data/espace.etudiant.php, data/finance.bareme.php,
 * data/paiement.echeances.php). Fonctions pures : aucune lecture en base ici.
 */
final class EnrollmentRules
{
    /** Première année dont l'étudiant voit ses fiches, par cycle (décision du registrariat du 07/10/2026). */
    public const SHEETS_FROM = ['L' => 2026, 'M' => 2025];

    /** @return array<int, array{label: string, cycle: string}> niveaux 0 à 5 (baremeNiveaux) */
    public static function levels(): array
    {
        return [
            0 => ['label' => 'Remise à niveau', 'cycle' => 'L'],
            1 => ['label' => 'Licence 1', 'cycle' => 'L'],
            2 => ['label' => 'Licence 2', 'cycle' => 'L'],
            3 => ['label' => 'Licence 3', 'cycle' => 'L'],
            4 => ['label' => 'Master 1', 'cycle' => 'M'],
            5 => ['label' => 'Master 2', 'cycle' => 'M'],
        ];
    }

    /**
     * Niveau de la fiche : celui de l'INSCRIPTION (la session), à défaut celui du dossier de l'étudiant.
     * L'ancienne fiche lisait toujours le niveau actuel du dossier : un étudiant passé en Master voyait sa fiche de
     * Licence imprimée « Master 1 ». Le montant ne dépend pas de ce choix, seulement l'intitulé et les lignes à 0.
     */
    public static function level(mixed $enrollmentLevel, mixed $studentLevel): ?int
    {
        $level = (int) $enrollmentLevel > 0 ? (int) $enrollmentLevel : (int) $studentLevel;

        return array_key_exists($level, self::levels()) ? $level : null;
    }

    public static function isMaster(?int $level): bool
    {
        return $level !== null && self::levels()[$level]['cycle'] === 'M';
    }

    /** La fiche de cette session est-elle montrée à l'étudiant ? (espaceEtudiantFicheVisible) */
    public static function sheetVisible(string $year, mixed $enrollmentLevel, mixed $studentLevel): bool
    {
        $level = (int) $enrollmentLevel > 0 ? (int) $enrollmentLevel : (int) $studentLevel;
        $from = self::SHEETS_FROM[$level >= 4 ? 'M' : 'L'];

        return (int) substr($year, 0, 4) >= $from;
    }

    /** Catégorie d'un cours. Une catégorie inconnue s'écrit « — », jamais une valeur inventée. */
    public static function courseCategory(mixed $category): string
    {
        return match (true) {
            $category === null => '—',
            (int) $category === 0 => 'Général',
            (int) $category === 1 => 'Majeur',
            (int) $category === -1, (int) $category === 2 => 'Sélective',
            (int) $category === 3 => 'Additionnel',
            default => '—',
        };
    }

    /** Frais de graduation : deuxième semestre ET étudiant diplômant seulement (baremeFraisGraduation). */
    public static function graduationFee(mixed $amount, mixed $semester, mixed $graduating): float
    {
        return ((int) $semester === 2 && (int) $graduating === 1) ? (float) $amount : 0.0;
    }

    /** @return array<string, array{label: string, parts: list<int>}> types de paiement (echeancesTypes) */
    public static function paymentTypes(): array
    {
        return [
            'A' => ['label' => 'En une fois', 'parts' => [100]],
            'B' => ['label' => 'Deux tranches de 50 %', 'parts' => [50, 50]],
            'C' => ['label' => 'Deux tranches, 75 % puis 25 %', 'parts' => [75, 25]],
            'D' => ['label' => 'Trois tranches : 40 %, 30 % et 30 %', 'parts' => [40, 30, 30]],
            'E' => ['label' => 'Quatre tranches de 25 %', 'parts' => [25, 25, 25, 25]],
        ];
    }

    /** « 2026-04-30 » → « 30 avril 2026 » ; « 2026-05-01 » → « 1er mai 2026 » (echeanceDate). */
    public static function dateLabel(?string $ymd): ?string
    {
        if ($ymd === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) {
            return null;
        }
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $day = (int) $m[3];
        $month = (int) $m[2];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return $day.($day === 1 ? 'er' : '').' '.$months[$month - 1].' '.$m[1];
    }

    /** Nom du PDF : sans accents ni espaces, comme l'ancienne page. */
    public static function pdfFilename(string $studentId, string $sessionName, string $year): string
    {
        $plain = strtr($sessionName.' '.$year, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'É' => 'E', 'È' => 'E',
        ]);
        $name = $studentId.'-fiche-inscription-'.preg_replace('/[^A-Za-z0-9]+/', '-', $plain);
        $name = trim((string) preg_replace('/-+/', '-', $name), '-');
        // Le matricule est une donnée saisie : jamais de caractère qui sorte du nom de fichier.
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $name).'.pdf';
    }
}
