<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Grading\RemiseReview;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validation des remises de notes (registrariat, niveaux 1 à 3). Le droit est vérifié dans le domaine à chaque appel.
 * Chaque écriture laisse sa trace dans le journal d'activité commun.
 */
class ReviewRemiseController extends Controller
{
    public function __construct(private readonly RemiseReview $review, private readonly AuditTrail $audit) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'year' => ['nullable', 'string', 'max:20', 'regex:/^\d{4}\s?-\s?\d{4}$/']]);

        return $this->read($this->review->queue($request->user(), $d['status'] ?? null, $d['year'] ?? null));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->read($this->review->detail($request->user(), $id));
    }

    /** Demande la validation : annulable pendant 10 secondes, aucune note écrite avant. */
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->write($request, 'Validation des notes · demande', fn () => $this->review->approve($request->user(), $id));
    }

    public function undo(Request $request, int $id): JsonResponse
    {
        return $this->write($request, 'Validation des notes · annulation', fn () => $this->review->undo($request->user(), $id));
    }

    /** Mène la validation à son terme une fois le délai d'annulation passé. */
    public function finalize(Request $request, int $id): JsonResponse
    {
        return $this->write($request, 'Validation des notes · validation', fn () => $this->review->finalize($request->user(), $id));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->write($request, 'Validation des notes · renvoi', fn () => $this->review->reject($request->user(), $id, $d['reason']));
    }

    /** Validation groupée : chaque feuille garde sa demande et son délai de 10 secondes ; celles qui ne peuvent pas partir sont rendues avec la raison. */
    public function approveMany(Request $request): JsonResponse
    {
        $ids = $this->ids($request);

        return $this->write($request, 'Validation des notes · demande groupée', fn () => $this->review->approveMany($request->user(), $ids));
    }

    public function undoMany(Request $request): JsonResponse
    {
        $ids = $this->ids($request);

        return $this->write($request, 'Validation des notes · annulation groupée', fn () => $this->review->undoMany($request->user(), $ids));
    }

    public function finalizeMany(Request $request): JsonResponse
    {
        $ids = $this->ids($request);

        return $this->write($request, 'Validation des notes · validation groupée', fn () => $this->review->finalizeMany($request->user(), $ids));
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        return array_map('intval', $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.RemiseReview::BULK_MAX],
            'ids.*' => ['integer', 'min:1'],
        ])['ids']);
    }

    private function read(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['server_time' => (int) floor(microtime(true) * 1000)]])->header('Cache-Control', 'private, no-store');
    }

    private function write(Request $request, string $label, callable $action): JsonResponse
    {
        $this->audit->reset();
        $result = $action();
        $this->audit->commit($request, $request->user(), $label, 200, $request->except(['_token']));

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
