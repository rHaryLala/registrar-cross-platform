<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Fabrique d'un PDF à partir d'un gabarit Blade de resources/views/pdf (dompdf), commune à tous les documents du serveur :
 * même rendu partout, aucune dépendance à un CDN.
 *
 * Aucune ressource distante, aucun code PHP dans le gabarit chargé, aucun fichier local hors de resources/views/pdf :
 * le PDF ne peut rien aller chercher. Les images (photo, signature) sont fournies en URI de données.
 */
final class PdfDocument
{
    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', resource_path('views/pdf'));
        $options->set('fontCache', storage_path('app/dompdf'));
        $options->set('tempDir', storage_path('app/dompdf'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $pdf->setPaper('A4', $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }
}
