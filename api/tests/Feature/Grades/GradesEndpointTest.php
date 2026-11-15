<?php

namespace Tests\Feature\Grades;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/**
 * « Mes notes » : relevé par session, moyennes, notes de promotion.
 * Points sensibles : sessions masquées, matricule non numérique, rubrique masquée, doublons, isolation entre étudiants.
 */
class GradesEndpointTest extends TestCase
{
    use MakesStudentData;

    private int $s1;

    private int $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s1 = $this->makeSession('Session 1', '2024-2025', 1, true);
        $this->s2 = $this->makeSession('Session 2', '2025-2026', 1, true);
    }

    private function grades(User $user)
    {
        return $this->actingAs($user, 'web')->fromApp()->getJson('/api/v1/me/grades');
    }

    public function test_sessions_are_listed_oldest_first_with_courses_sorted_by_title(): void
    {
        $u = $this->makeStudent('100');
        $this->makeNote('100', $this->s2, 'Zoologie', '12');
        $this->makeNote('100', $this->s1, 'Biologie', '14');
        $this->makeNote('100', $this->s1, 'Anglais', '16');

        $d = $this->grades($u)->assertOk()->json('data');

        $this->assertSame(['2024-2025', '2025-2026'], array_column(array_column($d['sessions'], 'session'), 'year'));
        $this->assertSame(['Anglais', 'Biologie'], array_column($d['sessions'][0]['courses'], 'title'));
    }

    public function test_hidden_sessions_never_appear_and_are_announced(): void
    {
        $u = $this->makeStudent('101');
        $hidden = $this->makeSession('Session 3', '2026-2027', 1);          // aucune ligne
        $declined = $this->makeSession('Session 4', '2026-2027', 2, false); // publiee = 0
        $this->makeNote('101', $this->s1, 'Visible', '14');
        $this->makeNote('101', $hidden, 'Secret un', '20');
        $this->makeNote('101', $declined, 'Secret deux', '20');

        $res = $this->grades($u)->assertOk();

        $this->assertStringNotContainsString('Secret', $res->getContent());
        $this->assertCount(1, $res->json('data.sessions'));
        $this->assertSame(['Session 3', 'Session 4'], array_column($res->json('data.pending_sessions'), 'name'));
    }

    public function test_session_and_cumulative_averages_are_weighted_by_credits_not_averaged(): void
    {
        $u = $this->makeStudent('102');
        // Session 1 : (10×2 + 20×2) / 4 = 15 ; session 2 : 12×6 / 6 = 12
        $this->makeNote('102', $this->s1, 'A', '10', 2);
        $this->makeNote('102', $this->s1, 'B', '20', 2);
        $this->makeNote('102', $this->s2, 'C', '12', 6);

        $d = $this->grades($u)->json('data');

        $this->assertEquals(15.0, $d['sessions'][0]['averages']['general']);
        $this->assertEquals(12.0, $d['sessions'][1]['averages']['general']);
        // Cumul = (20+40+72) / 10 = 13,2 ; et NON la moyenne des moyennes (13,5).
        $this->assertEquals(13.2, $d['cumulative']['general']);
    }

    public function test_the_major_average_keeps_only_category_one(): void
    {
        $u = $this->makeStudent('103');
        $this->makeNote('103', $this->s1, 'Majeur', '18', 3, ['cours_category' => 1]);
        $this->makeNote('103', $this->s1, 'General', '10', 3, ['cours_category' => 0]);

        $d = $this->grades($u)->json('data');

        $this->assertEquals(18.0, $d['sessions'][0]['averages']['major']);
        $this->assertEquals(14.0, $d['sessions'][0]['averages']['general']);
        $this->assertEquals(18.0, $d['cumulative']['major']);
    }

    public function test_validation_courses_and_codes_are_shown_but_never_averaged(): void
    {
        $u = $this->makeStudent('104');
        $this->makeNote('104', $this->s1, 'Normal', '16', 4);
        $this->makeNote('104', $this->s1, 'Validation', '12', 4, ['cours_category' => 5]);
        $this->makeNote('104', $this->s1, 'Valide sans note', '-2', 4);
        $this->makeNote('104', $this->s1, 'Echec sans note', '-3', 4);
        $this->makeNote('104', $this->s1, 'Non note', '-4', 4);

        $d = $this->grades($u)->json('data');
        $by = collect($d['sessions'][0]['courses'])->keyBy('title');

        $this->assertEquals(16.0, $d['sessions'][0]['averages']['general']);
        $this->assertSame('V', $by['Validation']['status']['code']);
        $this->assertSame('V', $by['Valide sans note']['status']['code']);
        $this->assertSame('E', $by['Echec sans note']['status']['code']);
        $this->assertSame('NN', $by['Non note']['status']['code']);
        $this->assertFalse($by['Validation']['counts_in_average']);
        $this->assertSame(20, $d['sessions'][0]['totals']['credits'], 'les crédits de tous les cours sont totalisés');
        $this->assertSame(5, $d['sessions'][0]['totals']['courses']);
    }

    public function test_a_zero_is_shown_but_excluded_from_the_average_until_the_registrar_decides(): void
    {
        $u = $this->makeStudent('105');
        $this->makeNote('105', $this->s1, 'Bon', '14', 3);
        $this->makeNote('105', $this->s1, 'Zero', '0', 3);

        $d = $this->grades($u)->json('data');
        $zero = collect($d['sessions'][0]['courses'])->firstWhere('title', 'Zero');

        $this->assertEquals(['E', 0.0], [$zero['status']['code'], $zero['grade']]);
        $this->assertEquals(14.0, $d['sessions'][0]['averages']['general']);

        config(['registrar.zero_counts_in_average' => true]);
        $this->assertEquals(7.0, $this->grades($u)->json('data.sessions.0.averages.general'));
    }

    public function test_a_comma_decimal_counts_with_its_real_value(): void
    {
        $u = $this->makeStudent('106');
        $this->makeNote('106', $this->s1, 'Virgule', '12,5', 2);

        // L'ancien SQL tronquait « 12,5 » en 12 ; l'affichage disait 12,5. Ici, une seule valeur : 12,5.
        $this->assertEquals(12.5, $this->grades($u)->json('data.sessions.0.averages.general'));
    }

    public function test_level_labels_follow_the_year_level(): void
    {
        $u = $this->makeStudent('107');
        $this->makeNote('107', $this->s1, 'L2', '10', 1, ['yearlevel' => 2]);
        $this->makeNote('107', $this->s2, 'M2', '10', 1, ['yearlevel' => 5]);

        $labels = array_column($this->grades($u)->json('data.sessions'), 'level_label');

        $this->assertSame(['Licence 2', 'Master 2'], $labels);
    }

    public function test_promotion_notes_are_returned_with_their_cumulative_over_all_displayed_sessions(): void
    {
        $u = $this->makeStudent('200');
        $this->makeNote('200', $this->s1, 'A', '10');
        $this->makeNote('200', $this->s2, 'B', '10');
        $this->makePromotion(200, $this->s1, 16.0, 14.0, 18.0);
        // Session 2 sans note de promotion : elle compte pour 0 dans le cumul, comme l'ancien affichage.

        $d = $this->grades($u)->json('data');

        $this->assertTrue($d['promotion_visible']);
        $this->assertEquals(['work_education' => 16.0, 'academic_remark' => 14.0, 'chapel' => 18.0], $d['sessions'][0]['promotion']);
        $this->assertNull($d['sessions'][1]['promotion']);
        $this->assertEquals(8.0, $d['cumulative']['work_education']); // 16 / 2 sessions
        $this->assertEquals(9.0, $d['cumulative']['chapel']);         // 18 / 2 sessions
    }

    public function test_without_any_promotion_data_the_cumulative_is_empty_not_zero(): void
    {
        $u = $this->makeStudent('203');
        $this->makeNote('203', $this->s1, 'A', '10');

        $d = $this->grades($u)->json('data');

        $this->assertTrue($d['promotion_visible']);
        $this->assertNull($d['cumulative']['work_education'], 'un 0 ressemblerait à une vraie note');
        $this->assertNull($d['cumulative']['chapel']);
    }

    public function test_a_zero_or_missing_promotion_value_shows_as_empty_like_before(): void
    {
        $u = $this->makeStudent('201');
        $this->makeNote('201', $this->s1, 'A', '10');
        $this->makePromotion(201, $this->s1, 0.0, null, 12.0);

        $p = $this->grades($u)->json('data.sessions.0.promotion');

        $this->assertNull($p['work_education']);
        $this->assertNull($p['academic_remark']);
        $this->assertEquals(12.0, $p['chapel']);
    }

    public function test_promotion_notes_disappear_when_the_superadmin_hides_the_rubric(): void
    {
        $u = $this->makeStudent('202');
        $this->makeNote('202', $this->s1, 'A', '10');
        $this->makePromotion(202, $this->s1, 16.0, 14.0, 18.0);
        $this->setRubric('notes_promotion', false);

        $res = $this->grades($u)->assertOk();

        $this->assertFalse($res->json('data.promotion_visible'));
        $this->assertNull($res->json('data.sessions.0.promotion'));
        $this->assertNull($res->json('data.cumulative.work_education'));
        $session = json_encode($res->json('data.sessions.0'));
        foreach (['work_education', 'academic_remark', 'chapel'] as $key) {
            $this->assertStringNotContainsString($key, $session, "la clé « {$key} » ne doit pas rester dans la session");
        }
    }

    public function test_a_non_numeric_matricule_never_reaches_promotion_notes(): void
    {
        // MySQL convertit « UAZ-1 » en 0 face à une colonne entière : une ligne à student_id = 0 serait rattachée à tort.
        $u = $this->makeStudent('UAZ-1');
        $this->makeNote('UAZ-1', $this->s1, 'A', '10');
        $this->makePromotion(0, $this->s1, 20.0, 20.0, 20.0);

        $this->assertNull($this->grades($u)->json('data.sessions.0.promotion'));
    }

    public function test_one_student_never_sees_another_students_promotion_notes(): void
    {
        $a = $this->makeStudent('300');
        $this->makeStudent('301');
        $this->makeNote('300', $this->s1, 'A', '10');
        $this->makePromotion(301, $this->s1, 19.0, 19.0, 19.0);

        $this->assertNull($this->grades($a)->json('data.sessions.0.promotion'));
    }

    public function test_duplicate_promotion_rows_resolve_to_the_first_one_like_the_old_page(): void
    {
        $u = $this->makeStudent('302');
        $this->makeNote('302', $this->s1, 'A', '10');
        $this->makePromotion(302, $this->s1, 11.0, null, null);
        $this->makePromotion(302, $this->s1, 19.0, null, null);

        $this->assertEquals(11.0, $this->grades($u)->json('data.sessions.0.promotion.work_education'));
    }

    public function test_a_student_never_sees_another_students_grades(): void
    {
        $a = $this->makeStudent('400');
        $this->makeStudent('401');
        $this->makeNote('400', $this->s1, 'De A', '10');
        $this->makeNote('401', $this->s1, 'De B', '19');

        $titles = collect($this->grades($a)->json('data.sessions.0.courses'))->pluck('title')->all();

        $this->assertSame(['De A'], $titles);
    }

    public function test_removed_enrolments_do_not_appear(): void
    {
        $u = $this->makeStudent('402');
        $this->makeNote('402', $this->s1, 'Actif', '10');
        $this->makeNote('402', $this->s1, 'Retire', '19', 3, ['ajout' => 0]);

        $this->assertCount(1, $this->grades($u)->json('data.sessions.0.courses'));
    }

    public function test_an_empty_record_is_a_valid_answer(): void
    {
        $u = $this->makeStudent('403');

        $this->grades($u)->assertOk()
            ->assertJsonPath('data.sessions', [])
            ->assertJsonPath('data.cumulative.general', null);
    }

    public function test_anonymous_requests_cannot_read_grades(): void
    {
        $this->fromApp()->getJson('/api/v1/me/grades')->assertStatus(401);
    }

    public function test_staff_accounts_cannot_read_student_grades(): void
    {
        $this->grades(User::factory()->staff(3, 'registrar')->create())->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');
    }

    public function test_a_student_account_without_a_matricule_cannot_read_grades(): void
    {
        $this->grades(User::factory()->create(['student_id' => null]))->assertStatus(403);
    }

    public function test_the_home_average_always_equals_the_cumulative_average(): void
    {
        $u = $this->makeStudent('500');
        $this->makeNote('500', $this->s1, 'A', '10', 2);
        $this->makeNote('500', $this->s1, 'B', '20', 2);
        $this->makeNote('500', $this->s2, 'C', '12', 6);

        $home = $this->actingAs($u, 'web')->fromApp()->getJson('/api/v1/me/home')->json('data.average');

        $this->assertEquals($this->grades($u)->json('data.cumulative.general'), $home);
        $this->assertEquals(13.2, $home);
        $this->assertSame(1, DB::table('t_2023_session')->where('session_name', 'Session 1')->count());
    }
}
