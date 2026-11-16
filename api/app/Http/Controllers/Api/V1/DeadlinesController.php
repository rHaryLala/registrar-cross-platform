<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Deadlines;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Échéances de paiement par session (niveaux 1 à 4). Le droit est vérifié dans le domaine avant toute validation ; chaque écriture laisse sa trace dans le journal d'activité. */
class DeadlinesController extends Controller
{
    public function __construct(private readonly Deadlines $deadlines, private readonly AuditTrail $audit) {}

    public function show(Request $request): JsonResponse
    {
        return $this->json($this->deadlines->show($request->user(), (int) $request->query('session', 0)));
    }

    public function save(Request $request, int $session): JsonResponse
    {
        $this->deadlines->require($request->user());
        $d = $request->validate(['dates' => ['present', 'array']]);
        $this->audit->reset();
        $result = $this->deadlines->save($request->user(), $session, $d['dates']);
        $this->audit->commit($request, $request->user(), 'Finance · échéances de paiement', 200, ['session_id' => $session, 'lignes_changees' => $result['changed']]);

        return $this->json($result);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
