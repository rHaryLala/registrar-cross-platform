<?php

namespace Tests\Feature\Directory;

use App\Domain\Directory\Directory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Les listes du registrariat : étudiants, cours, enseignants (recherche, filtres, tri, pages, périmètres, droits). */
class DirectoryTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tbl_2024_etudiant')->delete();   // la base de test garde une fiche de référence : on la met de côté le temps du test (annulé en fin de test)
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function registrar(array $attrs = []): User
    {
        return User::factory()->staff(3, 'registrar')->create($attrs);
    }

    private function student(string $id, string $last, string $first, array $over = []): int
    {
        return DB::table('tbl_2024_etudiant')->insertGetId(array_merge([
            'student_id' => $id, 'student_nom' => $last, 'student_prenom' => $first, 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1',
            'remove' => 0, 'etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux', 'annee_etude' => '1', 'new_student' => 0,
        ], $over));
    }

    private function teacher(string $name, string $last, array $over = []): int
    {
        $uid = 800000 + (++$this->seq);
        DB::table('teacher')->insert(array_merge(['uid' => $uid, 'name' => $name, 'lastName' => $last, 'email' => strtolower($last).$uid.'@exemple.test', 'sex' => 0, 'remove' => 0], $over));

        return $uid;
    }

    private function course(string $sigle, string $title, array $over = []): int
    {
        return DB::table('t_2023_cours')->insertGetId(array_merge([
            'Sigle' => $sigle, 'title' => $title, 'id_teacher' => null, 'yearlevel' => 1, 'cout' => 0, 'cout_lab' => 0, 'ajout' => 0, 'remove' => 0, 'dep_desc' => 'INFO',
            'nb_crd' => 3, 'category' => 1, 'semester' => 1, 'parcours' => 'all',
        ], $over));
    }

    private function students(User $who, string $query = '')
    {
        return $this->api($who)->getJson('/api/v1/staff/lists/students'.$query);
    }

    private function ids(array $data): array
    {
        return array_column($data['rows'], 'id');
    }

    // ------------------------------------------------------------------ accès

    public function test_the_students_list_is_open_to_staff_but_not_to_students_teachers_or_work_education(): void
    {
        $this->student('E1', 'Andria', 'Zo');
        $allowed = [User::factory()->staff(1, 'superadmin')->create(), User::factory()->staff(2, 'admin')->create(), $this->registrar(), User::factory()->staff(4, 'comptabilite')->create(),
            User::factory()->staff(5, 'media')->create(), User::factory()->staff(6, 'chef_mention')->create(['perimetre_mention' => 'Informatique'])];
        $denied = [User::factory()->staff(7, 'teacher')->create(), User::factory()->create(), User::factory()->staff(9, 'work_education')->create(), User::factory()->staff(3, 'registrar')->inactive()->create()];

        foreach ($allowed as $who) {
            $this->students($who)->assertOk();
        }
        foreach ($denied as $who) {
            $this->students($who)->assertForbidden()->assertJsonPath('code', 'forbidden.directory');
        }
    }

    public function test_courses_and_teachers_are_for_superadmin_and_registrar_only(): void
    {
        foreach (['courses', 'teachers'] as $list) {
            foreach ([User::factory()->staff(1, 'superadmin')->create(), $this->registrar()] as $who) {
                $this->api($who)->getJson("/api/v1/staff/lists/{$list}")->assertOk();
            }
            foreach ([User::factory()->staff(2, 'admin')->create(), User::factory()->staff(4, 'comptabilite')->create(), User::factory()->staff(6, 'chef_mention')->create(),
                User::factory()->staff(7, 'teacher')->create(), User::factory()->create(), $this->registrar()->forceFill(['etat' => 0])] as $who) {
                $this->api($who)->getJson("/api/v1/staff/lists/{$list}")->assertForbidden();
            }
        }
    }

    public function test_an_unknown_list_is_not_routed(): void
    {
        $this->api($this->registrar())->getJson('/api/v1/staff/lists/accounts')->assertNotFound();
    }

    public function test_a_session_is_required(): void
    {
        $this->getJson('/api/v1/staff/lists/students')->assertUnauthorized();
        $this->getJson('/api/v1/staff/students/1')->assertUnauthorized();
    }

    public function test_the_profile_lists_which_directories_the_account_can_open(): void
    {
        $get = fn (User $u) => $this->api($u)->getJson('/api/v1/auth/me')->json('data.directory');

        $this->assertSame(['students', 'courses', 'teachers'], $get($this->registrar()));
        $this->assertSame(['students'], $get(User::factory()->staff(2, 'admin')->create()));
        $this->assertSame([], $get(User::factory()->staff(7, 'teacher')->create()));
        $this->assertSame([], $get(User::factory()->create()));
    }

    // ------------------------------------------------------------------ lignes et confidentialité

    public function test_a_student_row_carries_only_what_the_list_needs(): void
    {
        $this->student('E1', 'Andria', 'Zo', ['new_student' => 1, 'num_cin' => '101 202 303', 'student_email' => 'zo@exemple.test', 'student_tel' => '0340000000', 'student_adresse' => 'Lot 12', 'password' => 'secret']);

        $d = $this->students($this->registrar())->assertOk()->json('data');

        $this->assertSame(['id', 'student_id', 'last_name', 'first_name', 'mention', 'track', 'year', 'level', 'level_label', 'is_new'], array_keys($d['rows'][0]));
        $this->assertSame(['E1', 'Andria', 'Zo', 'Informatique', 'Réseaux', '2025-2026', 1, 'Licence 1', true], array_slice(array_values($d['rows'][0]), 1));
        $this->assertStringNotContainsString('101 202 303', json_encode($d));
        $this->assertStringNotContainsString('secret', json_encode($d));
        $this->assertStringContainsString('no-store', (string) $this->students($this->registrar())->headers->get('Cache-Control'));
    }

    public function test_removed_records_are_never_listed(): void
    {
        $this->student('E1', 'Andria', 'Zo');
        $this->student('E2', 'Bema', 'Lova', ['remove' => 1]);
        $this->course('INF 1', 'Visible');
        $this->course('INF 2', 'Supprimé', ['remove' => 1]);
        $this->teacher('Voahangy', 'Randria');
        $this->teacher('Ancien', 'Parti', ['remove' => 1]);
        $reg = $this->registrar();

        $this->assertSame(['E1'], array_column($this->students($reg)->json('data.rows'), 'student_id'));
        $this->assertSame(['Visible'], array_column($this->api($reg)->getJson('/api/v1/staff/lists/courses')->json('data.rows'), 'title'));
        $this->assertSame(['Randria'], array_column($this->api($reg)->getJson('/api/v1/staff/lists/teachers')->json('data.rows'), 'last_name'));
    }

    // ------------------------------------------------------------------ recherche

    public function test_every_word_must_be_found_in_at_least_one_field(): void
    {
        $a = $this->student('E1', 'Andria', 'Zo', ['student_email' => 'zo@exemple.test']);
        $b = $this->student('E2', 'Andria', 'Lova', ['student_tel' => '0341234567']);
        $this->student('E3', 'Bema', 'Zo');
        $reg = $this->registrar();

        $this->assertEqualsCanonicalizing([$a, $b], $this->ids($this->students($reg, '?q=andria')->json('data')));
        $this->assertSame([$a], $this->ids($this->students($reg, '?q=andria+zo')->json('data')), 'les deux mots, dans des champs différents');
        $this->assertSame([$a], $this->ids($this->students($reg, '?q=E1')->json('data')), 'le matricule');
        $this->assertSame([$a], $this->ids($this->students($reg, '?q=exemple.test')->json('data')), 'l’e-mail');
        $this->assertSame([$b], $this->ids($this->students($reg, '?q=0341234')->json('data')), 'le téléphone');
        $this->assertSame([], $this->ids($this->students($reg, '?q=zzzz')->json('data')));
        $this->assertSame(3, $this->students($reg, '?q=+++')->json('data.total'), 'une recherche vide ne filtre rien');
    }

    public function test_the_search_normalises_spaces_and_caps_length_and_word_count(): void
    {
        $this->student('E1', 'Andria', 'Zo');
        $reg = $this->registrar();

        $this->assertSame('andria zo', $this->students($reg, '?q=++andria+++zo++')->json('data.state.q'));
        $this->assertSame(120, mb_strlen($this->students($reg, '?q='.str_repeat('a', 300))->json('data.state.q')));
        // seuls les 6 premiers mots comptent : le 7e (introuvable) est ignoré
        $this->assertSame(1, $this->students($reg, '?q=andria+zo+e1+a+a+a+introuvable')->json('data.total'));
        $this->assertSame(1, $this->students($reg, '?q[]=x')->json('data.total'), 'un q de type tableau est ignoré, sans erreur');
    }

    public function test_the_course_search_also_finds_by_teacher_name(): void
    {
        $uid = $this->teacher('Hasina', 'Rasolofo');
        $mine = $this->course('INF 10', 'Algorithmique', ['id_teacher' => $uid]);
        $this->course('INF 11', 'Bases de données');
        $reg = $this->registrar();

        $this->assertSame([$mine], $this->ids($this->api($reg)->getJson('/api/v1/staff/lists/courses?q=rasolofo')->json('data')));
        $this->assertSame([$mine], $this->ids($this->api($reg)->getJson('/api/v1/staff/lists/courses?q=algo')->json('data')));
    }

    // ------------------------------------------------------------------ filtres

    public function test_values_of_one_filter_are_or_and_filters_are_and(): void
    {
        $a = $this->student('E1', 'A', 'A', ['etude_envisage' => 'Informatique', 'annee_etude' => '1']);
        $b = $this->student('E2', 'B', 'B', ['etude_envisage' => 'Education', 'annee_etude' => '1']);
        $c = $this->student('E3', 'C', 'C', ['etude_envisage' => 'Gestion', 'annee_etude' => '2']);
        $reg = $this->registrar();

        $this->assertEqualsCanonicalizing([$a, $b], $this->ids($this->students($reg, '?mention[]=Informatique&mention[]=Education')->json('data')));
        $this->assertSame([$a], $this->ids($this->students($reg, '?mention=Informatique&niveau=1')->json('data')));
        $this->assertSame([], $this->ids($this->students($reg, '?mention=Gestion&niveau=1')->json('data')), 'ET entre filtres');
        $this->assertSame([$c], $this->ids($this->students($reg, '?niveau=2')->json('data')));
    }

    public function test_case_filters_and_unknown_filters_or_values(): void
    {
        $new = $this->student('E1', 'A', 'A', ['new_student' => 1]);
        $old = $this->student('E2', 'B', 'B', ['new_student' => 0]);
        $unset = $this->student('E3', 'C', 'C', ['new_student' => null]);
        $reg = $this->registrar();

        $this->assertSame([$new], $this->ids($this->students($reg, '?statut=nouveau')->json('data')));
        $this->assertEqualsCanonicalizing([$old, $unset], $this->ids($this->students($reg, '?statut=ancien')->json('data')));
        $this->assertSame(3, $this->students($reg, '?statut=nimporte')->json('data.total'), 'une valeur de cas inconnue est ignorée');
        $this->assertSame(3, $this->students($reg, '?inconnu=1&statut[]=')->json('data.total'), 'un filtre inconnu ou vide est ignoré');
        $this->assertSame(['statut' => ['nouveau']], $this->students($reg, '?statut[]=nouveau&statut[]=nimporte')->json('data.state.filters'));
    }

    public function test_facets_count_rows_with_the_other_filters_but_not_their_own(): void
    {
        $this->student('E1', 'A', 'A', ['etude_envisage' => 'Informatique', 'annee_etude' => '1']);
        $this->student('E2', 'B', 'B', ['etude_envisage' => 'Informatique', 'annee_etude' => '2']);
        $this->student('E3', 'C', 'C', ['etude_envisage' => 'Education', 'annee_etude' => '1']);
        $reg = $this->registrar();

        $f = $this->students($reg, '?mention=Informatique')->json('data.facets');

        $this->assertSame([['Education', 1, false], ['Informatique', 2, true]], array_map(fn ($o) => [$o['label'], $o['count'], $o['on']], $f['mention']['options']), 'son propre filtre ne le restreint pas');
        $this->assertSame([['Licence 1', 1, false], ['Licence 2', 1, false]], array_map(fn ($o) => [$o['label'], $o['count'], $o['on']], $f['niveau']['options']), 'les autres filtres, si');
    }

    public function test_the_track_filter_opens_only_once_a_mention_is_chosen(): void
    {
        $this->student('E1', 'A', 'A', ['etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux']);
        $this->student('E2', 'B', 'B', ['etude_envisage' => 'Education', 'etude_option' => 'Pédagogie']);
        $reg = $this->registrar();

        $closed = $this->students($reg)->json('data.facets.parcours');
        $this->assertSame([], $closed['options']);
        $this->assertSame('Choisissez d’abord une mention pour voir ses parcours.', $closed['help']);

        $open = $this->students($reg, '?mention=Informatique')->json('data.facets.parcours');
        $this->assertSame(['Réseaux'], array_column($open['options'], 'label'));
        $this->assertNull($open['help']);
    }

    public function test_a_chosen_value_stays_offered_even_at_zero(): void
    {
        $this->student('E1', 'A', 'A', ['annee_etude' => '1']);
        $reg = $this->registrar();

        $o = $this->students($reg, '?niveau=3')->json('data.facets.niveau.options');

        $this->assertSame([['Licence 1', 1, false], ['Licence 3', 0, true]], array_map(fn ($x) => [$x['label'], $x['count'], $x['on']], $o));
    }

    public function test_facet_options_are_ordered_by_their_own_rule(): void
    {
        foreach ([['E1', '2024-2025'], ['E2', '2026-2027'], ['E3', '2025-2026']] as [$id, $year]) {
            $this->student($id, $id, $id, ['annee_scolaire' => $year]);
        }
        $this->student('E4', 'E4', 'E4', ['etude_envisage' => 'Éducation']);
        $this->student('E5', 'E5', 'E5', ['etude_envisage' => 'Gestion']);
        $reg = $this->registrar();
        $f = $this->students($reg)->json('data.facets');

        $this->assertSame(['2026-2027', '2025-2026', '2024-2025'], array_column($f['annee']['options'], 'value'), 'les années, la plus récente d’abord');
        $mentions = array_column($f['mention']['options'], 'label');
        $this->assertLessThan(array_search('Gestion', $mentions, true), array_search('Éducation', $mentions, true), 'alphabétique sans accents : Éducation avant Gestion');
    }

    // ------------------------------------------------------------------ tri et pages

    public function test_sorting_asc_desc_with_ties_broken_by_the_next_column_and_the_key(): void
    {
        $b = $this->student('E2', 'Bema', 'Lova');
        $a2 = $this->student('E1', 'Andria', 'Zo');
        $a1 = $this->student('E3', 'Andria', 'Bako');
        $reg = $this->registrar();

        $this->assertSame([$a1, $a2, $b], $this->ids($this->students($reg, '?tri=nom')->json('data')), 'nom, puis prénom');
        $this->assertSame([$b, $a1, $a2], $this->ids($this->students($reg, '?tri=nom&sens=desc')->json('data')), 'le sens ne renverse pas le critère suivant');
        $this->assertSame([$a2, $b, $a1], $this->ids($this->students($reg, '?tri=matricule')->json('data')), 'E1, E2, E3');
        $this->assertSame([$a1, $a2, $b], $this->ids($this->students($reg)->json('data')), 'par défaut : les plus récents d’abord');
    }

    public function test_an_unknown_sort_or_direction_falls_back_to_the_default(): void
    {
        $this->student('E1', 'A', 'A');
        $reg = $this->registrar();

        $s = $this->students($reg, '?tri=password&sens=sideways')->assertOk()->json('data.state');

        $this->assertSame(['recent', 'desc'], [$s['sort'], $s['direction']]);
    }

    public function test_empty_values_sort_last_whatever_the_direction(): void
    {
        $none = $this->student('E1', 'A', 'A', ['etude_option' => '']);
        $x = $this->student('E2', 'B', 'B', ['etude_option' => 'Alpha']);
        $y = $this->student('E3', 'C', 'C', ['etude_option' => 'Zeta']);
        $reg = $this->registrar();

        $this->assertSame([$x, $y, $none], $this->ids($this->students($reg, '?tri=parcours&sens=asc')->json('data')));
        $this->assertSame([$y, $x, $none], $this->ids($this->students($reg, '?tri=parcours&sens=desc')->json('data')));
    }

    public function test_pages_of_two_hundred_and_the_remaining_count(): void
    {
        $rows = [];
        for ($i = 1; $i <= 205; $i++) {
            $rows[] = ['student_id' => sprintf('P%03d', $i), 'student_nom' => 'Nom', 'student_prenom' => 'Pre', 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1', 'remove' => 0, 'annee_etude' => '1'];
        }
        DB::table('tbl_2024_etudiant')->insert($rows);
        $reg = $this->registrar();

        $p0 = $this->students($reg, '?tri=matricule')->json('data');
        $p1 = $this->students($reg, '?tri=matricule&page=1')->json('data');

        $this->assertSame([205, 200, 5, 200], [$p0['total'], $p0['shown'], $p0['remaining'], count($p0['rows'])]);
        $this->assertSame([205, 205, 0, 5], [$p1['total'], $p1['shown'], $p1['remaining'], count($p1['rows'])]);
        $this->assertSame(['P201', 'P205'], [$p1['rows'][0]['student_id'], $p1['rows'][4]['student_id']]);
        $this->assertSame(0, $this->students($reg, '?page=-4')->json('data.state.page'));
        $this->assertSame(200, $this->students($reg, '?page=99999')->json('data.state.page'));
        $this->assertSame([], $this->students($reg, '?page=200&tri=matricule')->json('data.rows'));
    }

    // ------------------------------------------------------------------ cours et enseignants

    public function test_the_course_row_shows_the_displayed_code_the_teacher_and_labels(): void
    {
        $uid = $this->teacher('Hasina', 'Rasolofo');
        DB::table('filiere')->insert(['filiere_id' => 91, 'filiere_sigle' => 'INFO', 'filiere_description' => 'Informatique', 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '']);
        DB::table('filiere_parcours')->insert(['shortcode' => 'RES', 'description' => 'Réseaux', 'description_ang' => '', 'departement' => 'INFO']);
        $this->course('INF 100', 'Algorithmique', ['sigle_affiche' => 'INF-A', 'id_teacher' => $uid, 'parcours' => 'RES', 'yearlevel' => 4, 'semester' => 2, 'category' => 2, 'nb_crd' => 5]);
        $this->course('INF 101', 'Systèmes');
        $reg = $this->registrar();

        $rows = collect($this->api($reg)->getJson('/api/v1/staff/lists/courses?tri=sigle')->json('data.rows'))->keyBy('title');

        $this->assertSame(['INF-A', 'HASINA Rasolofo', 'Réseaux', 'Master 1', 2, 'Sélectif', 5], [$rows['Algorithmique']['code'], $rows['Algorithmique']['teacher'], $rows['Algorithmique']['track'], $rows['Algorithmique']['level_label'], $rows['Algorithmique']['semester'], $rows['Algorithmique']['category'], $rows['Algorithmique']['credits']]);
        $this->assertSame(['INF 101', null, 'Tronc commun'], [$rows['Systèmes']['code'], $rows['Systèmes']['teacher'], $rows['Systèmes']['track']]);
        $f = $this->api($reg)->getJson('/api/v1/staff/lists/courses')->json('data.facets');
        $this->assertSame(['Informatique'], array_column($f['mention']['options'], 'label'), 'le nom de la mention vient des réglages');
        $this->assertSame([['Enseignant désigné', 1], ['Sans enseignant', 1]], array_map(fn ($o) => [$o['label'], $o['count']], $f['enseignant']['options']));
    }

    public function test_a_course_attached_to_a_nameless_teacher_counts_as_without_teacher(): void
    {
        $ghost = $this->teacher('', '');
        $c = $this->course('INF 1', 'Orphelin', ['id_teacher' => $ghost]);
        $reg = $this->registrar();

        $d = $this->api($reg)->getJson('/api/v1/staff/lists/courses?enseignant=sans')->json('data');

        $this->assertSame([$c], $this->ids($d));
        $this->assertNull($d['rows'][0]['teacher']);
    }

    public function test_teacher_filters_by_place_and_religion(): void
    {
        $campus = $this->teacher('A', 'A', ['address' => 'Campus UAZ', 'religion' => 'Adventiste']);
        $away = $this->teacher('B', 'B', ['address' => 'Analakely', 'religion' => 'non adventiste']);
        $unknown = $this->teacher('C', 'C', ['address' => '', 'religion' => 'SDA']);
        $reg = $this->registrar();
        $ids = fn (string $q) => array_column($this->api($reg)->getJson('/api/v1/staff/lists/teachers'.$q)->json('data.rows'), 'last_name');

        $this->assertSame(['A'], $ids('?lieu=campus'));
        $this->assertSame(['B'], $ids('?lieu=hors'));
        $this->assertSame(['C'], $ids('?lieu=inconnu'));
        $this->assertEqualsCanonicalizing(['A', 'C'], $ids('?religion=adventiste'), '« non adventiste » n’est pas adventiste');
        $this->assertSame(['B'], $ids('?religion=autre'));
        $this->assertSame(['name', 'last_name', 'address', 'phone', 'email'], array_slice(array_keys($this->api($reg)->getJson('/api/v1/staff/lists/teachers')->json('data.rows.0')), 1));
        unset($campus, $away, $unknown);
    }

    // ------------------------------------------------------------------ périmètres

    public function test_an_account_limited_to_a_cycle_sees_only_that_cycle(): void
    {
        $l1 = $this->student('E1', 'A', 'A', ['annee_etude' => '1']);
        $l0 = $this->student('E2', 'B', 'B', ['annee_etude' => '0']);
        $m1 = $this->student('E3', 'C', 'C', ['annee_etude' => '4']);
        $none = $this->student('E4', 'D', 'D', ['annee_etude' => '']);
        $cl = $this->course('INF 1', 'Licence', ['yearlevel' => 2]);
        $cm = $this->course('INF 2', 'Master', ['yearlevel' => 5]);
        $licence = $this->registrar(['perimetre_cycle' => 'L']);
        $master = $this->registrar(['perimetre_cycle' => 'M']);

        $this->assertEqualsCanonicalizing([$l1, $l0], $this->ids($this->students($licence)->json('data')), 'la remise à niveau fait partie de la Licence');
        $this->assertSame([$m1], $this->ids($this->students($master)->json('data')));
        $this->assertContains($none, $this->ids($this->students($this->registrar())->json('data')), 'sans périmètre : tout, y compris sans niveau');
        $this->assertNotContains($none, $this->ids($this->students($licence)->json('data')));
        $this->assertSame([$cl], $this->ids($this->api($licence)->getJson('/api/v1/staff/lists/courses')->json('data')));
        $this->assertSame([$cm], $this->ids($this->api($master)->getJson('/api/v1/staff/lists/courses')->json('data')));
        $this->assertSame(2, $this->students($licence)->json('data.total'), 'le compteur et les filtres suivent le périmètre');
        $this->assertSame(['Remise à niveau', 'Licence 1'], array_column($this->students($licence)->json('data.facets.niveau.options'), 'label'));
    }

    public function test_a_chief_of_mention_sees_only_their_mention(): void
    {
        $mine = $this->student('E1', 'A', 'A', ['etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux']);
        $this->student('E2', 'B', 'B', ['etude_envisage' => 'Education']);
        $chief = User::factory()->staff(6, 'chef_mention')->create(['perimetre_mention' => 'Informatique']);

        $d = $this->students($chief)->assertOk()->json('data');

        $this->assertSame([$mine], $this->ids($d));
        $this->assertSame('Étudiants de la mention Informatique.', $d['notice']);
        $this->assertArrayNotHasKey('mention', $d['facets'], 'le filtre Mention n’a plus de sens');
        $this->assertSame(['Réseaux'], array_column($d['facets']['parcours']['options'], 'label'), 'le parcours s’ouvre sans attendre');
        $this->assertSame([$mine], $this->ids($this->students($chief, '?mention=Education')->json('data')), 'impossible de sortir de sa mention en forçant le filtre');
    }

    public function test_a_chief_of_mention_without_a_mention_sees_nobody_and_is_told_why(): void
    {
        $this->student('E1', 'A', 'A');
        $chief = User::factory()->staff(6, 'chef_mention')->create(['perimetre_mention' => null]);

        $d = $this->students($chief)->assertOk()->json('data');

        $this->assertSame([0, []], [$d['total'], $d['rows']]);
        $this->assertStringContainsString('Aucune mention n’est attribuée', $d['notice']);
    }

    public function test_a_search_cannot_escape_the_perimeter(): void
    {
        $this->student('E1', 'Andria', 'A', ['annee_etude' => '4']);
        $this->student('E2', 'Andria', 'B', ['annee_etude' => '1']);
        $licence = $this->registrar(['perimetre_cycle' => 'L']);

        $this->assertSame(1, $this->students($licence, '?q=andria')->json('data.total'));
        $this->assertSame(1, $this->students($licence, '?q=andria&niveau[]=4&niveau[]=1')->json('data.total'));
        $this->assertSame(0, $this->students($licence, '?niveau=4')->json('data.total'));
    }

    public function test_more_hardening_cases(): void
    {
        // un « chef de mention » de niveau 1 à 3 n'est pas limité (comme l'ancienne règle)
        $this->student('E1', 'A', 'A', ['etude_envisage' => 'Education']);
        $senior = User::factory()->staff(3, 'chef_mention')->create(['perimetre_mention' => 'Informatique']);
        $this->assertSame(1, $this->students($senior)->json('data.total'));

        // cas multiples d'un même filtre : OU
        $this->student('E2', 'B', 'B', ['new_student' => 1]);
        $this->assertSame(2, $this->students($this->registrar(), '?statut[]=nouveau&statut[]=ancien')->json('data.total'));

        // 40 valeurs au plus par filtre
        $many = implode('&', array_map(fn ($i) => "niveau[]=v{$i}", range(1, 60)));
        $this->assertCount(40, $this->students($this->registrar(), '?'.$many)->json('data.state.filters.niveau'));

        // égalité sur le critère de tri : le plus récent d'abord, d'une page à l'autre
        $x = $this->student('E3', 'Same', 'Same');
        $y = $this->student('E4', 'Same', 'Same');
        $this->assertSame([$y, $x], array_slice($this->ids($this->students($this->registrar(), '?q=same&tri=nom')->json('data')), 0, 2));
    }

    public function test_course_categories_semesters_are_labelled_and_ordered_numerically(): void
    {
        foreach ([[-1, 'Sélectif'], [0, 'Général'], [1, 'Majeur'], [2, 'Sélectif'], [3, 'Additionnel'], [9, 'Autre']] as $i => [$cat, $label]) {
            $this->course('C '.$i, 'T'.$i, ['category' => $cat, 'semester' => $i === 0 ? 10 : 2]);
        }
        $rows = collect($this->api($this->registrar())->getJson('/api/v1/staff/lists/courses?tri=sigle')->json('data.rows'))->pluck('category', 'title')->all();
        $this->assertSame(['T0' => 'Sélectif', 'T1' => 'Général', 'T2' => 'Majeur', 'T3' => 'Sélectif', 'T4' => 'Additionnel', 'T5' => 'Autre'], $rows);

        $f = $this->api($this->registrar())->getJson('/api/v1/staff/lists/courses')->json('data.facets');
        $this->assertSame(['2', '10'], array_column($f['semestre']['options'], 'value'), '2 avant 10 (ordre numérique)');
    }

    // ------------------------------------------------------------------ robustesse

    public function test_hostile_values_never_reach_the_sql(): void
    {
        $a = $this->student('E1', 'Andria', 'Zo');
        $reg = $this->registrar();
        $evil = urlencode("' OR 1=1 --");

        foreach (["?q={$evil}", "?mention={$evil}", "?niveau[]={$evil}", "?tri={$evil}", "?sens={$evil}", "?page={$evil}"] as $query) {
            $r = $this->students($reg, $query)->assertOk()->json('data');
            $this->assertLessThanOrEqual(1, $r['total'], $query);
        }
        $this->assertSame([$a], $this->ids($this->students($reg, '?tri='.$evil)->json('data')));
        $this->assertSame(1, DB::table('tbl_2024_etudiant')->count());
    }

    public function test_the_domain_checks_access_and_the_list_name_on_its_own(): void
    {
        $dir = app(Directory::class);

        foreach ([['teachers', User::factory()->staff(2, 'admin')->create()], ['accounts', $this->registrar()]] as [$list, $who]) {
            try {
                $dir->listing($who, $list, []);
                $this->fail('liste ouverte');
            } catch (\App\Exceptions\ApiProblem $e) {
                $this->assertContains($e->status, [403, 404]);
            }
        }
    }
}
