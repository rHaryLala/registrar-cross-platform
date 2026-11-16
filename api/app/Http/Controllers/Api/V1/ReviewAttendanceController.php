<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\AttendanceReview;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assiduité vue du registrariat (superadmin et registrariat). Le droit est vérifié dans le domaine à chaque appel.
 */
class ReviewAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceReview $review, private readonly AuditTrail $audit) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'year' => ['nullable', 'string', 'max:20', 'regex:/^\d{4}\s?-\s?\d{4}$/'],
            'processed' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->review->overview($request->user(), $d['year'] ?? null, (bool) ($d['processed'] ?? false))])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Marque un signalement comme traité. */
    public function process(Request $request, int $id): JsonResponse
    {
        $this->audit->reset();
        $result = $this->review->process($request->user(), $id);
        $this->audit->commit($request, $request->user(), 'Assiduité · signalement traité', 200, $request->except(['_token']));

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
