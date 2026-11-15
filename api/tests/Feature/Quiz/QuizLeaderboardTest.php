<?php

namespace Tests\Feature\Quiz;

use Illuminate\Support\Carbon;
use Tests\Concerns\MakesQuizData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Classement : meilleure partie de chaque joueur, égalités, semaine, confidentialité, noms abrégés. */
class QuizLeaderboardTest extends TestCase
{
    use MakesQuizData;
    use MakesStudentData;

    protected function setUp(): void
    {
        parent::setUp();
        // Lundi 16 novembre 2026, 10 h (heure de Madagascar).
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Indian/Antananarivo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function board(\App\Models\User $u, string $period = 'all')
    {
        return $this->actingAs($u, 'web')->fromApp()->getJson('/api/v1/me/quiz/classement?period='.$period)->assertOk();
    }

    public function test_each_player_appears_once_with_their_best_party(): void
    {
        $a = $this->makeStudent('900', 'Rakoto', 'Fanja');
        $this->finishedParty('900', 5, 60000);
        $this->finishedParty('900', 9, 80000); // meilleure
        $this->finishedParty('900', 7, 20000);

        $res = $this->board($a);

        $this->assertCount(1, $res->json('data.entries'));
        $this->assertEquals([9, 10, 90.0, 3], [
            $res->json('data.entries.0.score'), $res->json('data.entries.0.total'),
            $res->json('data.entries.0.percent'), $res->json('data.entries.0.rounds_played'),
        ]);
        // Moyenne de toutes les parties terminées : (50 + 90 + 70) / 3 = 70.
        $this->assertEquals(70.0, $res->json('data.entries.0.average_percent'));
    }

    public function test_with_equal_scores_a_players_best_party_is_the_fastest_one(): void
    {
        $a = $this->makeStudent('970');
        $this->finishedParty('970', 8, 90000);
        $this->finishedParty('970', 8, 25000);
        $this->finishedParty('970', 8, 60000);

        $this->assertSame(25000, $this->board($a)->json('data.entries.0.duration_ms'));
    }

    public function test_a_players_best_party_compares_percentages_not_raw_scores(): void
    {
        $a = $this->makeStudent('971');
        $this->finishedParty('971', 9, 10000, null, 10);  // 90 %, score brut plus élevé
        $this->finishedParty('971', 3, 10000, null, 3);   // 100 %, score brut plus bas

        $e = $this->board($a)->json('data.entries.0');
        $this->assertSame([3, 3], [$e['score'], $e['total']]);
    }

    public function test_ranking_orders_by_percent_then_by_shorter_time(): void
    {
        $viewer = $this->makeStudent('901', 'Aa', 'Viewer');
        $this->makeStudent('902', 'Bb', 'Lent');
        $this->makeStudent('903', 'Cc', 'Rapide');
        $this->makeStudent('904', 'Dd', 'Top');
        $this->finishedParty('901', 6, 10000);
        $this->finishedParty('902', 8, 90000);
        $this->finishedParty('903', 8, 30000);
        $this->finishedParty('904', 10, 99000);

        $res = $this->board($viewer);

        $this->assertSame(['Top D.', 'Rapide C.', 'Lent B.', 'Viewer A.'], array_column($res->json('data.entries'), 'name'));
        $this->assertSame([1, 2, 3, 4], array_column($res->json('data.entries'), 'rank'));
    }

    public function test_exact_ties_share_the_same_rank_and_the_next_rank_skips(): void
    {
        $viewer = $this->makeStudent('905', 'Aa', 'Un');
        $this->makeStudent('906', 'Bb', 'Deux');
        $this->makeStudent('907', 'Cc', 'Trois');
        $this->finishedParty('905', 9, 50000);
        $this->finishedParty('906', 9, 50000);
        $this->finishedParty('907', 7, 50000);

        $this->assertSame([1, 1, 3], array_column($this->board($viewer)->json('data.entries'), 'rank'));
    }

    public function test_percentages_compare_parties_of_different_lengths_fairly(): void
    {
        $viewer = $this->makeStudent('908', 'Aa', 'Court');
        $this->makeStudent('909', 'Bb', 'Long');
        $this->finishedParty('908', 3, 10000, null, 3);   // 3/3 = 100 %
        $this->finishedParty('909', 9, 10000, null, 10);  // 90 %

        $this->assertSame(['Court A.', 'Long B.'], array_column($this->board($viewer)->json('data.entries'), 'name'));
    }

    public function test_the_week_view_starts_on_monday_and_excludes_older_parties(): void
    {
        $viewer = $this->makeStudent('910', 'Aa', 'Ancien');
        $this->makeStudent('911', 'Bb', 'Récent');
        $this->makeStudent('912', 'Cc', 'Limite');
        $this->finishedParty('910', 10, 10000, '2026-11-15 23:59:59.000'); // dimanche : semaine précédente
        $this->finishedParty('911', 5, 10000, '2026-11-16 09:00:00.000');  // lundi
        $this->finishedParty('912', 4, 10000, '2026-11-16 00:00:00.000');  // lundi minuit pile : inclus

        $week = $this->board($viewer, 'week');

        $this->assertSame(['Récent B.', 'Limite C.'], array_column($week->json('data.entries'), 'name'));
        $this->assertSame('week', $week->json('data.period'));
        $this->assertSame(3, $this->board($viewer, 'all')->json('data.players'));
    }

    public function test_a_players_best_of_the_week_can_differ_from_their_best_ever(): void
    {
        $a = $this->makeStudent('913', 'Aa', 'Joueur');
        $this->finishedParty('913', 10, 10000, '2026-11-01 10:00:00.000');
        $this->finishedParty('913', 6, 10000, '2026-11-16 08:00:00.000');

        $this->assertSame(10, $this->board($a, 'all')->json('data.entries.0.score'));
        $this->assertSame(6, $this->board($a, 'week')->json('data.entries.0.score'));
    }

    public function test_only_the_top_twenty_are_listed_and_my_own_rank_is_added_below(): void
    {
        $me = $this->makeStudent('920', 'Zz', 'Moi');
        $this->finishedParty('920', 1, 10000);
        foreach (range(1, 22) as $i) {
            $this->makeStudent('93'.sprintf('%02d', $i), 'N'.$i, 'P'.$i);
            $this->finishedParty('93'.sprintf('%02d', $i), 5 + ($i % 5), 10000 + $i);
        }

        $res = $this->board($me);

        $this->assertCount(20, $res->json('data.entries'));
        $this->assertSame(23, $res->json('data.players'));
        $this->assertSame(23, $res->json('data.me.rank'));
        $this->assertTrue($res->json('data.me.is_me'));
        $this->assertNotContains(true, array_column($res->json('data.entries'), 'is_me'));
    }

    public function test_when_i_am_in_the_top_i_am_flagged_in_the_list(): void
    {
        $me = $this->makeStudent('940', 'Zz', 'Moi');
        $this->finishedParty('940', 10, 10000);

        $res = $this->board($me);

        $this->assertTrue($res->json('data.entries.0.is_me'));
        $this->assertSame(1, $res->json('data.me.rank'));
    }

    public function test_a_player_who_opted_out_is_hidden_and_does_not_take_a_rank(): void
    {
        $viewer = $this->makeStudent('950', 'Aa', 'Voit');
        $hidden = $this->makeStudent('951', 'Bb', 'Discret');
        $this->makeStudent('952', 'Cc', 'Visible');
        $this->finishedParty('950', 5, 10000);
        $this->finishedParty('951', 10, 10000);
        $this->finishedParty('952', 8, 10000);

        $this->actingAs($hidden, 'web')->fromApp()->putJson('/api/v1/me/quiz/reglages', ['visible_in_ranking' => false])
            ->assertOk()->assertJsonPath('data.visible_in_ranking', false);

        $res = $this->board($viewer);
        $this->assertSame(['Visible C.', 'Voit A.'], array_column($res->json('data.entries'), 'name'));
        $this->assertSame(2, $res->json('data.players'));
        $this->assertSame(2, $res->json('data.me.rank')); // pas 3 : le joueur masqué ne prend pas de place

        // Le joueur masqué garde ses propres statistiques mais n'a pas de rang.
        $mine = $this->board($hidden);
        $this->assertFalse($mine->json('data.visible_in_ranking'));
        $this->assertNull($mine->json('data.me'));
        $this->actingAs($hidden, 'web')->fromApp()->getJson('/api/v1/me/quiz')->assertJsonPath('data.rounds_played', 1)->assertJsonPath('data.visible_in_ranking', false);
    }

    public function test_opting_back_in_restores_the_player(): void
    {
        $a = $this->makeStudent('953', 'Aa', 'Retour');
        $this->finishedParty('953', 7, 10000);
        $this->actingAs($a, 'web')->fromApp()->putJson('/api/v1/me/quiz/reglages', ['visible_in_ranking' => false])->assertOk();
        $this->assertCount(0, $this->board($a)->json('data.entries'));

        $this->actingAs($a, 'web')->fromApp()->putJson('/api/v1/me/quiz/reglages', ['visible_in_ranking' => true])->assertOk();
        $this->assertCount(1, $this->board($a)->json('data.entries'));
    }

    public function test_the_default_is_visible_and_the_setting_is_validated(): void
    {
        $a = $this->makeStudent('954');

        $this->actingAs($a, 'web')->fromApp()->getJson('/api/v1/me/quiz')->assertJsonPath('data.visible_in_ranking', true);
        $this->actingAs($a, 'web')->fromApp()->putJson('/api/v1/me/quiz/reglages', [])->assertStatus(422);
        $this->actingAs($a, 'web')->fromApp()->putJson('/api/v1/me/quiz/reglages', ['visible_in_ranking' => 'peut-être'])->assertStatus(422);
    }

    public function test_names_are_shortened_and_never_expose_the_full_surname_or_the_matricule(): void
    {
        $a = $this->makeStudent('955', 'Andrianampoinimerina', 'Soa');
        $this->finishedParty('955', 8, 10000);

        $res = $this->board($a);
        $json = $res->getContent();

        $this->assertSame('Soa A.', $res->json('data.entries.0.name'));
        $this->assertStringNotContainsString('Andrianampoinimerina', $json);
        $this->assertStringNotContainsString('955', $json);
        $this->assertStringNotContainsString('student_id', $json);
    }

    public function test_a_multibyte_initial_is_cut_on_a_character_not_a_byte(): void
    {
        $a = $this->makeStudent('956', 'Éloi', 'Zo');
        $this->finishedParty('956', 8, 10000);

        $this->assertSame('Zo É.', $this->board($a)->json('data.entries.0.name'));
    }

    public function test_profile_reports_best_average_history_and_available_questions(): void
    {
        $this->makeQuestions(4);
        $a = $this->makeStudent('957');
        foreach ([[4, 50000], [9, 70000], [9, 40000], [6, 10000], [8, 10000], [5, 10000]] as $i => [$score, $ms]) {
            $this->finishedParty('957', $score, $ms, Carbon::now()->addMinutes($i)->format('Y-m-d H:i:s.v'));
        }

        $res = $this->actingAs($a, 'web')->fromApp()->getJson('/api/v1/me/quiz')->assertOk();

        $this->assertSame(6, $res->json('data.rounds_played'));
        $this->assertSame([9, 40000], [$res->json('data.best.score'), $res->json('data.best.duration_ms')]); // 9/10 le plus rapide
        $this->assertEquals(profile_avg([4, 9, 9, 6, 8, 5]), $res->json('data.average_percent'));
        $this->assertCount(5, $res->json('data.last_rounds'));
        $this->assertSame(5, $res->json('data.last_rounds.0.score')); // la plus récente d'abord
        $this->assertSame(4, $res->json('data.available_questions'));
    }

    public function test_a_second_player_with_the_same_score_does_not_steal_my_rank_through_a_later_finish(): void
    {
        // Égalité parfaite (même score, même durée) : le premier arrivé garde l'avantage dans l'ordre d'affichage.
        $a = $this->makeStudent('960', 'Aa', 'Premier');
        $b = $this->makeStudent('961', 'Bb', 'Second');
        $this->finishedParty('960', 8, 30000, '2026-11-16 08:00:00.000');
        $this->finishedParty('961', 8, 30000, '2026-11-16 09:00:00.000');

        $this->assertSame(['Premier A.', 'Second B.'], array_column($this->board($b)->json('data.entries'), 'name'));
        $this->assertSame(1, $this->board($b)->json('data.me.rank')); // rang ex æquo, pas 2
    }
}

/** Moyenne des pourcentages sur 10 questions, arrondie à une décimale comme l'API. */
function profile_avg(array $scores): float
{
    return round(array_sum(array_map(fn ($s) => $s * 10, $scores)) / count($scores), 1);
}
