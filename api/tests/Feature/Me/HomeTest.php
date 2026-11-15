<?php

namespace Tests\Feature\Me;

use App\Models\User;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/**
 * Accueil étudiant. Le point sensible : une note n'est visible que si sa session est publiée,
 * et un étudiant ne voit jamais les notes d'un autre.
 */
class HomeTest extends TestCase
{
    use MakesStudentData;

    private int $published;

    private int $hidden;

    private int $declined;

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = $this->makeSession('Session 1', '2025-2026', 1, true);
        $this->hidden = $this->makeSession('Session 2', '2025-2026', 2);          // aucune ligne dans t_notes_publication
        $this->declined = $this->makeSession('Session 3', '2026-2027', 1, false); // ligne présente, publiee = 0
    }

    private function home(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp()->getJson('/api/v1/me/home');
    }

    public function test_only_grades_of_published_sessions_are_returned(): void
    {
        $u = $this->makeStudent('E-001');
        $this->makeNote('E-001', $this->published, 'Algorithmique', '14');
        $this->makeNote('E-001', $this->hidden, 'Secret masqué', '19');
        $this->makeNote('E-001', $this->declined, 'Secret refusé', '18');

        $res = $this->home($u)->assertOk();

        $this->assertSame(['Algorithmique'], collect($res->json('data.recent_grades'))->pluck('course_title')->all());
        $this->assertStringNotContainsString('Secret', json_encode($res->json()));
    }

    public function test_hidden_sessions_are_announced_without_their_grades(): void
    {
        $u = $this->makeStudent('E-002');
        $this->makeNote('E-002', $this->published, 'Visible', '12');
        $this->makeNote('E-002', $this->hidden, 'Cache', '20');
        $this->makeNote('E-002', $this->declined, 'Cache aussi', '20');

        $pending = collect($this->home($u)->json('data.pending_sessions'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Session 2', 'Session 3'], $pending);
    }

    public function test_the_average_ignores_hidden_sessions_otherwise_it_would_reveal_them(): void
    {
        $u = $this->makeStudent('E-003');
        $this->makeNote('E-003', $this->published, 'A', '10', 2);
        $this->makeNote('E-003', $this->hidden, 'B', '20', 10); // pèserait lourd s'il comptait

        $this->assertEquals(10.0, $this->home($u)->json('data.average'));
    }

    public function test_the_average_is_weighted_by_credits(): void
    {
        $u = $this->makeStudent('E-004');
        $this->makeNote('E-004', $this->published, 'A', '14', 3);
        $this->makeNote('E-004', $this->published, 'B', '10', 2);

        $this->assertEquals(12.4, $this->home($u)->json('data.average')); // (14×3 + 10×2) / 5
    }

    public function test_the_average_excludes_validation_courses_and_non_numeric_grades(): void
    {
        $u = $this->makeStudent('E-005');
        $this->makeNote('E-005', $this->published, 'Normal', '16', 4);
        $this->makeNote('E-005', $this->published, 'Validation', '5', 4, ['cours_category' => 5]);
        $this->makeNote('E-005', $this->published, 'RELP', '5', 4, ['Sigle' => 'RELP 291']);
        $this->makeNote('E-005', $this->published, 'Formation spirituelle I', '5', 4);
        $this->makeNote('E-005', $this->published, 'Non note', '-4', 4);
        $this->makeNote('E-005', $this->published, 'Vide', '', 4);
        $this->makeNote('E-005', $this->published, 'Retire', '5', 4, ['ajout' => 0]);

        $this->assertEquals(16.0, $this->home($u)->json('data.average'));
    }

    public function test_a_course_without_category_still_counts(): void
    {
        $u = $this->makeStudent('E-006');
        $this->makeNote('E-006', $this->published, 'Sans categorie', '12', 3, ['cours_category' => null]);

        $this->assertEquals(12.0, $this->home($u)->json('data.average'));
    }

    public function test_grade_states_are_translated_into_codes(): void
    {
        $u = $this->makeStudent('E-007');
        $this->makeNote('E-007', $this->published, 'Chiffree', '15.5');
        $this->makeNote('E-007', $this->published, 'Validee', '-2');
        $this->makeNote('E-007', $this->published, 'Echec', '-3');
        $this->makeNote('E-007', $this->published, 'Non notee', '-4');

        $by = collect($this->home($u)->json('data.recent_grades'))->keyBy('course_title');

        $this->assertEquals(['S', 15.5], [$by['Chiffree']['status']['code'], $by['Chiffree']['grade']]);
        $this->assertEquals(['V', null], [$by['Validee']['status']['code'], $by['Validee']['grade']]);
        $this->assertEquals(['E', null], [$by['Echec']['status']['code'], $by['Echec']['grade']]);
        $this->assertEquals(['NN', null], [$by['Non notee']['status']['code'], $by['Non notee']['grade']]);
    }

    public function test_a_student_never_sees_another_students_grades(): void
    {
        $a = $this->makeStudent('E-010', 'Alpha', 'A');
        $this->makeStudent('E-011', 'Beta', 'B');
        $this->makeNote('E-010', $this->published, 'Note de A', '11');
        $this->makeNote('E-011', $this->published, 'Note de B', '19');

        $res = $this->home($a)->assertOk();

        $this->assertSame(['Note de A'], collect($res->json('data.recent_grades'))->pluck('course_title')->all());
        $this->assertSame('E-010', $res->json('data.student.student_id'));
        $this->assertEquals(11.0, $res->json('data.average'));
    }

    public function test_only_the_five_latest_grades_are_listed(): void
    {
        $u = $this->makeStudent('E-012');
        foreach (range(1, 8) as $i) {
            $this->makeNote('E-012', $this->published, "Cours {$i}", '10');
        }

        $this->assertCount(5, $this->home($u)->json('data.recent_grades'));
    }

    public function test_a_student_with_nothing_published_gets_an_empty_but_valid_answer(): void
    {
        $u = $this->makeStudent('E-013');
        $this->makeNote('E-013', $this->hidden, 'Rien de visible', '15');

        $this->home($u)->assertOk()
            ->assertJsonPath('data.average', null)
            ->assertJsonPath('data.recent_grades', [])
            ->assertJsonCount(1, 'data.pending_sessions');
    }

    public function test_staff_accounts_are_refused(): void
    {
        $this->home(User::factory()->staff(3, 'registrar')->create())->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');
    }

    public function test_a_student_account_without_a_matricule_is_refused(): void
    {
        $this->home(User::factory()->create(['student_id' => null]))->assertStatus(403);
    }

    public function test_anonymous_requests_are_refused(): void
    {
        $this->fromApp()->getJson('/api/v1/me/home')->assertStatus(401)->assertJsonPath('code', 'auth.unauthenticated');
    }
}
