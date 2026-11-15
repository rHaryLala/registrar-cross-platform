<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Student\Profile;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ResolvesStudent;

    /** Mes informations : MA fiche, en lecture seule. Aucun identifiant en paramètre : on ne peut jamais lire celle d'un autre. */
    public function show(Request $request, Profile $profile): JsonResponse
    {
        $data = $profile->for($this->studentId($request))
            ?? throw new ApiProblem(404, 'profile.not_found', 'Fiche introuvable', 'Aucune fiche d’étudiant n’est associée à votre compte. Contactez le registrariat.');

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
