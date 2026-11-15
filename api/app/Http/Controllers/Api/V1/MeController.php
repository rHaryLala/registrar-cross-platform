<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Student\Attendance;
use App\Domain\Student\GradeBook;
use App\Domain\Student\StudentHome;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Support\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /** Accueil étudiant : profil, moyenne, dernières notes publiées, sessions en attente. */
    public function home(Request $request, StudentHome $home): JsonResponse
    {
        return response()->json(['data' => $home->for($this->studentId($request))]);
    }

    /** « Mes notes » : sessions publiées, cours, moyennes par session et cumulative, notes de promotion. */
    public function grades(Request $request, GradeBook $grades): JsonResponse
    {
        return response()->json(['data' => $grades->for($this->studentId($request))]);
    }

    /** « Mon assiduité » : présences, retards et absences par cours, et le seuil des 15 % (Bulletin académique §472 à §476). */
    public function attendance(Request $request, Attendance $attendance): JsonResponse
    {
        return response()->json(['data' => $attendance->for($this->studentId($request))]);
    }

    private function studentId(Request $request): string
    {
        $user = $request->user();

        if ($user->role() !== Role::Student || empty($user->student_id)) {
            throw new ApiProblem(403, 'forbidden.student_only', 'Espace réservé aux étudiants', 'Ce contenu n’existe que pour un compte étudiant.');
        }

        return (string) $user->student_id;
    }
}
