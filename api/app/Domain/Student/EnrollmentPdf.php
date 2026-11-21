<?php

namespace App\Domain\Student;

use App\Support\PdfDocument;

/** Fiche d'inscription en PDF, fabriquée sur le serveur (même rendu sur tous les appareils, plus de dépendance à un CDN). */
final class EnrollmentPdf
{
    /**
     * @param  array<string, mixed>  $sheet  résultat de Enrollments::sheet()
     * @param  string|null  $photo  photo de l'étudiant en URI de données (data:image/jpeg;base64,…), ou null
     */
    public function render(array $sheet, ?string $photo = null): string
    {
        return PdfDocument::render('pdf.enrollment-sheet', ['s' => $sheet, 'photo' => $photo]);
    }
}
