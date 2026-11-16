<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Directory\Directory;
use App\Domain\Directory\Dossiers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Les listes et les fiches du registrariat (lecture seule). Le droit est vérifié dans le domaine à chaque appel. */
class DirectoryController extends Controller
{
    public function __construct(private readonly Directory $directory, private readonly Dossiers $dossiers) {}

    public function index(Request $request, string $list): JsonResponse
    {
        return $this->json($this->directory->listing($request->user(), $list, $request->query()));
    }

    public function student(Request $request, int $id): JsonResponse
    {
        return $this->json($this->dossiers->student($request->user(), $id));
    }

    public function course(Request $request, int $id): JsonResponse
    {
        return $this->json($this->dossiers->course($request->user(), $id));
    }

    public function teacher(Request $request, int $id): JsonResponse
    {
        return $this->json($this->dossiers->teacher($request->user(), $id));
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
