<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\StudentCreation;
use App\Http\Controllers\Controller;
use App\Support\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Création d'un étudiant (niveaux 1 à 3). Le droit est vérifié dans le domaine à chaque appel ; chaque écriture laisse sa trace dans le journal d'activité.
 */
class StudentCreationController extends Controller
{
    public function __construct(private readonly StudentCreation $creation, private readonly AuditTrail $audit) {}

    public function options(Request $request): JsonResponse
    {
        return $this->json($this->creation->options($request->user()));
    }

    /** Le prochain matricule libre et l'adresse universitaire libre, pour une mention, un niveau et un nom. */
    public function suggest(Request $request): JsonResponse
    {
        $d = $request->validate([
            'mention' => ['required', 'string', 'max:10'], 'level' => ['required', 'integer', 'between:0,5'],
            'last_name' => ['nullable', 'string', 'max:100'], 'first_name' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->json($this->creation->suggest($request->user(), $d['mention'], (int) $d['level'], (string) ($d['last_name'] ?? ''), (string) ($d['first_name'] ?? '')));
    }

    public function fromApplication(Request $request, int $id): JsonResponse
    {
        return $this->json($this->creation->fromApplication($request->user(), $id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->creation->require($request->user());
        $level = (int) $request->input('level', 1);
        // L'adresse saisie à la main : espaces retirés et minuscules, comme dans l'ancien formulaire.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(str_replace(' ', '', $request->input('email')))]);
        }
        $text = fn (int $max = 150) => ['nullable', 'string', 'max:'.$max];
        $d = $request->validate([
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'application_id' => ['nullable', 'integer', 'min:1'],
            'last_name' => ['required', 'string', 'max:100'], 'first_name' => ['required', 'string', 'max:100'],
            'sex' => ['required', Rule::in([0, 1, '0', '1'])],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'], 'birth_place' => ['required', 'string', 'max:150'],
            'nationality' => ['required', 'string', 'max:100'], 'origin_country' => ['required', 'string', 'max:100'],
            'marital_status' => ['required', Rule::in(StudentCreation::MARITAL)], 'religion' => ['required', Rule::in(StudentCreation::RELIGIONS)],
            'spouse_name' => $text(100), 'children' => ['nullable', 'integer', 'between:0,30'],
            'cin_number' => $text(50), 'cin_date' => ['nullable', 'date_format:Y-m-d'], 'cin_region' => ['nullable', 'integer', 'exists:region,id'], 'visa_number' => $text(50),
            'phone' => $text(40), 'email' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9._\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            'region' => ['required', 'integer', 'exists:region,id'], 'address' => ['required', 'string', 'max:255'],
            'mention' => ['required', 'string', 'max:10'], 'track' => ['required', 'string', 'max:255'], 'level' => ['required', 'integer', 'between:0,5'],
            'semester' => ['nullable', Rule::in([1, 2, '1', '2'])], 'academic_year' => ['required', 'string', 'regex:/^\d{4} - \d{4}$/'],
            'status' => ['required', Rule::in(StudentCreation::STATUSES)], 'is_new' => ['nullable', 'boolean'], 'cafeteria' => ['nullable', Rule::in([0, 1, '0', '1', true, false])],
            'size' => ['nullable', Rule::in(array_merge([''], StudentCreation::SIZES))], 'student_id' => ['nullable', 'string', 'max:20', 'regex:/^\d{1,9}[A-Za-z]{0,3}$/'],
            'bacc_date' => $level <= 3 ? ['nullable', 'date_format:Y-m-d', 'required_with:bacc_series'] : ['prohibited'], 'bacc_series' => $level <= 3 ? ['nullable', Rule::in(StudentCreation::SERIES), 'required_with:bacc_date'] : ['prohibited'],
            'previous_degree' => $level > 3 ? ['nullable', 'string', 'max:100', 'required_with:previous_degree_date'] : ['prohibited'], 'previous_degree_date' => $level > 3 ? ['nullable', 'date_format:Y-m-d', 'required_with:previous_degree'] : ['prohibited'],
            'father_name' => $text(150), 'father_job' => $text(100), 'mother_name' => $text(150), 'mother_job' => $text(100), 'parent_phone' => $text(40), 'parent_address' => $text(255),
            'sponsor_last_name' => $text(100), 'sponsor_first_name' => $text(100), 'sponsor_phone' => $text(40), 'sponsor_address' => $text(255),
        ]);

        $this->audit->reset();
        $result = $this->creation->create($request->user(), $d, $d['token']);
        $this->audit->commit($request, $request->user(), 'Étudiants · création', 200, collect($request->except(['_token', 'token']))->only(['last_name', 'first_name', 'mention', 'level', 'academic_year', 'application_id'])->all());

        return response()->json(['data' => $result], $result['already'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function google(Request $request, int $id): JsonResponse
    {
        return $this->json($this->creation->googleStatus($request->user(), $id));
    }

    public function retryGoogle(Request $request, int $id): JsonResponse
    {
        $this->audit->reset();
        $result = $this->creation->retryGoogle($request->user(), $id);
        $this->audit->commit($request, $request->user(), 'Étudiants · compte Google relancé', 200, ['fiche_id' => $id]);

        return $this->json($result);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
