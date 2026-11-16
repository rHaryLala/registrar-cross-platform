<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Balances;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Solde de compte par session (niveaux 1 à 3). Le droit est vérifié dans le domaine à chaque appel, avant toute validation ; chaque écriture laisse sa trace dans le journal d'activité. */
class BalancesController extends Controller
{
    public function __construct(private readonly Balances $balances, private readonly AuditTrail $audit) {}

    public function index(Request $request): JsonResponse
    {
        return $this->json($this->balances->listing($request->user(), $request->query()));
    }

    public function update(Request $request, int $session, string $student): JsonResponse
    {
        $this->balances->require($request->user());
        $request->validate(['balance' => ['required']]);
        $this->audit->reset();
        $result = $this->balances->update($request->user(), $session, $student, $request->input('balance'));
        $this->audit->commit($request, $request->user(), 'Finance · solde de compte', 200, ['session_id' => $session, 'etudiant_finance' => $student, 'solde' => $request->input('balance')]);

        return $this->json($result);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
