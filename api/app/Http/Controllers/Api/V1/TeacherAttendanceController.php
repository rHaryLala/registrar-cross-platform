<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Teacher\TeacherAttendance;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Présence : les registres de l'enseignant (lecture, puis écritures, qui passent toutes par `write()` : journal d'activité inclus).
 *
 * Aucun identifiant d'enseignant n'est lu dans la requête : c'est le compte connecté qui décide, et la propriété du cours est
 * vérifiée dans le domaine à chaque appel.
 */
class TeacherAttendanceController extends Controller
{
    private const YEAR = ['required', 'string', 'max:20', 'regex:/^\d{4}\s?-\s?\d{4}$/'];

    private const TIME = ['nullable', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'];

    public function __construct(private readonly TeacherAttendance $attendance, private readonly AuditTrail $audit) {}

    public function courses(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['nullable', 'string', 'max:20', 'regex:/^\d{4}\s?-\s?\d{4}$/']]);

        return $this->read($this->attendance->courses($request->user(), $data['year'] ?? null));
    }

    public function register(Request $request, int $course): JsonResponse
    {
        $data = $request->validate(['year' => self::YEAR, 'semester' => ['nullable', 'integer', 'between:1,4']]);

        return $this->read($this->attendance->register($request->user(), $course, $data['year'], isset($data['semester']) ? (int) $data['semester'] : null));
    }

    public function session(Request $request, int $session): JsonResponse
    {
        return $this->read($this->attendance->session($request->user(), $session));
    }

    public function setPlanned(Request $request, int $course): JsonResponse
    {
        $d = $request->validate(['year' => self::YEAR, 'semester' => ['nullable', 'integer', 'between:1,4'], 'planned' => ['present', 'nullable', 'integer', 'between:1,200']] + $this->offline());

        return $this->write($request, 'Présence · séances prévues', fn () => $this->attendance->setPlanned(
            $request->user(), $course, $d['year'], isset($d['semester']) ? (int) $d['semester'] : null, isset($d['planned']) ? (int) $d['planned'] : null, $this->clientAt($d), $this->force($d),
        ));
    }

    public function createSession(Request $request, int $course): JsonResponse
    {
        $d = $request->validate([
            'year' => self::YEAR, 'semester' => ['nullable', 'integer', 'between:1,4'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'planned_time' => self::TIME, 'teacher_arrival' => self::TIME, 'warned' => ['nullable', 'boolean'],
        ] + $this->offline());

        return $this->write($request, 'Présence · ouvrir une séance', fn () => $this->attendance->createSession(
            $request->user(), $course, $d['year'], isset($d['semester']) ? (int) $d['semester'] : null, $d['date'],
            $this->time($d['planned_time'] ?? null), $this->time($d['teacher_arrival'] ?? null), (bool) ($d['warned'] ?? false), $this->clientAt($d), $this->force($d),
        ), 201);
    }

    public function updateSession(Request $request, int $session): JsonResponse
    {
        $d = $request->validate([
            'planned_time' => array_merge(['present'], self::TIME), 'teacher_arrival' => array_merge(['present'], self::TIME), 'warned' => ['present', 'boolean'], 'comment' => ['nullable', 'string', 'max:255'],
        ] + $this->offline());

        return $this->write($request, 'Présence · modifier une séance', fn () => $this->attendance->updateSession(
            $request->user(), $session, $this->time($d['planned_time'] ?? null), $this->time($d['teacher_arrival'] ?? null), (bool) $d['warned'], $d['comment'] ?? null, $this->clientAt($d), $this->force($d),
        ));
    }

    public function deleteSession(Request $request, int $session): JsonResponse
    {
        $d = $request->validate($this->offline());

        return $this->write($request, 'Présence · supprimer une séance', fn () => $this->attendance->deleteSession($request->user(), $session, $this->clientAt($d), $this->force($d)));
    }

    public function mark(Request $request, int $session, string $student): JsonResponse
    {
        $d = $request->validate([
            'status' => ['present', 'nullable', Rule::in(['present', 'retard', 'absent'])], 'minutes_late' => ['nullable', 'integer', 'between:1,240'],
        ] + $this->offline());

        return $this->write($request, 'Présence · pointer', fn () => $this->attendance->mark(
            $request->user(), $session, $student, $d['status'] ?? null, isset($d['minutes_late']) ? (int) $d['minutes_late'] : null, $this->clientAt($d), $this->force($d),
        ));
    }

    public function justify(Request $request, int $session, string $student): JsonResponse
    {
        $d = $request->validate(['justification' => ['present', 'nullable', Rule::in(['medical', 'autre'])]] + $this->offline());

        return $this->write($request, 'Présence · justificatif', fn () => $this->attendance->justify($request->user(), $session, $student, $d['justification'] ?? null, $this->clientAt($d), $this->force($d)));
    }

    public function markAllPresent(Request $request, int $session): JsonResponse
    {
        $d = $request->validate($this->offline());

        return $this->write($request, 'Présence · tous présents', fn () => $this->attendance->markAllPresent($request->user(), $session, $this->clientAt($d), $this->force($d)));
    }

    // ------------------------------------------------------------------ outils

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

    /** « 8:30 » → « 08:30:00 » ; null reste null. */
    private function time(?string $t): ?string
    {
        if ($t === null || trim($t) === '') {
            return null;
        }
        [$h, $m] = array_pad(explode(':', trim($t)), 2, '0');

        return sprintf('%02d:%02d:00', (int) $h, (int) $m);
    }

    /** Une lecture n'est jamais gardée en cache : le registre bouge à chaque pointage. */
    private function read(array $data): JsonResponse
    {
        // server_time : l'horloge du serveur, pour que l'appareil date correctement ses écritures hors ligne (la sienne peut être fausse).
        return response()->json(['data' => $data, 'meta' => ['server_time' => (int) floor(microtime(true) * 1000)]])->header('Cache-Control', 'private, no-store');
    }

    /** Exécute une écriture, puis écrit au journal d'activité ce qu'elle a changé (jamais en cas d'échec : tout est annulé). */
    private function write(Request $request, string $label, callable $action, int $status = 200): JsonResponse
    {
        $this->audit->reset();   // rien ne traîne d'une action précédente ; si l'action échoue, tout est annulé et rien n'est écrit
        $result = $action();
        $this->audit->commit($request, $request->user(), $label, $status, $request->except(['_token']));

        return response()->json(['data' => $result], $status)->header('Cache-Control', 'private, no-store');
    }
}
