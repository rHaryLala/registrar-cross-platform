<?php

namespace Tests\Feature\Quiz;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesQuizData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Le serveur crée la partie, juge chaque réponse et calcule seul le score. */
class QuizPlayTest extends TestCase
{
    use MakesQuizData;
    use MakesStudentData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Indian/Antananarivo'));
        $this->makeQuestions(12);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function postAs(User $u, string $url, array $body = [])
    {
        return $this->actingAs($u, 'web')->fromApp()->postJson($url, $body);
    }

    public function test_starting_a_party_serves_ten_questions_but_only_the_first_without_any_answer_key(): void
    {
        $u = $this->makeStudent('800');

        $res = $this->postAs($u, '/api/v1/me/quiz/parties')->assertCreated();

        $this->assertSame(10, $res->json('data.total'));
        $this->assertSame(1, $res->json('data.question.position'));
        $this->assertCount(3, $res->json('data.question.options'));
        $this->assertStringNotContainsString('bonne_reponse', $res->getContent());
        $this->assertStringNotContainsString('correct_answer', $res->getContent());
        $this->assertSame(10, DB::table('t_quiz_partie_question')->where('partie_id', $res->json('data.party_id'))->count());
    }

    public function test_options_of_a_question_are_the_right_answer_and_the_two_wrong_ones(): void
    {
        $u = $this->makeStudent('801');
        $party = $this->startParty($u);

        $text = $party['question']['text'];
        $n = (int) filter_var($text, FILTER_SANITIZE_NUMBER_INT);
        $this->assertEqualsCanonicalizing(["Bonne {$n}", "Fausse A{$n}", "Fausse B{$n}"], $party['question']['options']);
    }

    public function test_the_same_question_is_never_drawn_twice_in_a_party(): void
    {
        $u = $this->makeStudent('802');
        $party = $this->startParty($u);

        $ids = DB::table('t_quiz_partie_question')->where('partie_id', $party['party_id'])->pluck('question_id');
        $this->assertCount(10, $ids->unique());
    }

    public function test_a_right_answer_is_confirmed_and_the_next_question_is_served(): void
    {
        $u = $this->makeStudent('803');
        $party = $this->startParty($u);

        $res = $this->answerCurrent($u, $party['party_id'], 1, true)->assertOk();

        $this->assertTrue($res->json('data.correct'));
        $this->assertSame(1, $res->json('data.score'));
        $this->assertFalse($res->json('data.finished'));
        $this->assertSame(2, $res->json('data.next.position'));
        $this->assertStringNotContainsString('correct_answer', json_encode($res->json('data.next')));
    }

    public function test_a_wrong_answer_reveals_the_right_one_and_scores_nothing(): void
    {
        $u = $this->makeStudent('804');
        $party = $this->startParty($u);

        $res = $this->answerCurrent($u, $party['party_id'], 1, false)->assertOk();

        $this->assertFalse($res->json('data.correct'));
        $this->assertSame(0, $res->json('data.score'));
        $this->assertStringStartsWith('Bonne ', $res->json('data.correct_answer'));
    }

    public function test_finishing_a_party_stores_the_server_computed_score_and_duration(): void
    {
        $u = $this->makeStudent('805');

        $id = $this->playParty($u, 7, 3);

        $row = DB::table('t_quiz_partie')->find($id);
        $this->assertSame(7, (int) $row->score);
        $this->assertNotNull($row->terminee_le);
        // 10 questions à ~3 s (+2 s d'attente dans answerCurrent) : la durée est mesurée par le serveur, jamais reçue du client.
        $this->assertGreaterThan(40000, (int) $row->duree_ms);
    }

    public function test_the_last_answer_returns_the_result_with_verdict_and_rank(): void
    {
        $u = $this->makeStudent('806');
        $party = $this->startParty($u);
        for ($p = 1; $p <= 9; $p++) {
            $this->answerCurrent($u, $party['party_id'], $p, true)->assertOk();
        }

        $res = $this->answerCurrent($u, $party['party_id'], 10, true)->assertOk();

        $this->assertTrue($res->json('data.finished'));
        $this->assertNull($res->json('data.next'));
        $this->assertSame(10, $res->json('data.result.score'));
        $this->assertSame(100, $res->json('data.result.percent'));
        $this->assertSame('perfect', $res->json('data.result.verdict'));
        $this->assertTrue($res->json('data.result.personal_best'));
        $this->assertSame(1, $res->json('data.result.rank_all'));
        $this->assertSame(1, $res->json('data.result.players_all'));
    }

    public function test_personal_best_is_only_flagged_when_the_previous_best_is_beaten(): void
    {
        $u = $this->makeStudent('870');
        $result = function (int $score) use ($u) {
            $party = $this->startParty($u);
            $last = null;
            for ($p = 1; $p <= 10; $p++) {
                $last = $this->answerCurrent($u, $party['party_id'], $p, $p <= $score)->assertOk();
            }

            return $last->json('data.result.personal_best');
        };

        $this->assertTrue($result(6));   // première partie : forcément un record
        $this->assertFalse($result(5));  // moins bien
        $this->assertFalse($result(6));  // égalité : pas un nouveau record
        $this->assertTrue($result(7));   // mieux
    }

    public function test_verdicts_follow_the_percentage(): void
    {
        foreach ([[10, 'perfect'], [8, 'excellent'], [6, 'good'], [4, 'fair'], [3, 'review']] as $i => [$score, $verdict]) {
            $u = $this->makeStudent('81'.$i);
            $party = $this->startParty($u);
            $last = null;
            for ($p = 1; $p <= 10; $p++) {
                $last = $this->answerCurrent($u, $party['party_id'], $p, $p <= $score)->assertOk();
            }
            $this->assertSame($verdict, $last->json('data.result.verdict'), "{$score}/10");
        }
    }

    public function test_answering_faster_than_the_minimum_time_is_refused_and_does_not_count(): void
    {
        $u = $this->makeStudent('820');
        $party = $this->startParty($u);
        $opt = $party['question']['options'][0];

        $this->wait(0.5);
        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", ['position' => 1, 'answer' => $opt])
            ->assertStatus(422)->assertJsonPath('code', 'quiz.too_fast');

        $this->assertNull(DB::table('t_quiz_partie_question')->where('partie_id', $party['party_id'])->where('position', 1)->value('repondue_le'));
        // Un peu plus tard, la même question peut être répondue.
        $this->answerCurrent($u, $party['party_id'], 1, true)->assertOk();
    }

    public function test_the_boundary_is_exactly_eight_hundred_milliseconds(): void
    {
        $u = $this->makeStudent('821');
        $party = $this->startParty($u);
        $opt = $party['question']['options'][0];

        $this->wait(0.799);
        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", ['position' => 1, 'answer' => $opt])->assertStatus(422);
        $this->wait(0.001);
        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", ['position' => 1, 'answer' => $opt])->assertOk();
    }

    public function test_the_clock_of_a_question_starts_when_the_previous_one_is_answered(): void
    {
        $u = $this->makeStudent('822');
        $party = $this->startParty($u);
        $this->answerCurrent($u, $party['party_id'], 1, true)->assertOk();
        $opt = DB::table('t_quiz_partie_question')->where('partie_id', $party['party_id'])->where('position', 2)->value('options');

        // Réponse à la question 2 immédiatement après avoir reçu la 2 : refusée.
        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", ['position' => 2, 'answer' => json_decode($opt, true)[0]])
            ->assertStatus(422)->assertJsonPath('code', 'quiz.too_fast');
    }

    public function test_questions_must_be_answered_in_order(): void
    {
        $u = $this->makeStudent('823');
        $party = $this->startParty($u);
        $this->wait();

        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", ['position' => 3, 'answer' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'quiz.out_of_order');
    }

    public function test_a_question_cannot_be_answered_twice(): void
    {
        $u = $this->makeStudent('824');
        $party = $this->startParty($u);
        $this->answerCurrent($u, $party['party_id'], 1, true)->assertOk();

        // Rejouer la position 1 : refusé, le score ne change pas.
        $this->answerCurrent($u, $party['party_id'], 1, true)->assertStatus(409)->assertJsonPath('code', 'quiz.out_of_order');
        $this->assertSame(1, DB::table('t_quiz_partie_question')->where('partie_id', $party['party_id'])->where('correcte', 1)->count());
    }

    public function test_an_answer_that_is_not_one_of_the_three_options_is_refused(): void
    {
        $u = $this->makeStudent('825');
        $party = $this->startParty($u);
        $this->wait();

        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", ['position' => 1, 'answer' => 'Une réponse inventée'])
            ->assertStatus(422)->assertJsonPath('code', 'quiz.invalid_answer');
    }

    public function test_a_finished_party_accepts_no_more_answers(): void
    {
        $u = $this->makeStudent('826');
        $id = $this->playParty($u, 10);

        $this->wait();
        $this->postAs($u, "/api/v1/me/quiz/parties/{$id}/reponses", ['position' => 10, 'answer' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'quiz.finished');
        $this->assertSame(10, (int) DB::table('t_quiz_partie')->where('id', $id)->value('score'));
    }

    public function test_a_party_expires_after_thirty_minutes_and_never_counts(): void
    {
        $u = $this->makeStudent('827');
        $party = $this->startParty($u);

        $this->wait(29 * 60);
        $this->answerCurrent($u, $party['party_id'], 1, true)->assertOk(); // encore valide à 29 min + 2 s

        $this->wait(60);
        $this->answerCurrent($u, $party['party_id'], 2, true)->assertStatus(409)->assertJsonPath('code', 'quiz.expired');
        $this->assertNull(DB::table('t_quiz_partie')->where('id', $party['party_id'])->value('terminee_le'));
    }

    public function test_starting_a_new_party_abandons_the_previous_one(): void
    {
        $u = $this->makeStudent('828');
        $first = $this->startParty($u);
        $this->wait();
        $second = $this->startParty($u);

        $this->answerCurrent($u, $first['party_id'], 1, true)->assertStatus(409)->assertJsonPath('code', 'quiz.expired');
        $this->answerCurrent($u, $second['party_id'], 1, true)->assertOk();
    }

    public function test_an_unfinished_party_does_not_appear_in_stats_or_ranking(): void
    {
        $u = $this->makeStudent('829');
        $party = $this->startParty($u);
        $this->answerCurrent($u, $party['party_id'], 1, true);

        $this->actingAs($u, 'web')->fromApp()->getJson('/api/v1/me/quiz')->assertOk()
            ->assertJsonPath('data.rounds_played', 0)->assertJsonPath('data.best', null);
        $this->actingAs($u, 'web')->fromApp()->getJson('/api/v1/me/quiz/classement')->assertOk()
            ->assertJsonPath('data.entries', [])->assertJsonPath('data.me', null);
    }

    public function test_a_player_cannot_answer_in_another_players_party(): void
    {
        $a = $this->makeStudent('830');
        $b = $this->makeStudent('831', 'Randria', 'Hery');
        $party = $this->startParty($a);

        $this->answerCurrent($b, $party['party_id'], 1, true)->assertStatus(404)->assertJsonPath('code', 'quiz.not_found');
        $this->assertNull(DB::table('t_quiz_partie_question')->where('partie_id', $party['party_id'])->where('position', 1)->value('repondue_le'));
    }

    public function test_the_client_cannot_provide_a_score_or_a_duration(): void
    {
        $u = $this->makeStudent('832');
        $party = $this->startParty($u);

        $this->wait();
        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", [
            'position' => 1, 'answer' => $party['question']['options'][0], 'score' => 10, 'duree_ms' => 1,
        ])->assertOk();
        $this->assertNull(DB::table('t_quiz_partie')->where('id', $party['party_id'])->value('score'));

        // Il n'existe pas de route « enregistrer mon score ».
        $this->postAs($u, '/api/v1/me/quiz/scores', ['score' => 10])->assertStatus(404);
    }

    public function test_only_active_questions_are_drawn(): void
    {
        DB::table('t_quiz_question')->update(['actif' => 0]);
        DB::table('t_quiz_question')->limit(10)->update(['actif' => 1]);
        $u = $this->makeStudent('833');
        $party = $this->startParty($u);

        $used = DB::table('t_quiz_partie_question as pq')->join('t_quiz_question as q', 'q.id', '=', 'pq.question_id')
            ->where('pq.partie_id', $party['party_id'])->pluck('q.actif')->unique()->all();
        $this->assertSame([1], array_map('intval', $used));
    }

    public function test_a_small_bank_gives_a_shorter_party_and_an_empty_bank_is_refused(): void
    {
        DB::table('t_quiz_question')->limit(9)->update(['actif' => 0]);
        $u = $this->makeStudent('834');

        $this->assertSame(3, $this->startParty($u)['total']);

        DB::table('t_quiz_question')->update(['actif' => 0]);
        $this->postAs($u, '/api/v1/me/quiz/parties')->assertStatus(503)->assertJsonPath('code', 'quiz.no_questions');
    }

    public function test_a_short_party_is_scored_over_its_own_length(): void
    {
        DB::table('t_quiz_question')->limit(9)->update(['actif' => 0]); // 3 questions actives
        $u = $this->makeStudent('835');
        $party = $this->startParty($u);
        $last = null;
        for ($p = 1; $p <= 3; $p++) {
            $last = $this->answerCurrent($u, $party['party_id'], $p, $p <= 2)->assertOk();
        }

        $this->assertSame([2, 3, 67], [$last->json('data.result.score'), $last->json('data.result.total'), $last->json('data.result.percent')]);
    }

    public function test_the_start_endpoint_is_throttled_to_thirty_parties_an_hour(): void
    {
        $u = $this->makeStudent('836');
        for ($i = 0; $i < 30; $i++) {
            $this->postAs($u, '/api/v1/me/quiz/parties')->assertCreated();
        }

        $this->postAs($u, '/api/v1/me/quiz/parties')->assertStatus(429);
    }

    public function test_staff_and_anonymous_are_kept_out(): void
    {
        $this->getJson('/api/v1/me/quiz')->assertStatus(401);
        $this->postJson('/api/v1/me/quiz/parties')->assertStatus(401);

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff, 'web')->fromApp()->getJson('/api/v1/me/quiz')->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');
        $this->actingAs($staff, 'web')->fromApp()->postJson('/api/v1/me/quiz/parties')->assertStatus(403);
    }

    public function test_the_answer_payload_is_validated(): void
    {
        $u = $this->makeStudent('837');
        $party = $this->startParty($u);

        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", [])->assertStatus(422);
        $this->postAs($u, "/api/v1/me/quiz/parties/{$party['party_id']}/reponses", ['position' => 11, 'answer' => 'x'])->assertStatus(422);
        $this->postAs($u, '/api/v1/me/quiz/parties/abc/reponses', ['position' => 1, 'answer' => 'x'])->assertStatus(404);
    }
}
