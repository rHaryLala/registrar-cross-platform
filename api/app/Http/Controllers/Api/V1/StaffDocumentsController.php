<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\StaffEnrollmentSheets;
use App\Domain\Documents\StaffTranscripts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Les documents imprimables d'un étudiant, pour le personnel. Le droit et le périmètre sont vérifiés dans le domaine à chaque appel. */
class StaffDocumentsController extends Controller
{
    public function __construct(
        private readonly StaffEnrollmentSheets $sheets,
        private readonly StaffTranscripts $transcripts,
    ) {}

    public function enrollmentSheets(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->sheets->sessions($request->user(), $id)])->header('Cache-Control', 'private, no-store');
    }

    /** Document personnel et financier : jamais mis en cache. */
    public function enrollmentSheetPdf(Request $request, int $id, int $session): Response
    {
        return $this->pdf($this->sheets->pdf($request->user(), $id, $session));
    }

    /** Les sessions où l'étudiant a des notes : de quoi choisir ce que le relevé couvre. */
    public function transcriptOptions(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->transcripts->options($request->user(), $id)])->header('Cache-Control', 'private, no-store');
    }

    /** Le relevé semestriel (filtres facultatifs : year, semester, level, session). Notes d'un étudiant : jamais mis en cache. */
    public function transcriptPdf(Request $request, int $id): Response
    {
        return $this->pdf($this->transcripts->pdf($request->user(), $id, $request->query()));
    }

    /** @param  array{filename: string, content: string}  $file */
    private function pdf(array $file): Response
    {
        return response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
