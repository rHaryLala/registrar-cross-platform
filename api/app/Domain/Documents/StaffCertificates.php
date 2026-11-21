<?php

namespace App\Domain\Documents;

use App\Models\User;
use App\Support\LegacyAsset;
use App\Support\PdfDocument;

/**
 * Le certificat de scolarité en PDF, demandé par le personnel. Droit et périmètre : `StudentDocumentAccess::identityStudent`.
 * Il porte le nom et le titre de la personne qui l'imprime (comme l'ancien certificat), jamais une signature scannée.
 */
final class StaffCertificates
{
    public function __construct(private readonly StudentDocumentAccess $access, private readonly Certificate $certificate) {}

    /** @return array{filename: string, content: string} */
    public function pdf(User $user, int $id): array
    {
        $student = $this->access->identityStudent($user, $id);
        $data = $this->certificate->build($student, $user);

        return [
            'filename' => self::filename($data['student_id']),
            'content' => PdfDocument::render('pdf.certificate', ['c' => $data, 'logo' => LegacyAsset::dataUri('UAZ Official Black Logo.jpg', 360, 360)]),
        ];
    }

    public static function filename(string $studentId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '', $studentId).'-certificat-scolarite.pdf';
    }
}
