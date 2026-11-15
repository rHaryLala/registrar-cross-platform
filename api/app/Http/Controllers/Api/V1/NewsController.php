<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\News\News;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Support\SafeImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class NewsController extends Controller
{
    use ResolvesStudent;

    public function __construct(private readonly News $news) {}

    /** Les actualités visibles, épinglées d'abord ; `?category=` filtre. */
    public function index(Request $request): JsonResponse
    {
        $this->studentId($request);   // espace étudiant : même garde que le reste (les pages du personnel viendront avec leur lot)
        $data = $request->validate(['category' => ['nullable', Rule::in(array_keys(News::CATEGORIES))]]);

        return response()->json(['data' => $this->news->list($data['category'] ?? null)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->studentId($request);

        return response()->json(['data' => $this->news->find($id) ?? throw $this->notFound()]);
    }

    /** L'image d'une annonce visible, réduite (1 200 × 800 au plus) pour ne pas faire télécharger des mégaoctets au téléphone. */
    public function image(Request $request, int $id): Response
    {
        $this->studentId($request);
        $path = $this->news->imagePath($id) ?? throw $this->notFound();
        $jpeg = SafeImage::jpeg($path, 1200, 800) ?? throw $this->notFound();

        $response = response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(md5($path.'|'.filemtime($path)));
        $response->isNotModified($request);

        return $response;
    }

    private function notFound(): ApiProblem
    {
        return new ApiProblem(404, 'news.not_found', 'Annonce introuvable', 'Cette annonce n’existe pas ou n’est plus affichée.');
    }
}
