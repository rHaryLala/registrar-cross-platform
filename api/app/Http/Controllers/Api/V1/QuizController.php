<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Quiz\QuizService;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    use ResolvesStudent;

    public function __construct(private readonly QuizService $quiz) {}

    /** Mes statistiques de joueur, mes dernières parties et mon réglage de confidentialité. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quiz->profile($this->studentId($request))]);
    }

    /** Démarre une partie : 10 questions tirées par le serveur, la première est renvoyée. */
    public function start(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quiz->start($this->studentId($request))], 201);
    }

    /** Répond à la question affichée ; le serveur dit si c'est juste et renvoie la suivante (ou le résultat final). */
    public function answer(Request $request, int $party): JsonResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', 'min:1', 'max:'.QuizService::QUESTIONS_PER_PARTY],
            'answer' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->quiz->answer($this->studentId($request), $party, (int) $data['position'], $data['answer'])]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'in:all,week']]);

        return response()->json(['data' => $this->quiz->leaderboard($this->studentId($request), $data['period'] ?? 'all')]);
    }

    /** Apparaître (ou non) au classement des autres joueurs. */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['visible_in_ranking' => ['required', 'boolean']]);
        $this->quiz->setVisible($this->studentId($request), (bool) $data['visible_in_ranking']);

        return response()->json(['data' => ['visible_in_ranking' => (bool) $data['visible_in_ranking']]]);
    }
}
