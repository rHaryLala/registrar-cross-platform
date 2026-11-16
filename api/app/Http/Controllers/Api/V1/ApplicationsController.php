<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Admissions\Applications;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Candidatures de pré-admission (superadmin et registrariat). Le droit est vérifié dans le domaine à chaque appel.
 * Chaque écriture laisse sa trace dans le journal d'activité commun.
 */
class ApplicationsController extends Controller
{
    public function __construct(private readonly Applications $applications, private readonly AuditTrail $audit) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['status' => ['nullable', 'string', 'max:20']]);

        return $this->read($this->applications->list($request->user(), $d['status'] ?? null));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->read($this->applications->detail($request->user(), $id));
    }

    /** Instruire : change le statut. Accepter ou refuser pose les notifications (202 : la décision est enregistrée, l'envoi suit). */
    public function status(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'string', 'max:20']]);
        $result = $this->write($request, 'Candidatures · statut', fn () => $this->applications->setStatus($request->user(), $id, $d['status']));
        $pending = collect($result['notifications'])->contains(fn ($n) => $n['state'] === 'en_attente' && $result['changed']);

        return response()->json(['data' => $result], $pending ? 202 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['archive' => ['required', 'boolean']]);

        return $this->respond($this->write($request, 'Candidatures · archivage', fn () => $this->applications->archive($request->user(), $id, (bool) $d['archive'])));
    }

    /** Relance les notifications échouées de la décision actuelle. */
    public function retry(Request $request, int $id): JsonResponse
    {
        return $this->respond($this->write($request, 'Candidatures · nouvel envoi', fn () => ['id' => $id, 'requeued' => $this->applications->retry($request->user(), $id)]));
    }

    private function read(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    private function write(Request $request, string $label, callable $action): array
    {
        $this->audit->reset();
        $result = $action();
        $this->audit->commit($request, $request->user(), $label, 200, $request->except(['_token']));

        return $result;
    }
}
