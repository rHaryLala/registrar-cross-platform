<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\ExtraFees;
use App\Domain\Finance\Tariff;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Barème financier par session et frais supplémentaires (niveaux 1 à 3). Le droit est vérifié dans le domaine avant toute validation ;
 * chaque écriture laisse sa trace dans le journal d'activité.
 */
class TariffController extends Controller
{
    public function __construct(private readonly Tariff $tariff, private readonly ExtraFees $fees, private readonly AuditTrail $audit) {}

    public function show(Request $request): JsonResponse
    {
        return $this->json($this->tariff->show($request->user(), $request->query()));
    }

    public function save(Request $request): JsonResponse
    {
        $this->tariff->require($request->user());
        $this->audit->reset();
        $result = $this->tariff->save($request->user(), $request->all());
        $this->audit->commit($request, $request->user(), 'Finance · barème', 200, ['session_id' => $result['session_id'], 'mentions' => $result['mentions'], 'semestre' => $result['semester']]);

        return $this->json($result);
    }

    public function copy(Request $request): JsonResponse
    {
        $this->tariff->require($request->user());
        $this->audit->reset();
        $result = $this->tariff->copy($request->user(), $request->all());
        $this->audit->commit($request, $request->user(), 'Finance · barème : reprise', 200, $request->only(['session_id', 'source_session_id', 'mention']));

        return $this->json($result);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->tariff->require($request->user());
        $this->audit->reset();
        $result = $this->tariff->reset($request->user(), $request->all());
        $this->audit->commit($request, $request->user(), 'Finance · barème : retour à la base', 200, $request->only(['session_id', 'mention']));

        return $this->json($result);
    }

    public function createFee(Request $request): JsonResponse
    {
        $this->tariff->require($request->user());
        $this->audit->reset();
        $result = $this->fees->create($request->user(), $request->input('label'), $request->input('condition', 'aucune'), $request->input('semesters', ''));
        $this->audit->commit($request, $request->user(), 'Finance · frais supplémentaire : création', 200, ['libelle' => $request->input('label')]);

        return $this->json($result, $result['created'] ? 201 : 200);
    }

    /** Un frais : renommer (`label`), activer ou désactiver (`active`), régler (`condition` et `semesters`). Un seul réglage par appel. */
    public function updateFee(Request $request, int $id): JsonResponse
    {
        $this->tariff->require($request->user());
        $this->audit->reset();
        if ($request->has('label')) {
            $result = $this->fees->rename($id, $request->input('label'));
        } elseif ($request->has('active')) {
            $result = $this->fees->setActive($id, filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        } elseif ($request->has('condition')) {
            $result = $this->fees->configure($id, $request->input('condition'), $request->input('semesters', ''));
        } else {
            $request->validate(['label' => ['required']]);
            $result = [];
        }
        $this->audit->commit($request, $request->user(), 'Finance · frais supplémentaire : modification', 200, ['frais_id' => $id] + $request->only(['label', 'active', 'condition', 'semesters']));

        return $this->json($result);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}
