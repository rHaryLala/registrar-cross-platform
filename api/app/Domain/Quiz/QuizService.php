<?php

namespace App\Domain\Quiz;

use App\Exceptions\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quiz UAZ : le SERVEUR est l'arbitre.
 *
 * Une partie est créée ici (10 questions tirées au hasard dans la banque active, réponses mélangées), puis le joueur répond
 * UNE question à la fois. Le score est calculé par le serveur à partir des réponses enregistrées : il n'existe aucun
 * point d'entrée « enregistrer mon score », donc personne ne peut s'attribuer un 10/10.
 *
 * Garde-fous (sans prétendre à l'infaillibilité : les réponses étaient lisibles dans le code de l'ancienne page) :
 *  - une réponse par question, dans l'ordre, et jamais moins de MIN_ANSWER_MS après l'affichage de la question ;
 *  - une partie expire au bout de PARTY_MINUTES ; démarrer une nouvelle partie abandonne la précédente ;
 *  - la bonne réponse n'est révélée qu'APRÈS la réponse du joueur ;
 *  - seules les parties TERMINÉES comptent dans les statistiques et le classement.
 */
final class QuizService
{
    public const QUESTIONS_PER_PARTY = 10;

    public const MIN_ANSWER_MS = 800;

    public const PARTY_MINUTES = 30;

    public const TOP = 20;

    /** @return array<string, mixed> */
    public function start(string $studentId): array
    {
        return DB::transaction(function () use ($studentId) {
            $now = Carbon::now();

            // Une seule partie en cours : la précédente est abandonnée (elle ne comptera jamais).
            DB::table('t_quiz_partie')->where('student_id', $studentId)->whereNull('terminee_le')
                ->where('expire_le', '>', $this->ts($now))->update(['expire_le' => $this->ts($now)]);

            $questions = DB::table('t_quiz_question')->where('actif', 1)->inRandomOrder()->limit(self::QUESTIONS_PER_PARTY)->get();
            if ($questions->isEmpty()) {
                throw new ApiProblem(503, 'quiz.no_questions', 'Quiz indisponible', 'Aucune question n’est disponible pour le moment.');
            }

            $partyId = DB::table('t_quiz_partie')->insertGetId([
                'student_id' => $studentId,
                'commencee_le' => $this->ts($now),
                'expire_le' => $this->ts($now->copy()->addMinutes(self::PARTY_MINUTES)),
                'nb_questions' => $questions->count(),
            ]);

            foreach ($questions->values() as $i => $q) {
                $options = [$q->bonne_reponse, $q->mauvaise_1, $q->mauvaise_2];
                shuffle($options);
                DB::table('t_quiz_partie_question')->insert([
                    'partie_id' => $partyId, 'position' => $i + 1, 'question_id' => $q->id,
                    'options' => json_encode($options, JSON_UNESCAPED_UNICODE),
                    'posee_le' => $i === 0 ? $this->ts($now) : null, // l'horloge d'une question démarre quand elle est montrée
                ]);
            }

            return [
                'party_id' => $partyId,
                'total' => $questions->count(),
                'expires_at' => $now->copy()->addMinutes(self::PARTY_MINUTES)->toIso8601String(),
                'question' => $this->questionPayload($partyId, 1),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function answer(string $studentId, int $partyId, int $position, string $answer): array
    {
        return DB::transaction(function () use ($studentId, $partyId, $position, $answer) {
            // Une partie d'un AUTRE joueur est « introuvable », jamais « interdite » : on ne révèle pas son existence.
            $party = DB::table('t_quiz_partie')->where('id', $partyId)->where('student_id', $studentId)->lockForUpdate()->first();
            if (! $party) {
                throw new ApiProblem(404, 'quiz.not_found', 'Partie introuvable', 'Cette partie n’existe pas.');
            }
            if ($party->terminee_le !== null) {
                throw new ApiProblem(409, 'quiz.finished', 'Partie terminée', 'Cette partie est déjà terminée.');
            }

            $now = Carbon::now();
            if (Carbon::parse($party->expire_le)->lte($now)) {
                throw new ApiProblem(409, 'quiz.expired', 'Partie expirée', 'Cette partie a expiré. Lancez-en une nouvelle.');
            }

            $current = DB::table('t_quiz_partie_question as pq')->join('t_quiz_question as q', 'q.id', '=', 'pq.question_id')
                ->where('pq.partie_id', $partyId)->whereNull('pq.repondue_le')->orderBy('pq.position')
                ->first(['pq.id', 'pq.position', 'pq.options', 'pq.posee_le', 'q.bonne_reponse']);

            if ((int) $current->position !== $position) {
                throw new ApiProblem(409, 'quiz.out_of_order', 'Question inattendue', 'Répondez à la question affichée.');
            }
            if (! in_array($answer, json_decode($current->options, true), true)) {
                throw new ApiProblem(422, 'quiz.invalid_answer', 'Réponse invalide', 'Choisissez l’une des trois réponses proposées.');
            }
            if ($this->ms($now) - $this->ms(Carbon::parse($current->posee_le)) < self::MIN_ANSWER_MS) {
                throw new ApiProblem(422, 'quiz.too_fast', 'Trop rapide', 'Prenez le temps de lire la question.');
            }

            $correct = $answer === $current->bonne_reponse;
            DB::table('t_quiz_partie_question')->where('id', $current->id)->update([
                'reponse' => $answer, 'correcte' => $correct ? 1 : 0, 'repondue_le' => $this->ts($now),
            ]);

            $base = [
                'correct' => $correct,
                'correct_answer' => $current->bonne_reponse, // révélée seulement maintenant
            ];

            if ($position < (int) $party->nb_questions) {
                DB::table('t_quiz_partie_question')->where('partie_id', $partyId)->where('position', $position + 1)->update(['posee_le' => $this->ts($now)]);

                return $base + [
                    'score' => $this->scoreSoFar($partyId), 'answered' => $position, 'total' => (int) $party->nb_questions,
                    'finished' => false, 'next' => $this->questionPayload($partyId, $position + 1), 'result' => null,
                ];
            }

            $score = $this->scoreSoFar($partyId);
            $duration = max(0, $this->ms($now) - $this->ms(Carbon::parse($party->commencee_le)));
            DB::table('t_quiz_partie')->where('id', $partyId)->update(['terminee_le' => $this->ts($now), 'score' => $score, 'duree_ms' => $duration]);

            return $base + [
                'score' => $score, 'answered' => $position, 'total' => (int) $party->nb_questions,
                'finished' => true, 'next' => null, 'result' => $this->result($studentId, $partyId, $score, (int) $party->nb_questions, $duration),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function profile(string $studentId): array
    {
        $rounds = DB::table('t_quiz_partie')->where('student_id', $studentId)->whereNotNull('terminee_le')
            ->orderByDesc('terminee_le')->get(['id', 'score', 'nb_questions', 'duree_ms', 'terminee_le']);

        $best = $rounds->sortBy([
            fn ($a, $b) => ($b->score / max(1, $b->nb_questions)) <=> ($a->score / max(1, $a->nb_questions)),
            fn ($a, $b) => $a->duree_ms <=> $b->duree_ms,
        ])->first();

        return [
            'rounds_played' => $rounds->count(),
            'best' => $best ? $this->round($best) : null,
            'average_percent' => $rounds->isEmpty() ? null : round($rounds->avg(fn ($r) => 100 * $r->score / max(1, $r->nb_questions)), 1),
            'last_rounds' => $rounds->take(5)->map(fn ($r) => $this->round($r))->values()->all(),
            'visible_in_ranking' => $this->isVisible($studentId),
            'available_questions' => DB::table('t_quiz_question')->where('actif', 1)->count(),
        ];
    }

    /**
     * Classement : la MEILLEURE partie terminée de chaque joueur (pourcentage, puis temps le plus court), sur « tout » ou « week ».
     * Les joueurs qui ont choisi de ne pas apparaître n'y figurent pas et ne comptent pas dans les rangs.
     *
     * @return array<string, mixed>
     */
    public function leaderboard(string $studentId, string $period): array
    {
        $since = $period === 'week' ? Carbon::now()->startOfWeek(Carbon::MONDAY) : null;
        $rows = $this->rankedRows($since);
        $visible = $this->isVisible($studentId);

        $top = $rows->take(self::TOP);
        $mine = $visible ? $rows->firstWhere('student_id', $studentId) : null;
        $names = $this->displayNames($top->pluck('student_id')->push($mine?->student_id)->filter()->unique()->all());

        $entry = fn ($r) => [
            'rank' => (int) $r->rang,
            'name' => $names[$r->student_id] ?? 'Joueur',
            'score' => (int) $r->score,
            'total' => (int) $r->nb_questions,
            'percent' => (float) $r->pct,
            'duration_ms' => (int) $r->duree_ms,
            'rounds_played' => (int) $r->parties,
            'average_percent' => round((float) $r->moyenne, 1),
            'is_me' => $r->student_id === $studentId,
        ];

        return [
            'period' => $period === 'week' ? 'week' : 'all',
            'players' => $rows->count(),
            'visible_in_ranking' => $visible,
            'entries' => $top->map($entry)->values()->all(),
            'me' => $mine ? $entry($mine) : null,
        ];
    }

    public function setVisible(string $studentId, bool $visible): void
    {
        DB::table('t_quiz_joueur')->updateOrInsert(['student_id' => $studentId], ['classement_visible' => $visible ? 1 : 0, 'modifie_le' => $this->ts(Carbon::now())]);
    }

    // ------------------------------------------------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, object> */
    private function rankedRows(?Carbon $since)
    {
        $inner = DB::table('t_quiz_partie as p')
            ->leftJoin('t_quiz_joueur as j', 'j.student_id', '=', 'p.student_id')
            ->whereNotNull('p.terminee_le')
            ->whereRaw('COALESCE(j.classement_visible, 1) = 1')
            ->when($since, fn ($q) => $q->where('p.terminee_le', '>=', $this->ts($since)))
            ->selectRaw('p.student_id, p.score, p.nb_questions, p.duree_ms, p.terminee_le,
                ROUND(100 * p.score / p.nb_questions, 2) AS pct,
                COUNT(*) OVER (PARTITION BY p.student_id) AS parties,
                AVG(100 * p.score / p.nb_questions) OVER (PARTITION BY p.student_id) AS moyenne,
                ROW_NUMBER() OVER (PARTITION BY p.student_id ORDER BY (p.score / p.nb_questions) DESC, p.duree_ms ASC, p.terminee_le ASC) AS rn');

        return DB::table(DB::raw('('.$inner->toSql().') as t'))->mergeBindings($inner)
            ->where('t.rn', 1)
            ->selectRaw('t.*, RANK() OVER (ORDER BY t.pct DESC, t.duree_ms ASC) AS rang')
            ->orderBy('rang')->orderBy('t.terminee_le')
            ->get();
    }

    /** « Fanja R. » : prénom et initiale du nom, jamais le nom complet. @param list<string> $ids @return array<string, string> */
    private function displayNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (DB::table('tbl_2024_etudiant')->whereIn('student_id', $ids)->orderBy('remove')->orderByDesc('annee_scolaire')
            ->get(['student_id', 'student_prenom', 'student_nom']) as $e) {
            $first = trim((string) $e->student_prenom);
            $last = trim((string) $e->student_nom);
            $names[$e->student_id] ??= trim($first.($last !== '' ? ' '.mb_strtoupper(mb_substr($last, 0, 1)).'.' : '')) ?: 'Joueur';
        }

        return $names;
    }

    /** @return array<string, mixed> */
    private function result(string $studentId, int $partyId, int $score, int $total, int $durationMs): array
    {
        $percent = (int) round(100 * $score / max(1, $total));
        $all = $this->leaderboard($studentId, 'all');
        $week = $this->leaderboard($studentId, 'week');

        // Meilleure partie AVANT celle-ci : est-ce un record personnel ?
        $previous = DB::table('t_quiz_partie')->where('student_id', $studentId)->whereNotNull('terminee_le')->where('id', '<>', $partyId)
            ->selectRaw('MAX(score / nb_questions) AS m')->value('m');

        return [
            'score' => $score, 'total' => $total, 'percent' => $percent, 'duration_ms' => $durationMs,
            'verdict' => match (true) {
                $percent === 100 => 'perfect', $percent >= 80 => 'excellent', $percent >= 60 => 'good', $percent >= 40 => 'fair', default => 'review',
            },
            'personal_best' => $previous === null || ($score / max(1, $total)) > (float) $previous,
            'rank_all' => $all['me']['rank'] ?? null,
            'rank_week' => $week['me']['rank'] ?? null,
            'players_all' => $all['players'],
            'players_week' => $week['players'],
        ];
    }

    /** @return array<string, mixed> */
    private function questionPayload(int $partyId, int $position): array
    {
        $r = DB::table('t_quiz_partie_question as pq')->join('t_quiz_question as q', 'q.id', '=', 'pq.question_id')
            ->where('pq.partie_id', $partyId)->where('pq.position', $position)->first(['pq.position', 'pq.options', 'q.question', 'q.icone', 'q.indice', 'q.categorie']);

        // JAMAIS de bonne réponse ici : seulement les trois options, dans l'ordre mélangé.
        return ['position' => (int) $r->position, 'text' => $r->question, 'icon' => $r->icone, 'hint' => $r->indice, 'category' => $r->categorie, 'options' => json_decode($r->options, true)];
    }

    private function scoreSoFar(int $partyId): int
    {
        return (int) DB::table('t_quiz_partie_question')->where('partie_id', $partyId)->where('correcte', 1)->count();
    }

    private function isVisible(string $studentId): bool
    {
        $v = DB::table('t_quiz_joueur')->where('student_id', $studentId)->value('classement_visible');

        return $v === null ? true : (bool) $v;
    }

    /** @return array<string, mixed> */
    private function round(object $r): array
    {
        return [
            'score' => (int) $r->score, 'total' => (int) $r->nb_questions,
            'percent' => (int) round(100 * $r->score / max(1, $r->nb_questions)),
            'duration_ms' => (int) $r->duree_ms,
            'finished_at' => Carbon::parse($r->terminee_le)->toIso8601String(),
        ];
    }

    private function ts(Carbon $c): string
    {
        return $c->format('Y-m-d H:i:s.v');
    }

    private function ms(Carbon $c): int
    {
        return $c->getTimestamp() * 1000 + (int) $c->format('v');
    }
}
