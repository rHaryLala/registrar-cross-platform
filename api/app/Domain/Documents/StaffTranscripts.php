<?php

namespace App\Domain\Documents;

use App\Domain\Student\StudentPhoto;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\PdfDocument;
use App\Support\RegistrarSignature;
use Illuminate\Support\Carbon;

/**
 * Le relevé semestriel des notes d'un étudiant, en PDF, demandé par le personnel (port de `TranscriptSS` de src/data.topdf.php).
 *
 * Le droit, le périmètre de cycle et la désignation de l'étudiant sont ceux de tous les documents du personnel (`StudentDocumentAccess`).
 * Les filtres de l'ancien relevé sont conservés : année scolaire, semestre, niveau, session. Un filtre illisible est refusé (422) au lieu
 * d'être ignoré : un relevé « de l'année 2O25 » qui sortirait complet serait un faux document.
 */
final class StaffTranscripts
{
    public function __construct(
        private readonly StudentDocumentAccess $access,
        private readonly Transcript $transcript,
        private readonly StudentPhoto $photos,
    ) {}

    /**
     * Les sessions où l'étudiant a des notes, pour choisir ce qu'on imprime.
     *
     * @return array<string, mixed>
     */
    public function options(User $user, int $id): array
    {
        $student = $this->access->student($user, $id);

        return [
            'student_id' => (string) $student->student_id,
            'name' => trim(mb_strtoupper((string) $student->student_nom).' '.$student->student_prenom),
            'sessions' => $this->transcript->options((string) $student->student_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $query  year, semester, level, session (tous facultatifs)
     * @return array{filename: string, content: string}
     */
    public function pdf(User $user, int $id, array $query): array
    {
        $student = $this->access->student($user, $id);
        $filters = $this->filters($query);
        $data = $this->transcript->build($student, $filters);

        $content = PdfDocument::render('pdf.transcript', [
            't' => $data,
            'photo' => $this->photos->dataUri((string) $student->student_id),
            'footer' => RegistrarSignature::footer(),
            'issued' => self::dateLabel(Carbon::now('Indian/Antananarivo')),
            'filters' => $filters,
        ]);

        return ['filename' => self::filename((string) $student->student_id, $filters), 'content' => $content];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{year: ?string, semester: ?int, level: ?int, session: ?int}
     */
    public function filters(array $query): array
    {
        $number = function (string $key, int $min, int $max) use ($query): ?int {
            $v = $query[$key] ?? null;
            if ($v === null || $v === '' || $v === 'all') {
                return null;
            }
            if (! is_scalar($v) || ! preg_match('/^\d{1,9}$/', (string) $v) || (int) $v < $min || (int) $v > $max) {
                throw new ApiProblem(422, 'validation.transcript', 'Filtre invalide', "Le filtre « {$key} » n’est pas valide.");
            }

            return (int) $v;
        };

        $year = $query['year'] ?? null;
        if ($year === 'all' || $year === '' || $year === null) {
            $year = null;
        } elseif (! is_string($year) || ! preg_match('/^\d{4}\s?-\s?\d{4}$/', $year)) {
            throw new ApiProblem(422, 'validation.transcript', 'Filtre invalide', 'L’année scolaire s’écrit « 2025 - 2026 ».');
        }

        return ['year' => $year, 'semester' => $number('semester', 1, 4), 'level' => $number('level', 0, 5), 'session' => $number('session', 1, 999_999_999)];
    }

    /** @param  array{year: ?string, semester: ?int, level: ?int, session: ?int}  $filters */
    public static function filename(string $studentId, array $filters): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '', $studentId);
        $scope = $filters['year'] !== null ? '-'.preg_replace('/[^0-9]+/', '-', $filters['year']) : '';
        $scope = trim((string) preg_replace('/-+/', '-', $scope), '-');

        return $id.'-releve-semestriel'.($scope !== '' ? '-'.$scope : '').'.pdf';
    }

    public static function dateLabel(Carbon $d): string
    {
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $d->day.' '.$months[$d->month - 1].' '.$d->year;
    }
}
