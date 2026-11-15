<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\News\SiteNews;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteNewsController extends Controller
{
    use ResolvesStudent;

    public function __construct(private readonly SiteNews $news) {}

    /** Les actualités du site de l'université (copie locale), la plus récente d'abord. */
    public function index(Request $request): JsonResponse
    {
        $this->studentId($request);   // espace étudiant : même garde que le reste

        return response()->json(['data' => $this->news->list()]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $this->studentId($request);

        return response()->json(['data' => $this->news->find($slug)
            ?? throw new ApiProblem(404, 'news.not_found', 'Actualité introuvable', 'Cet article n\'existe pas ou n\'est plus affiché.')]);
    }
}
