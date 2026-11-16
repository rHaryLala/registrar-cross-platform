<?php

namespace Tests\Feature\Directory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Tableau de bord du personnel : « à traiter » et « chiffres clés ». */
class StaffDashboardTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');   // l'année en cours est « 2026 - 2027 », l'année écoulée « 2025 - 2026 »
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function registrar(array $attrs = []): User
    {
        return User::factory()->staff(3, 'registrar')->create($attrs);
    }

    private function board(User $who, string $query = '')
    {
        return $this->api($who)->getJson('/api/v1/staff/dashboard'.$query);
    }

    private function item(array $data, string $key): ?array
    {
        return collect($data['to_do'])->firstWhere('key', $key);
    }

    private function course(string $code, string $title, array $over = []): int
    {
        return DB::table('t_2023_cours')->insertGetId(array_merge(['Sigle' => $code, 'title' => $title, 'id_teacher' => null, 'yearlevel' => 1, 'cout' => 0, 'cout_lab' => 0, 'ajout' => 0, 'remove' => 0], $over));
    }

    private function grade(int $course, string $student, ?string $grade, string $year = '2025 - 2026', array $over = []): void
    {
        DB::table('t_2023_notes')->insert(array_merge([
            'id_cours' => (string) $course, 'student_id' => $student.(++$this->n), 'semester' => 1, 'annee_scolaire' => $year, 'Sigle' => 'X', 'title_cours' => 'X', 'cours_category' => 1,
            'yearlevel' => 1, 'ajout' => 1, 'remove' => 0, 'user_id_entry' => 't', 'grade' => $grade,
        ], $over));
    }

    private function candidate(string $status, string $when = '2026-09-10 10:00:00', array $over = []): int
    {
        return DB::table('candidat')->insertGetId(array_merge(['nom_complet' => 'Cand '.(++$this->n), 'telephone' => '0340000000', 'mention' => 'INFO', 'statut' => $status, 'date_creation' => $when], $over));
    }

    // ------------------------------------------------------------------ accès

    public function test_staff_can_open_the_dashboard_but_not_students_teachers_or_work_education(): void
    {
        foreach ([User::factory()->staff(1, 'superadmin')->create(), User::factory()->staff(2, 'admin')->create(), $this->registrar(), User::factory()->staff(4, 'comptabilite')->create(),
            User::factory()->staff(5, 'media')->create(), User::factory()->staff(6, 'chef_mention')->create()] as $who) {
            $this->board($who)->assertOk();
        }
        foreach ([User::factory()->staff(7, 'teacher')->create(), User::factory()->create(), User::factory()->staff(9, 'work_education')->create(), User::factory()->staff(3, 'registrar')->inactive()->create()] as $who) {
            $this->board($who)->assertForbidden()->assertJsonPath('code', 'forbidden.dashboard');
        }
    }

    public function test_a_session_is_required(): void
    {
        $this->getJson('/api/v1/staff/dashboard')->assertUnauthorized();
    }

    public function test_the_profile_tells_who_has_the_dashboard(): void
    {
        $flag = fn (User $u) => $this->api($u)->getJson('/api/v1/auth/me')->json('data.dashboard');

        $this->assertTrue($flag($this->registrar()));
        $this->assertTrue($flag(User::factory()->staff(4, 'comptabilite')->create()));
        $this->assertFalse($flag(User::factory()->staff(7, 'teacher')->create()));
        $this->assertFalse($flag(User::factory()->create()));
    }

    public function test_only_the_registrariat_sees_things_to_do_and_applications(): void
    {
        DB::table('t_remise_notes')->insert(['cours_id' => 1, 'annee_scolaire' => '2026 - 2027', 'statut' => 'soumise', 'soumis_le' => '2026-11-01 08:00:00']);
        $this->candidate('nouveau');

        foreach ([User::factory()->staff(2, 'admin')->create(), User::factory()->staff(4, 'comptabilite')->create(), User::factory()->staff(6, 'chef_mention')->create()] as $who) {
            $d = $this->board($who)->json('data');
            $this->assertSame([], $d['to_do']);
            $this->assertNull($d['figures']['applications']);
        }
        $d = $this->board($this->registrar())->json('data');
        $this->assertNotSame([], $d['to_do']);
        $this->assertNotNull($d['figures']['applications']);
        $this->assertNotSame([], $this->board(User::factory()->staff(1, 'superadmin')->create())->json('data.to_do'));
    }

    // ------------------------------------------------------------------ à traiter

    public function test_reviews_flags_and_new_applications_are_counted(): void
    {
        foreach (['2026-11-03 09:00:00', '2026-11-01 08:00:00'] as $when) {
            DB::table('t_remise_notes')->insert(['cours_id' => 1, 'annee_scolaire' => '2026 - 2027', 'statut' => 'soumise', 'soumis_le' => $when]);
        }
        DB::table('t_remise_notes')->insert(['cours_id' => 2, 'annee_scolaire' => '2026 - 2027', 'statut' => 'brouillon']);
        DB::table('t_remise_notes')->insert(['cours_id' => 3, 'annee_scolaire' => '2026 - 2027', 'statut' => 'validee', 'soumis_le' => '2026-10-01 08:00:00']);
        $sheet = DB::table('t_presence_feuille')->insertGetId(['cours_id' => 1, 'sigle' => 'X', 'titre_cours' => 'X', 'annee_scolaire' => '2026 - 2027', 'semestre' => 1, 'cree_le' => '2026-09-01', 'modifie_le' => '2026-09-01']);
        DB::table('t_presence_signalement')->insert([
            ['feuille_id' => $sheet, 'student_id' => 'A', 'absences' => 3, 'seances_prevues' => 10, 'signale_le' => '2026-10-01 10:00:00', 'traite_le' => null],
            ['feuille_id' => $sheet, 'student_id' => 'B', 'absences' => 3, 'seances_prevues' => 10, 'signale_le' => '2026-10-01 10:00:00', 'traite_le' => null],
            ['feuille_id' => $sheet, 'student_id' => 'C', 'absences' => 3, 'seances_prevues' => 10, 'signale_le' => '2026-10-01 10:00:00', 'traite_le' => '2026-10-02 10:00:00'],
        ]);
        foreach (['nouveau', 'nouveau', 'nouveau', 'en_cours', 'accepte', 'refuse'] as $s) {
            $this->candidate($s);
        }
        $this->candidate('nouveau', '2026-09-10 10:00:00', ['archive_le' => '2026-10-01 10:00:00']);   // archivée : ne compte pas

        $d = $this->board($this->registrar())->json('data');

        $this->assertSame([2, '2026-11-01 08:00:00', '/validations/'], [$this->item($d, 'reviews')['count'], $this->item($d, 'reviews')['since'], $this->item($d, 'reviews')['link']]);
        $this->assertSame([2, '/assiduite/'], [$this->item($d, 'flags')['count'], $this->item($d, 'flags')['link']], 'les signalements traités ne comptent pas');
        $this->assertSame([3, 1, '/candidatures/'], [$this->item($d, 'applications')['count'], $this->item($d, 'applications')['in_progress'], $this->item($d, 'applications')['link']]);
    }

    public function test_nothing_to_do_means_no_items_and_zero_counts_are_never_listed(): void
    {
        $this->candidate('en_cours');   // pas de « nouvelle » candidature : rien à signaler

        $this->assertSame([], $this->board($this->registrar())->json('data.to_do'));
    }

    public function test_items_are_ordered_urgent_first_then_by_count(): void
    {
        $c1 = $this->course('A 1', 'Sans enseignant');
        DB::table('t_2023_course_sessions')->insert(['course_id' => $c1, 'session_label' => 'S1', 'academic_year' => '2026 - 2027', 'offered' => 1]);
        $c2 = $this->course('B 1', 'Doublon');
        $c3 = $this->course('B 1', 'Doublon bis');
        foreach (range(1, 5) as $i) {
            $this->grade($c2, 'S', null);
        }
        foreach (range(1, 2) as $i) {
            $this->candidate('nouveau');
        }
        DB::table('t_remise_notes')->insert(['cours_id' => 1, 'annee_scolaire' => '2026 - 2027', 'statut' => 'soumise', 'soumis_le' => '2026-11-01 08:00:00']);

        $d = $this->board($this->registrar())->json('data');

        $this->assertSame(['ungraded', 'applications', 'reviews', 'no_teacher', 'duplicate_codes'], array_column($d['to_do'], 'key'));
        unset($c3);
    }

    public function test_ungraded_notes_of_the_past_year_are_listed_by_course_most_late_first(): void
    {
        $uid = 830001;
        DB::table('teacher')->insert(['uid' => $uid, 'name' => 'HASINA', 'lastName' => 'Rasolofo', 'email' => 'h@exemple.test', 'sex' => 0, 'remove' => 0]);
        $a = $this->course('INF 1', 'Algo', ['id_teacher' => $uid, 'sigle_affiche' => 'INF-A']);
        $b = $this->course('INF 2', 'Bases');
        foreach ([null, '', '-1', '-4', null] as $g) {
            $this->grade($a, 'S', $g);
        }
        $this->grade($b, 'S', null);
        $this->grade($a, 'S', '-2');                                  // validé : saisi
        $this->grade($a, 'S', '-3');                                  // échec : saisi
        $this->grade($a, 'S', '14.5');                                // noté
        $this->grade($a, 'S', null, '2026 - 2027');                     // année en cours : une note manque normalement
        $this->grade($a, 'S', null, '2025 - 2026', ['remove' => 1]);    // retirée

        $it = $this->item($this->board($this->registrar())->json('data'), 'ungraded');

        $this->assertSame([6, 2, '2025 - 2026'], [$it['count'], $it['courses'], $it['year']]);
        $this->assertSame(['INF-A', 'Hasina Rasolofo', 5], [$it['list'][0]['code'], $it['list'][0]['teacher'], $it['list'][0]['count']]);
        $this->assertSame(['INF 2', null, 1], [$it['list'][1]['code'], $it['list'][1]['teacher'], $it['list'][1]['count']]);
        $this->assertSame($a, $it['list'][0]['course_id']);
    }

    public function test_the_ungraded_list_shows_the_eight_most_late_courses_but_counts_all(): void
    {
        foreach (range(1, 10) as $i) {
            $c = $this->course('C '.$i, 'Cours '.$i);
            foreach (range(1, $i) as $j) {
                $this->grade($c, 'S', null);
            }
        }

        $it = $this->item($this->board($this->registrar())->json('data'), 'ungraded');

        $this->assertSame([55, 10, 8], [$it['count'], $it['courses'], count($it['list'])]);
        $this->assertSame([10, 9, 8, 7, 6, 5, 4, 3], array_column($it['list'], 'count'));
    }

    public function test_offered_courses_without_a_teacher_this_year(): void
    {
        DB::table('teacher')->insert(['uid' => 830002, 'name' => 'Parti', 'lastName' => 'Ancien', 'email' => 'p@exemple.test', 'sex' => 0, 'remove' => 1]);
        DB::table('teacher')->insert(['uid' => 830003, 'name' => 'Actif', 'lastName' => 'Prof', 'email' => 'a@exemple.test', 'sex' => 0, 'remove' => 0]);
        $none = $this->course('N 1', 'Sans');
        $gone = $this->course('N 2', 'Enseignant parti', ['id_teacher' => 830002]);
        $ok = $this->course('N 3', 'Avec', ['id_teacher' => 830003]);
        $notOffered = $this->course('N 4', 'Non offert');
        $removed = $this->course('N 5', 'Supprimé', ['remove' => 1]);
        $other = $this->course('N 6', 'Autre année');
        foreach ([[$none, 1, '2026 - 2027'], [$gone, 1, '2026 - 2027'], [$ok, 1, '2026 - 2027'], [$notOffered, 0, '2026 - 2027'], [$removed, 1, '2026 - 2027'], [$other, 1, '2025 - 2026']] as [$c, $offered, $year]) {
            DB::table('t_2023_course_sessions')->insert(['course_id' => $c, 'session_label' => 'Premier semestre', 'academic_year' => $year, 'offered' => $offered]);
        }

        $it = $this->item($this->board($this->registrar())->json('data'), 'no_teacher');

        $this->assertSame(2, $it['count']);
        $this->assertEqualsCanonicalizing(['N 1', 'N 2'], array_column($it['list'], 'code'));
        $this->assertSame('Premier semestre', $it['list'][0]['session']);
        unset($ok, $notOffered, $removed, $other);
    }

    public function test_two_active_courses_shown_under_the_same_code(): void
    {
        $a = $this->course('INF 100', 'A');
        $b = $this->course('inf  100', 'B');                        // même code, casse et espaces différents
        $c = $this->course('X', 'C', ['sigle_affiche' => 'INF 100']);  // alias : même code affiché
        $this->course('INF 200', 'D');
        $this->course('INF 100', 'Supprimé', ['remove' => 1]);

        $it = $this->item($this->board($this->registrar())->json('data'), 'duplicate_codes');

        $this->assertSame(1, $it['count']);
        $this->assertSame('INF 100', $it['list'][0]['code']);
        $this->assertSame([$a, $b, $c], $it['list'][0]['course_ids']);
    }

    public function test_the_cycle_limits_the_course_and_note_counters(): void
    {
        $licence = $this->course('L 1', 'Licence', ['yearlevel' => 1]);
        $master = $this->course('M 1', 'Master', ['yearlevel' => 5]);
        $this->grade($licence, 'S', null, '2025 - 2026', ['yearlevel' => 1]);
        $this->grade($master, 'S', null, '2025 - 2026', ['yearlevel' => 5]);

        $all = $this->item($this->board($this->registrar())->json('data'), 'ungraded');
        $onlyLicence = $this->item($this->board($this->registrar(['perimetre_cycle' => 'L']))->json('data'), 'ungraded');

        $this->assertSame([2, 1], [$all['count'], $onlyLicence['count']]);
        $this->assertSame([$licence], array_column($onlyLicence['list'], 'course_id'));
    }

    // ------------------------------------------------------------------ chiffres clés

    public function test_each_student_counts_once_per_year_on_their_last_enrolment(): void
    {
        $s1 = $this->makeSession('Session 1', '2026 - 2027', 1);
        $s2 = $this->makeSession('Session 2', '2026 - 2027', 2);
        foreach (['A', 'B'] as $id) {
            DB::table('tbl_2024_etudiant')->insert(['student_id' => $id, 'student_nom' => $id, 'student_prenom' => $id, 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2026 - 2027', 'period' => 'S1', 'remove' => 0, 'sex' => $id === 'A' ? '0' : '1']);
        }
        $this->enroll('A', $s1, 1, 'INFO', ['status' => 'Interne', 'new_student' => 1]);
        $this->enroll('A', $s2, 1, 'INFO', ['status' => 'Bungalow', 'new_student' => 1]);       // la dernière compte
        $this->enroll('B', $s1, 4, 'EDUC', ['status' => 'Externe', 'new_student' => 0]);
        DB::table('t_2024_inscription_session')->where('student_id', 'B')->update(['remove' => 0]);
        $this->enroll('Z', $s1, 1, 'INFO', ['remove' => 1]);                                      // supprimée : ignorée

        $d = $this->board($this->registrar())->json('data.figures.current');

        $this->assertSame(['enrolled' => 2, 'new' => 1, 'bachelor' => 1, 'master' => 1, 'women' => 1, 'men' => 1, 'boarding' => 0, 'bungalow' => 1, 'external' => 1], $d);
    }

    public function test_the_year_comes_from_the_session_not_from_the_copy_on_the_enrolment(): void
    {
        $s = $this->makeSession('Session 1', '2026 - 2027', 1);
        $this->enroll('A', $s, 1, 'INFO', ['annee_scolaire' => '2024 - 2025']);   // la copie a divergé : la session fait foi
        $noSession = $this->makeSession('Sans année', '', 1);
        $this->enroll('B', $noSession, 1, 'INFO', ['annee_scolaire' => ' 2026 - 2027 ']);   // session sans année : on retombe sur la copie

        $d = $this->board($this->registrar())->json('data');

        $this->assertSame([2, 0], [$d['figures']['current']['enrolled'], $d['figures']['before']['enrolled']]);
        $this->assertContains('2026 - 2027', $d['years']);
        $this->assertNotContains('2024 - 2025', $d['years']);
    }

    public function test_the_previous_year_the_same_date_figure_and_mentions_with_labels(): void
    {
        DB::table('filiere')->insert(['filiere_id' => 91, 'filiere_sigle' => 'INFO', 'filiere_description' => 'Informatique', 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '']);
        $now = $this->makeSession('S', '2026 - 2027', 1);
        $past = $this->makeSession('S', '2025 - 2026', 1);
        foreach (['A', 'B', 'C'] as $id) {
            $this->enroll($id, $now, 1, 'INFO');
        }
        $this->enroll('D', $now, 1, 'zzz');
        $this->enroll('E', $now, 1, '');
        foreach (['A', 'B', 'C', 'X'] as $id) {
            $this->enroll($id, $past, 1, 'INFO', ['date_entry' => '2020-09-01']);
        }
        $this->enroll('Y', $past, 1, 'EDUC', ['date_entry' => '2099-09-01']);   // « à la même date » : pas encore arrivé

        $d = $this->board($this->registrar())->json('data');

        $this->assertSame(['2025 - 2026', true, 5, 5], [$d['previous'], $d['is_current'], $d['figures']['current']['enrolled'], $d['figures']['before']['enrolled']]);
        $this->assertSame(4, $d['figures']['same_date'], 'seuls les inscrits de l’an dernier arrivés à la même date');
        $m = collect($d['figures']['mentions']);
        $this->assertSame(['INFO', 'Informatique', 3, 4], [$m[0]['code'], $m[0]['label'], $m[0]['count'], $m[0]['before']]);
        $this->assertSame('Mention non renseignée', $m->firstWhere('code', '?')['label']);
        $this->assertSame('ZZZ', $m->firstWhere('code', 'ZZZ')['label']);
        $codes = $m->pluck('code')->all();
        $this->assertSame(['INFO', 'EDUC'], [$codes[0], $codes[3]], 'le plus d’inscrits d’abord, le moins inscrit en dernier');
    }

    public function test_the_breakdowns_split_correctly_between_levels_sex_and_housing(): void
    {
        $s = $this->makeSession('S', '2026 - 2027', 1);
        foreach ([['A', '0'], ['B', '0'], ['C', '1'], ['D', '1'], ['E', '1']] as [$id, $sex]) {
            DB::table('tbl_2024_etudiant')->insert(['student_id' => $id, 'student_nom' => $id, 'student_prenom' => $id, 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2026-2027', 'period' => 'S1', 'remove' => 0, 'sex' => $sex]);
        }
        $this->enroll('A', $s, 0, 'INFO', ['status' => 'Interne']);       // remise à niveau : licence
        $this->enroll('B', $s, 3, 'INFO', ['status' => '']);              // sans statut : externe
        $this->enroll('C', $s, 4, 'INFO', ['status' => 'Externe']);
        $this->enroll('D', $s, 5, 'INFO', ['status' => 'Bungalow']);
        $this->enroll('E', $s, 2, 'INFO', ['status' => 'Autre']);          // tout ce qui n'est ni interne ni bungalow : externe

        $c = $this->board($this->registrar())->json('data.figures.current');

        $this->assertSame(['enrolled' => 5, 'new' => 0, 'bachelor' => 3, 'master' => 2, 'women' => 2, 'men' => 3, 'boarding' => 1, 'bungalow' => 1, 'external' => 3], $c);
    }

    public function test_years_are_listed_newest_first_and_only_when_well_formed(): void
    {
        $this->enroll('A', $this->makeSession('S', '2024 - 2025', 1), 1);
        $this->enroll('B', $this->makeSession('S2', '2025 - 2026', 1), 1);
        $this->enroll('C', $this->makeSession('S3', '2022-2023', 1), 1);      // sans espaces : pas une année valide
        $this->enroll('D', $this->makeSession('S4', 'brouillon', 1), 1);

        $this->assertSame(['2026 - 2027', '2025 - 2026', '2024 - 2025'], $this->board($this->registrar())->json('data.years'));
    }

    public function test_duplicate_codes_are_reported_in_capitals_whatever_the_first_spelling(): void
    {
        $a = $this->course('inf  100', 'A');
        $b = $this->course('INF 100', 'B');

        $it = $this->item($this->board($this->registrar())->json('data'), 'duplicate_codes');

        $this->assertSame(['INF 100', [$a, $b]], [$it['list'][0]['code'], $it['list'][0]['course_ids']]);
    }

    public function test_the_same_date_figure_only_counts_last_years_students_who_had_arrived_a_year_ago(): void
    {
        $past = $this->makeSession('S', '2025 - 2026', 1);
        $recent = DB::selectOne('SELECT CURDATE() - INTERVAL 100 DAY AS d')->d;   // il y a 100 jours : pas encore arrivé il y a un an
        $this->enroll('A', $past, 1, 'INFO', ['date_entry' => '2020-09-01']);
        $this->enroll('B', $past, 1, 'INFO', ['date_entry' => $recent]);

        $d = $this->board($this->registrar())->json('data.figures');

        $this->assertSame([1, 2], [$d['same_date'], $d['before']['enrolled']]);
    }

    public function test_mentions_are_ordered_by_enrolment_then_by_last_years_count(): void
    {
        $now = $this->makeSession('S', '2026 - 2027', 1);
        $past = $this->makeSession('S', '2025 - 2026', 1);
        $this->enroll('a1', $now, 1, 'CCC');
        foreach (['b1', 'b2'] as $id) {
            $this->enroll($id, $now, 1, 'BBB');
        }
        foreach (['c1', 'c2', 'c3'] as $id) {
            $this->enroll($id, $now, 1, 'AAA');
        }
        foreach (['x1', 'x2', 'x3', 'x4'] as $id) {
            $this->enroll($id, $past, 1, 'DDD');   // aucune inscription cette année, mais quatre l'an dernier
        }
        $this->enroll('y1', $now, 1, 'EEE');
        $this->enroll('y1', $past, 1, 'EEE');

        $codes = $this->board($this->registrar())->json('data.figures.mentions');

        $this->assertSame(['AAA', 'BBB', 'EEE', 'CCC', 'DDD'], array_column($codes, 'code'), "le plus d’inscrits, puis (à égalité) le plus d’inscrits l’an dernier");
    }

    public function test_a_past_year_has_no_same_date_figure(): void
    {
        $past = $this->makeSession('S', '2025 - 2026', 1);
        $this->enroll('A', $past, 1);

        $d = $this->board($this->registrar(), '?year='.urlencode('2025 - 2026'))->json('data');

        $this->assertSame(['2025 - 2026', false, null, 1], [$d['year'], $d['is_current'], $d['figures']['same_date'], $d['figures']['current']['enrolled']]);
        $this->assertSame('2024 - 2025', $d['previous']);
    }

    public function test_an_unknown_year_falls_back_to_the_current_one_and_a_malformed_one_is_refused(): void
    {
        $reg = $this->registrar();

        $this->assertSame('2026 - 2027', $this->board($reg, '?year='.urlencode('2031 - 2032'))->assertOk()->json('data.year'));
        $this->board($reg, '?year=abc')->assertUnprocessable();
        $this->board($reg, '?year='.urlencode("2026 - 2027' OR 1=1"))->assertUnprocessable();
    }

    public function test_the_cycle_limits_the_enrolment_figures(): void
    {
        $s = $this->makeSession('S', '2026 - 2027', 1);
        $this->enroll('A', $s, 1);
        $this->enroll('B', $s, 4);
        $this->enroll('C', $s, 5);

        $all = $this->board($this->registrar())->json('data.figures.current');
        $licence = $this->board($this->registrar(['perimetre_cycle' => 'L']))->json('data.figures.current');
        $master = $this->board($this->registrar(['perimetre_cycle' => 'M']))->json('data.figures.current');

        $this->assertSame([3, 1, 2], [$all['enrolled'], $licence['enrolled'], $master['enrolled']]);
        $this->assertSame([1, 0], [$licence['bachelor'], $licence['master']]);
    }

    public function test_applications_are_counted_over_the_school_year_july_to_june(): void
    {
        $this->candidate('nouveau', '2026-06-30 23:59:59');                  // l'année d'avant
        $this->candidate('nouveau', '2026-07-01 00:00:00');
        $this->candidate('accepte', '2027-06-30 12:00:00');
        $this->candidate('refuse', '2026-10-01 12:00:00');
        $this->candidate('refuse', '2026-10-02 12:00:00');
        $this->candidate('accepte', '2027-07-01 00:00:00');                  // l'année d'après
        $this->candidate('accepte', '2025-08-01 00:00:00');                  // dans l'année d'avant (2025-2026)

        $a = $this->board($this->registrar())->json('data.figures.applications');

        $this->assertSame(['received' => 4, 'accepted' => 1, 'before' => 2], $a);
    }

    public function test_responses_are_never_cached(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->board($this->registrar())->headers->get('Cache-Control'));
    }
}
