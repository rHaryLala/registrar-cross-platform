<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Student\EnrollmentPdf;
use App\Domain\Student\Enrollments;
use App\Domain\Student\StudentPhoto;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EnrollmentController extends Controller
{
    use ResolvesStudent;

    public function __construct(private readonly Enrollments $enrollments) {}

    /** Mes inscriptions : une fiche par session, de la plus récente à la plus ancienne. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->enrollments->list($this->studentId($request))]);
    }

    public function show(Request $request, int $session): JsonResponse
    {
        return response()->json(['data' => $this->sheetOrFail($request, $session)]);
    }

    /** La fiche en PDF. Document personnel et financier : jamais mis en cache. */
    public function pdf(Request $request, int $session, EnrollmentPdf $pdf, StudentPhoto $photos): Response
    {
        $sheet = $this->sheetOrFail($request, $session);

        return response($pdf->render($sheet, $photos->dataUri($this->studentId($request))), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$sheet['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function sheetOrFail(Request $request, int $session): array
    {
        // Session inconnue, d'un autre étudiant, trop ancienne ou rubrique masquée : la même réponse, sans rien révéler.
        return $this->enrollments->sheet($this->studentId($request), $session)
            ?? throw new ApiProblem(404, 'enrollment.not_found', 'Fiche introuvable', 'Cette session ne figure pas parmi vos inscriptions, ou les fiches ne sont pas consultables pour le moment.');
    }
}
