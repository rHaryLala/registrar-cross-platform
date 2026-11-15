<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Student\StudentPhoto;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PhotoController extends Controller
{
    use ResolvesStudent;

    /** MA photo, réduite (JPEG). Il n'y a pas d'identifiant en paramètre : on ne peut jamais demander celle d'un autre. */
    public function show(Request $request, StudentPhoto $photos): Response
    {
        $jpeg = $photos->jpeg($this->studentId($request));

        if ($jpeg === null) {
            throw new ApiProblem(404, 'photo.not_found', 'Aucune photo', 'Aucune photo n’est enregistrée pour votre fiche.');
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
