<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Grading\GradingRules;
use App\Domain\Teacher\TeacherGrading;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remise de notes : la feuille de l'enseignant (lecture, saisie cellule par cellule, pondération, transmission).
 *
 * Aucun identifiant d'enseignant n'est lu dans la requête ; la propriété du cours est vérifiée dans le domaine à chaque appel.
 * Chaque écriture écrit au journal d'activité commun (voir TeacherAttendanceController).
 */
class TeacherGradingController extends Controller
{
    private const YEAR = ['required', 'string', 'max:20', 'regex:/^\d{4}\s?-\s?\d{4}$/'];

    public function __construct(private readonly TeacherGrading $grading, private readonly AuditTrail $audit) {}

    public function courses(Request $request): JsonResponse
    {
        $d = $request->validate(['year' => ['nullable', 'string', 'max:20', 'regex:/^\d{4}\s?-\s?\d{4}$/']]);

        return $this->read($this->grading->courses($request->user(), $d['year'] ?? null));
    }

    public function sheet(Request $request, int $course): JsonResponse
    {
        $d = $request->validate(['year' => self::YEAR, 'semester' => ['nullable', 'integer', 'between:1,4']]);

        return $this->read($this->grading->sheet($request->user(), $course, $d['year'], isset($d['semester']) ? (int) $d['semester'] : null));
    }

    /** Les colonnes de la feuille (titre et pondération de chacune) : la liste complète, dans l'ordre voulu. */
    public function components(Request $request, int $course): JsonResponse
    {
        $d = $request->validate([
            'year' => self::YEAR, 'semester' => ['nullable', 'integer', 'between:1,4'],
            'components' => ['required', 'array', 'min:1', 'max:'.GradingRules::MAX_COMPONENTS],
            'components.*' => ['array'], 'components.*.slot' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'components.*.label' => ['required', 'string', 'max:'.(GradingRules::MAX_LABEL * 3)], 'components.*.weight' => ['present', 'nullable', 'max:20'],
        ] + $this->offline());

        return $this->write($request, 'Remise de notes · colonnes et pondération', fn () => $this->grading->setComponents(
            $request->user(), $course, $d['year'], isset($d['semester']) ? (int) $d['semester'] : null, $d['components'], $this->clientAt($d), $this->force($d),
        ));
    }

    public function cell(Request $request, int $course, string $student): JsonResponse
    {
        $d = $request->validate([
            'year' => self::YEAR, 'semester' => ['nullable', 'integer', 'between:1,4'], 'field' => ['required', 'regex:/^(c\d{1,5}|statut_special|commentaire)$/'],
            'value' => ['present', 'nullable', 'max:300'],
        ] + $this->offline());

        return $this->write($request, 'Remise de notes · saisie', fn () => $this->grading->setCell(
            $request->user(), $course, $d['year'], isset($d['semester']) ? (int) $d['semester'] : null, $student, $d['field'],
            isset($d['value']) ? (string) $d['value'] : null, $this->clientAt($d), $this->force($d),
        ));
    }

    public function submit(Request $request, int $course): JsonResponse
    {
        $d = $request->validate(['year' => self::YEAR, 'semester' => ['nullable', 'integer', 'between:1,4']]);

        return $this->write($request, 'Remise de notes · transmission', fn () => $this->grading->submit(
            $request->user(), $course, $d['year'], isset($d['semester']) ? (int) $d['semester'] : null,
        ));
    }

    /** @return array<string, list<mixed>> */
    private function offline(): array
    {
        return ['client_at' => ['nullable', 'integer', 'min:0'], 'force' => ['nullable', 'boolean']];
    }

    private function clientAt(array $d): ?int
    {
        return isset($d['client_at']) ? (int) $d['client_at'] : null;
    }

    private function force(array $d): bool
    {
        return (bool) ($d['force'] ?? false);
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
