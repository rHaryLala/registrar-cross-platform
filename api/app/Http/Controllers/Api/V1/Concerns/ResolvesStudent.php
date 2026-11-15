<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Exceptions\ApiProblem;
use App\Support\Role;
use Illuminate\Http\Request;

trait ResolvesStudent
{
    /** Le matricule de l'étudiant connecté : toujours celui du compte, jamais un paramètre de la requête. */
    protected function studentId(Request $request): string
    {
        $user = $request->user();

        if ($user->role() !== Role::Student || empty($user->student_id)) {
            throw new ApiProblem(403, 'forbidden.student_only', 'Espace réservé aux étudiants', 'Ce contenu n’existe que pour un compte étudiant.');
        }

        return (string) $user->student_id;
    }
}
