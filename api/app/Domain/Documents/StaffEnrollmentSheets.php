<?php

namespace App\Domain\Documents;

use App\Domain\Student\EnrollmentPdf;
use App\Domain\Student\Enrollments;
use App\Domain\Student\StudentPhoto;
use App\Exceptions\ApiProblem;
use App\Models\User;

/**
 * La fiche d'inscription d'un étudiant, demandée par le personnel (port de `Fiche_inscription` de src/data.topdf.php, 710 appels en deux mois).
 *
 * Même document que celui de l'espace étudiant (mêmes montants, même PDF serveur) : c'est `Enrollments` qui le construit. Ce qui change :
 *  - toute inscription est consultable, y compris les années que l'étudiant ne voit pas encore ;
 *  - le droit et le périmètre sont ceux de tous les documents du personnel (`StudentDocumentAccess`) : niveaux 1 à 4, cycle du compte,
 *    étudiant désigné par l'identifiant de sa ligne, jamais par un matricule saisi.
 */
final class StaffEnrollmentSheets
{
    public function __construct(
        private readonly Enrollments $enrollments,
        private readonly EnrollmentPdf $pdf,
        private readonly StudentPhoto $photos,
        private readonly StudentDocumentAccess $access,
    ) {}

    public static function allows(User $user): bool
    {
        return StudentDocumentAccess::allows($user);
    }

    /**
     * Les inscriptions d'un étudiant, pour proposer une fiche par session.
     *
     * @return array<string, mixed>
     */
    public function sessions(User $user, int $id): array
    {
        $student = $this->access->student($user, $id);

        return [
            'student_id' => (string) $student->student_id,
            'name' => trim(mb_strtoupper((string) $student->student_nom).' '.$student->student_prenom),
            'sessions' => $this->enrollments->sessionsForStaff((string) $student->student_id),
        ];
    }

    /**
     * Le PDF d'une session de cet étudiant.
     *
     * @return array{filename: string, content: string}
     */
    public function pdf(User $user, int $id, int $sessionId): array
    {
        $student = $this->access->student($user, $id);
        $sheet = $this->enrollments->sheetForStaff((string) $student->student_id, $sessionId)
            ?? throw new ApiProblem(404, 'document.not_found', 'Fiche introuvable', 'Cet étudiant n’est pas inscrit à cette session.');

        return [
            'filename' => $sheet['filename'],
            'content' => $this->pdf->render($sheet, $this->photos->dataUri((string) $student->student_id)),
        ];
    }
}
