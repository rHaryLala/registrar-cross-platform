<?php

namespace App\Domain\Student;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Fiche d'inscription en PDF, fabriquée sur le serveur (même rendu sur tous les appareils, plus de dépendance à un CDN). */
final class EnrollmentPdf
{
    /**
     * @param  array<string, mixed>  $sheet  résultat de Enrollments::sheet()
     * @param  string|null  $photo  photo de l'étudiant en URI de données (data:image/jpeg;base64,…), ou null
     */
    public function render(array $sheet, ?string $photo = null): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        // Aucune ressource distante, aucun fichier local hors du gabarit : le PDF ne peut rien aller chercher.
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', resource_path('views/pdf'));
        $options->set('fontCache', storage_path('app/dompdf'));
        $options->set('tempDir', storage_path('app/dompdf'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.enrollment-sheet', ['s' => $sheet, 'photo' => $photo])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return (string) $pdf->output();
    }
}
