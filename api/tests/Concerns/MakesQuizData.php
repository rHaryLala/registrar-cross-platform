<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Questions et parties FICTIVES pour les tests du Quiz (base *_test uniquement). */
trait MakesQuizData
{
    protected function makeQuestions(int $n = 12): void
    {
        for ($i = 1; $i <= $n; $i++) {
            DB::table('t_quiz_question')->insert([
                'categorie' => 'Test', 'question' => "Question {$i} ?", 'bonne_reponse' => "Bonne {$i}",
                'mauvaise_1' => "Fausse A{$i}", 'mauvaise_2' => "Fausse B{$i}", 'icone' => '🙂', 'indice' => "Indice {$i}",
                'actif' => 1, 'empreinte' => sha1("test-question-{$i}-".uniqid('', true)),
            ]);
        }
    }

    /** Avance l'horloge de $seconds : une réponse trop rapide (< 0,8 s) est refusée. */
    protected function wait(float $seconds = 2): void
    {
        Carbon::setTestNow(Carbon::now()->addMilliseconds((int) round($seconds * 1000)));
    }

    /** @return array<string, mixed> la partie démarrée (data) */
    protected function startParty(User $user): array
    {
        return $this->actingAs($user, 'web')->fromApp()->postJson('/api/v1/me/quiz/parties')->assertCreated()->json('data');
    }

    /** Répond à la question courante ; $right = true donne la bonne réponse (lue en base, jamais dans la réponse de l'API). */
    protected function answerCurrent(User $user, int $party, int $position, bool $right): \Illuminate\Testing\TestResponse
    {
        $row = DB::table('t_quiz_partie_question as pq')->join('t_quiz_question as q', 'q.id', '=', 'pq.question_id')
            ->where('pq.partie_id', $party)->where('pq.position', $position)->first(['pq.options', 'q.bonne_reponse']);
        $options = json_decode($row->options, true);
        $answer = $right ? $row->bonne_reponse : collect($options)->first(fn ($o) => $o !== $row->bonne_reponse);

        $this->wait();

        return $this->actingAs($user, 'web')->fromApp()->postJson("/api/v1/me/quiz/parties/{$party}/reponses", ['position' => $position, 'answer' => $answer]);
    }

    /** Joue une partie complète avec $correct bonnes réponses ; renvoie l'id de la partie. */
    protected function playParty(User $user, int $correct, float $secondsPerQuestion = 2): int
    {
        $party = $this->startParty($user);
        for ($p = 1; $p <= $party['total']; $p++) {
            $this->wait($secondsPerQuestion);
            $this->answerCurrent($user, $party['party_id'], $p, $p <= $correct)->assertOk();
        }

        return $party['party_id'];
    }

    /** Insère directement une partie terminée (classement, semaine, égalités) sans passer par l'API. */
    protected function finishedParty(string $studentId, int $score, int $durationMs, ?string $endedAt = null, int $total = 10): int
    {
        $end = $endedAt ?? Carbon::now()->format('Y-m-d H:i:s.v');

        return DB::table('t_quiz_partie')->insertGetId([
            'student_id' => $studentId, 'commencee_le' => $end, 'terminee_le' => $end, 'expire_le' => $end,
            'nb_questions' => $total, 'score' => $score, 'duree_ms' => $durationMs,
        ]);
    }
}
