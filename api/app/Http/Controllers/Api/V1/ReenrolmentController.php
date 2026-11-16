<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Directory\Directory;
use App\Domain\Finance\Reenrolment;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Autorisation de réinscription (finance) : la liste, les bascules case par case ou groupées, et la réinitialisation globale (superadmin seul).
 * Le droit est vérifié dans le domaine à chaque appel, avant toute validation ; chaque écriture laisse sa trace dans le journal d'activité.
 */
class ReenrolmentController extends Controller
{
    public function __construct(private readonly Reenrolment $reenrolment, private readonly Directory $directory, private readonly AuditTrail $audit) {}

    /** La liste (mêmes paramètres que les autres listes), les compteurs par état, ce que le compte peut faire et le dernier lot annulable. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->reenrolment->require($user);
        $list = $this->directory->listing($user, 'reenrolment', $request->query());

        return $this->json($list + [
            'stats' => $this->reenrolment->stats(),
            'rights' => Reenrolment::rights($user),
            'last_batch' => $this->reenrolment->lastBatch($user),
            'batches_available' => $this->reenrolment->batchesAvailable(),
        ]);
    }

    public function toggle(Request $request, int $id, string $field): JsonResponse
    {
        $this->reenrolment->require($request->user());
        $d = $request->validate(['value' => ['required', 'boolean']]);
        $this->audit->reset();
        $result = $this->reenrolment->toggle($request->user(), $id, $field, (bool) $d['value'], (string) $request->ip(), (string) $request->userAgent());
        $this->audit->commit($request, $request->user(), 'Réinscription · '.($field === 'authorization' ? 'autorisation' : 'dossier'), 200, ['etudiant_id' => $id, 'valeur' => (bool) $d['value']]);

        return $this->json($result);
    }

    public function bulk(Request $request): JsonResponse
    {
        $this->reenrolment->require($request->user());
        $d = $request->validate([
            'field' => ['required', Rule::in(array_keys(Reenrolment::FIELDS))], 'value' => ['required', 'boolean'],
            'ids' => ['required', 'array', 'min:1', 'max:'.Reenrolment::BULK_MAX], 'ids.*' => ['integer', 'min:1'],
        ]);
        $this->audit->reset();
        $result = $this->reenrolment->toggleMany($request->user(), $d['ids'], $d['field'], (bool) $d['value'], (string) $request->ip(), (string) $request->userAgent());
        $this->audit->commit($request, $request->user(), 'Réinscription · '.($d['field'] === 'authorization' ? 'autorisation' : 'dossier').' (groupé)', 200, ['champ' => $d['field'], 'valeur' => (bool) $d['value'], 'nombre' => count($d['ids'])]);

        return $this->json($result);
    }

    public function preview(Request $request): JsonResponse
    {
        return $this->json($this->reenrolment->preview($request->user(), $request->query('columns')));
    }

    public function reset(Request $request): JsonResponse
    {
        $this->reenrolment->require($request->user());
        $d = $request->validate(['columns' => ['required', 'array', 'min:1'], 'columns.*' => ['string'], 'confirmation' => ['required', 'string', 'max:40']]);
        $this->audit->reset();
        $result = $this->reenrolment->reset($request->user(), $d['columns'], $d['confirmation'], (string) $request->ip(), (string) $request->userAgent());
        $this->audit->commit($request, $request->user(), 'Réinscription · réinitialisation globale', 200, ['colonnes' => $d['columns'], 'lot' => $result['lot']]);

        return $this->json($result);
    }

    public function undo(Request $request, string $batch): JsonResponse
    {
        $this->audit->reset();
        $result = $this->reenrolment->undo($request->user(), $batch, (string) $request->ip(), (string) $request->userAgent());
        $this->audit->commit($request, $request->user(), 'Réinscription · annulation d’une réinitialisation', 200, ['lot' => $batch]);

        return $this->json($result);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
