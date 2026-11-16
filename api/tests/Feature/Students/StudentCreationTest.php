<?php

namespace Tests\Feature\Students;

use App\Domain\Students\StudentAddress;
use App\Domain\Students\StudentCreation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Création d'un étudiant : accès, propositions (matricule, adresse), validation, anti-doublon, conversion d'une candidature. */
class StudentCreationTest extends TestCase
{
    private int $n = 0;

    private int $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        DB::table('tbl_2024_etudiant')->delete();   // la base de test garde une fiche de référence : écartée le temps du test
        DB::table('filiere')->insert([
            ['filiere_id' => 91, 'filiere_sigle' => 'INFO', 'filiere_description' => 'Informatique', 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '', 'ouverte' => 1],
            ['filiere_id' => 92, 'filiere_sigle' => 'GEST', 'filiere_description' => 'Gestion', 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '', 'ouverte' => 1],
            ['filiere_id' => 93, 'filiere_sigle' => 'EDUC', 'filiere_description' => 'Éducation', 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '', 'ouverte' => 0],
        ]);
        DB::table('filiere_parcours')->insert([
            ['shortcode' => 'I_RES', 'description' => 'Réseaux', 'description_ang' => '', 'departement' => 'INFO', 'cycles' => 'L,M'],
            ['shortcode' => 'I_LIC', 'description' => 'Développement', 'description_ang' => '', 'departement' => 'INFO', 'cycles' => 'L'],
            ['shortcode' => 'I_MAS', 'description' => 'Intelligence artificielle', 'description_ang' => '', 'departement' => 'INFO', 'cycles' => 'M'],
            ['shortcode' => 'E_PED', 'description' => 'Pédagogie', 'description_ang' => '', 'departement' => 'EDUC', 'cycles' => 'L,M'],
        ]);
        $this->region = DB::table('region')->insertGetId(['region' => 'Vakinankaratra', 'chefLieu' => 'Antsirabe', 'province' => 'Antananarivo']);
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function registrar(array $attrs = []): User
    {
        return User::factory()->staff(3, 'registrar')->create($attrs);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Un formulaire complet et valide (licence 1, informatique). */
    private function form(array $over = []): array
    {
        return array_merge([
            'token' => $this->token(), 'last_name' => 'Rabe', 'first_name' => 'Zo Lalaina', 'sex' => 1, 'birth_date' => '2005-03-12', 'birth_place' => 'Antsirabe',
            'nationality' => 'Malagasy', 'origin_country' => 'Madagascar', 'marital_status' => 'Célibataire', 'religion' => 'Adventiste', 'region' => $this->region, 'address' => 'Lot 12 Antsirabe',
            'mention' => 'INFO', 'track' => 'Réseaux', 'level' => 1, 'academic_year' => '2026 - 2027', 'status' => 'Externe', 'phone' => '034 12 345 67',
        ], $over);
    }

    private function create(User $who, array $form)
    {
        return $this->api($who)->postJson('/api/v1/staff/students', $form);
    }

    private function student(string $id, string $mention = 'Informatique', int $level = 1, array $over = []): int
    {
        return DB::table('tbl_2024_etudiant')->insertGetId(array_merge([
            'student_id' => $id, 'student_nom' => 'N'.(++$this->n), 'student_prenom' => 'P', 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2025 - 2026', 'period' => '',
            'remove' => 0, 'etude_envisage' => $mention, 'annee_etude' => (string) $level,
        ], $over));
    }

    private function candidate(string $status = 'accepte', array $over = []): int
    {
        return DB::table('candidat')->insertGetId(array_merge([
            'nom_complet' => 'Rabe Zo Lalaina', 'nom' => 'Rabe', 'prenom' => 'Zo Lalaina', 'telephone' => '034 12 345 67', 'email' => 'zo@exemple.test', 'mention' => 'informatique', 'mention_libelle' => 'Informatique',
            'diplome_vise' => 'licence', 'statut' => $status, 'date_creation' => '2026-09-10 10:00:00', 'genre' => 'M', 'etat_civil' => 'celibataire', 'nationalite' => 'Malagasy',
            'pays_origine' => 'Madagascar', 'adresse' => 'Lot 12', 'nom_pere' => 'Papa Rabe', 'nom_mere' => 'Maman Rabe', 'contact_parent' => '0321111111', 'tuteur' => 'Oncle', 'contact_tuteur' => '0322222222',
            'date_naissance' => '2005-03-12', 'lieu_naissance' => 'Antsirabe', 'parcours' => 'Réseaux',
        ], $over));
    }

    // ------------------------------------------------------------------ accès

    public function test_only_levels_one_to_three_can_create_students(): void
    {
        $denied = [User::factory()->staff(4, 'comptabilite')->create(), User::factory()->staff(5, 'media')->create(), User::factory()->staff(6, 'chef_mention')->create(),
            User::factory()->staff(7, 'teacher')->create(), User::factory()->create(), User::factory()->staff(9, 'work_education')->create(), User::factory()->staff(3, 'registrar')->inactive()->create()];

        foreach ($denied as $who) {
            $this->api($who)->getJson('/api/v1/staff/student-creation/options')->assertForbidden()->assertJsonPath('code', 'forbidden.student_creation');
            $this->api($who)->getJson('/api/v1/staff/student-creation/suggest?mention=INFO&level=1')->assertForbidden();
            $this->api($who)->getJson('/api/v1/staff/student-creation/from-application/1')->assertForbidden();
            $this->create($who, $this->form())->assertForbidden();
            $this->api($who)->getJson('/api/v1/staff/students/1/google')->assertForbidden();
            $this->api($who)->postJson('/api/v1/staff/students/1/google/retry')->assertForbidden();
        }
        $this->assertSame(0, DB::table('tbl_2024_etudiant')->count());
        foreach ([User::factory()->staff(1, 'superadmin')->create(), User::factory()->staff(2, 'admin')->create(), $this->registrar()] as $who) {
            $this->api($who)->getJson('/api/v1/staff/student-creation/options')->assertOk();
        }
    }

    public function test_access_is_checked_before_validation_so_a_forbidden_account_never_learns_the_fields(): void
    {
        foreach ([User::factory()->staff(7, 'teacher')->create(), User::factory()->staff(4, 'comptabilite')->create(), User::factory()->create()] as $who) {
            $this->api($who)->postJson('/api/v1/staff/students', [])->assertForbidden()->assertJsonMissingValidationErrors();
        }
    }

    public function test_a_session_is_required_and_the_profile_tells_who_can_create(): void
    {
        $this->postJson('/api/v1/staff/students', $this->form())->assertUnauthorized();
        $this->getJson('/api/v1/staff/student-creation/options')->assertUnauthorized();
    }

    public function test_the_profile_flag(): void
    {
        $flag = fn (User $u) => $this->api($u)->getJson('/api/v1/auth/me')->json('data.student_creation');

        $this->assertTrue($flag($this->registrar()));
        $this->assertTrue($flag(User::factory()->staff(2, 'admin')->create()));
        $this->assertFalse($flag(User::factory()->staff(4, 'comptabilite')->create()));
        $this->assertFalse($flag(User::factory()->create()));
    }

    // ------------------------------------------------------------------ choix et propositions

    public function test_the_options_list_mentions_tracks_regions_and_eight_years(): void
    {
        $d = $this->api($this->registrar())->getJson('/api/v1/staff/student-creation/options')->assertOk()->json('data');

        $this->assertSame(['EDUC' => false, 'GEST' => true, 'INFO' => true], collect($d['mentions'])->pluck('open', 'code')->sortKeys()->all());
        $this->assertContains(['code' => 'I_MAS', 'label' => 'Intelligence artificielle', 'mention' => 'INFO', 'cycles' => 'M'], $d['tracks']);
        $this->assertSame([['id' => $this->region, 'name' => 'Vakinankaratra']], $d['regions']);
        $this->assertSame(['2026 - 2027', '2025 - 2026', '2024 - 2025', '2023 - 2024', '2022 - 2023', '2021 - 2022', '2020 - 2021', '2019 - 2020'], $d['years']);
        $this->assertSame(['Externe', 'Interne', 'Bungalow'], $d['statuses']);
        $this->assertContains('Technique Ouvrage Bois', $d['series']);
        $this->assertSame(['S', 'M', 'L', 'XL', 'XXL', 'XXXL'], $d['sizes']);
    }

    public function test_the_next_student_id_follows_the_mention_range_and_the_cycle(): void
    {
        $this->student('30010', 'Informatique', 2);
        $this->student('9999', 'Informatique', 1);        // plus petit : sans effet (ordre numérique)
        $this->student('30481MI', 'Informatique', 4);
        $this->student('20040', 'Gestion', 1);
        $s = fn (string $mention, int $level) => $this->api($this->registrar())->getJson("/api/v1/staff/student-creation/suggest?mention={$mention}&level={$level}")->assertOk()->json('data.student_id');

        $this->assertSame('30011', $s('INFO', 1), 'licence : le plus grand numéro de la mention + 1');
        $this->assertSame('30011', $s('INFO', 0), 'la remise à niveau est en licence');
        $this->assertSame('30482MI', $s('INFO', 4), 'master : suffixe M + initiale, numérotation séparée');
        $this->assertSame('30482MI', $s('INFO', 5));
        $this->assertSame('20041', $s('GEST', 3));
        $this->assertSame('20000MG', $s('GEST', 4), 'master sans étudiant : début de plage (les licences n\x27y comptent pas)');
        $this->assertSame('00000', $s('ZZZZ', 1), 'mention inconnue : rien de sensé à proposer');
        $this->assertSame('50000', $s('EDUC', 1), 'une mention fermée garde sa plage');
    }

    public function test_an_empty_mention_starts_at_its_range_and_a_taken_number_is_skipped(): void
    {
        $this->assertSame('30000', app(StudentCreation::class)->nextStudentId('INFO', 1));
        $this->assertSame('30000MI', app(StudentCreation::class)->nextStudentId('INFO', 4));
        $this->student('30000', 'Autre mention', 1);      // pris, mais hors de cette mention : le numéro de début est sauté
        $this->assertSame('30001', app(StudentCreation::class)->nextStudentId('INFO', 1));
    }

    public function test_a_free_student_id_keeps_the_suffix_when_it_moves_forward(): void
    {
        $this->student('20481MG');
        $this->student('20482MG');

        $this->assertSame('20483MG', StudentCreation::freeStudentId('20481MG'));
        $this->assertSame('777', StudentCreation::freeStudentId('777'));
        $this->assertSame('abc', StudentCreation::freeStudentId('abc'), 'format inattendu : on ne touche à rien');
        $this->assertSame('', StudentCreation::freeStudentId(''));
    }

    public function test_the_proposed_university_address_comes_from_the_name_and_is_never_one_already_taken(): void
    {
        $this->student('1', 'Informatique', 1, ['student_email' => 'rabe.zo@zurcher.edu.mg']);
        DB::table('teacher')->insert(['uid' => 840001, 'name' => 'X', 'lastName' => 'Y', 'email' => 'rabe.zol@zurcher.edu.mg', 'sex' => 0, 'remove' => 0]);
        User::factory()->staff(7, 'teacher')->create(['mail' => 'rabe.zola@zurcher.edu.mg']);
        $this->student('2', 'Informatique', 1, ['student_email' => 'RABE.ZOLAL@zurcher.edu.mg', 'remove' => 1]);   // même supprimée : son compte Google peut exister

        $d = $this->api($this->registrar())->getJson('/api/v1/staff/student-creation/suggest?mention=INFO&level=1&last_name='.urlencode('Rabé').'&first_name='.urlencode('Zolalaina'))->json('data');

        $this->assertSame(['rabe.zolala@zurcher.edu.mg', 'rabe.zol@zurcher.edu.mg'], [$d['email'], $d['email_usual']], 'zol (enseignant), zola (compte), zolal (fiche supprimée) sont pris : zolala est libre');
    }

    public function test_address_tries_run_from_three_letters_to_the_whole_first_name_then_numbers(): void
    {
        $this->assertSame(['address' => 'rabe.zo@zurcher.edu.mg', 'usual' => 'rabe.zo@zurcher.edu.mg'], StudentAddress::free('Rabe', 'Zo'));
        DB::table('tbl_2024_etudiant')->insert(['student_id' => 'A', 'student_nom' => 'x', 'student_prenom' => 'x', 'password' => '', 'abonment' => 0, 'annee_scolaire' => '', 'period' => '', 'remove' => 0, 'student_email' => 'rabe.zo@zurcher.edu.mg']);
        $this->assertSame('rabe.zo2@zurcher.edu.mg', StudentAddress::free('Rabe', 'Zo')['address'], 'prénom court : on passe aux numéros');
        $this->assertSame('rabe.lal@zurcher.edu.mg', StudentAddress::free('Rabe', 'Lalaina')['address']);
        $this->assertSame('rabe.uaz@zurcher.edu.mg', StudentAddress::free('Rabe', '')['address']);
        $this->assertSame(['address' => '', 'usual' => ''], StudentAddress::free('', 'Zo'));
        $this->assertSame('rabe.zo2@zurcher.edu.mg', StudentAddress::free('Rabé', 'Zô')['address'], 'les accents sont retirés avant de comparer');
        $this->assertSame('rabe.zo2@zurcher.edu.mg', StudentAddress::free("R'abe", 'Zo')['address'], 'l\'apostrophe aussi');
    }

    public function test_normalisation_keeps_only_lowercase_letters_and_digits(): void
    {
        $this->assertSame('rakotoarisoa', StudentAddress::normalize(" Rakoto-Arisoa "));
        $this->assertSame('alafetedeca', StudentAddress::normalize('À la fête dé Çà'));
        $this->assertSame('mariejoseph2', StudentAddress::normalize("Marie-Joseph 2"));
        $this->assertSame('eleonore', StudentAddress::normalize('Éléonore'));
        $this->assertSame('oeuvre', StudentAddress::normalize('Œuvre'));
        $this->assertSame('', StudentAddress::normalize("'-  "));
    }

    // ------------------------------------------------------------------ depuis une candidature

    public function test_an_accepted_application_prefills_the_form_without_the_personal_email(): void
    {
        $id = $this->candidate('accepte');

        $d = $this->api($this->registrar())->getJson("/api/v1/staff/student-creation/from-application/{$id}")->assertOk()->json('data');

        $this->assertSame(['Rabe', 'Zo Lalaina', 'INFO', 'Réseaux', 1, 1, 'Célibataire', false], [$d['last_name'], $d['first_name'], $d['mention'], $d['track'], $d['sex'], $d['level'], $d['marital_status'], $d['is_master']]);
        $this->assertSame(['Papa Rabe', 'Maman Rabe', '0321111111', 'Oncle', '0322222222', '034 12 345 67'], [$d['father_name'], $d['mother_name'], $d['parent_phone'], $d['sponsor_name'], $d['sponsor_phone'], $d['phone']]);
        $this->assertArrayNotHasKey('email', $d, 'l’adresse personnelle n’est pas reprise comme adresse institutionnelle');
        $this->assertStringNotContainsString('zo@exemple.test', json_encode($d));
    }

    public function test_the_prefill_maps_the_public_form_values(): void
    {
        $f = $this->candidate('accepte', ['nom' => '', 'prenom' => '', 'nom_complet' => 'Rasoa Marie Claire', 'genre' => 'F', 'etat_civil' => 'divorce', 'mention' => 'etudes-anglophones', 'diplome_vise' => 'master']);
        DB::table('filiere')->insert(['filiere_id' => 94, 'filiere_sigle' => 'LANG', 'filiere_description' => 'Etudes anglophones', 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '', 'ouverte' => 1]);
        $unknown = $this->candidate('accepte', ['mention' => 'astrologie', 'genre' => null]);
        $reg = $this->registrar();

        $d = $this->api($reg)->getJson("/api/v1/staff/student-creation/from-application/{$f}")->json('data');
        $u = $this->api($reg)->getJson("/api/v1/staff/student-creation/from-application/{$unknown}")->json('data');

        $this->assertSame(['Rasoa', 'Marie Claire', 0, null, 'LANG', 4, true], [$d['last_name'], $d['first_name'], $d['sex'], $d['marital_status'], $d['mention'], $d['level'], $d['is_master']], 'nom coupé au premier espace ; divorcé : pas d’équivalent');
        $this->assertSame(['', null], [$u['mention'], $u['sex']], 'mention inconnue : l’agent choisit lui-même');
    }

    public function test_only_an_accepted_unconverted_application_opens_the_form(): void
    {
        $reg = $this->registrar();
        foreach (['nouveau', 'en_cours', 'refuse'] as $status) {
            $id = $this->candidate($status);
            $this->api($reg)->getJson("/api/v1/staff/student-creation/from-application/{$id}")->assertStatus(409)->assertJsonPath('code', 'students.application_not_accepted');
        }
        $fiche = $this->student('UAZ-7');
        $done = $this->candidate('converti', ['student_id' => 'UAZ-7']);

        $r = $this->api($reg)->getJson("/api/v1/staff/student-creation/from-application/{$done}")->assertStatus(409)->assertJsonPath('code', 'students.application_converted');

        $this->assertSame(['UAZ-7', $fiche], [$r->json('student_id'), $r->json('student_file_id')]);
        $this->api($reg)->getJson('/api/v1/staff/student-creation/from-application/999999')->assertNotFound();
    }

    // ------------------------------------------------------------------ création

    public function test_a_valid_form_creates_the_student_with_a_matricule_an_address_and_a_random_password(): void
    {
        $this->student('30010', 'Informatique', 1);
        $reg = $this->registrar();

        $r = $this->create($reg, $this->form(['student_id' => '30011']))->assertCreated();
        $d = $r->json('data');

        $row = DB::table('tbl_2024_etudiant')->where('id', $d['id'])->first();
        $this->assertSame(['30011', 'Rabe', 'Zo Lalaina', 'Informatique', 'Réseaux', '1', '2026 - 2027', 'Externe', '1', 0, 0, '', '202730011'], [
            $row->student_id, $row->student_nom, $row->student_prenom, $row->etude_envisage, $row->etude_option, $row->annee_etude, $row->annee_scolaire, $row->status, $row->sex, $row->remove, $row->graduated, $row->period, $row->lookup_code,
        ]);
        $this->assertSame(['rabe.zol@zurcher.edu.mg', '2026-11-15', (int) $reg->id, (string) $this->region], [$row->student_email, $row->date_entry, (int) $row->last_change_user_id, $row->student_region]);
        $this->assertSame(1, (int) $row->new_student);
        $this->assertSame($d['initial_password'], $row->password);
        $this->assertMatchesRegularExpression('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)[A-HJ-NP-Za-km-z2-9]{12}$/', $d['initial_password']);
        $this->assertDoesNotMatchRegularExpression('/^\d{4}Student\d{4}$/', $d['initial_password']);
        $this->assertSame(['30011', 'rabe.zol@zurcher.edu.mg', false, null], [$d['student_id'], $d['email'], $d['already'], $d['email_notice']]);
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_two_students_never_get_the_same_random_password(): void
    {
        $a = $this->create($this->registrar(), $this->form(['last_name' => 'Aaa']))->json('data.initial_password');
        $b = $this->create($this->registrar(), $this->form(['last_name' => 'Bbb']))->json('data.initial_password');

        $this->assertNotSame($a, $b);
    }

    public function test_the_lookup_code_uses_the_year_of_the_next_school_year_from_july(): void
    {
        $this->travelTo('2026-03-10 10:00:00');
        $a = $this->create($this->registrar(), $this->form(['last_name' => 'Avant']))->json('data');
        $this->travelTo('2026-09-10 10:00:00');
        $b = $this->create($this->registrar(), $this->form(['last_name' => 'Apres']))->json('data');

        $this->assertSame('2026'.$a['student_id'], DB::table('tbl_2024_etudiant')->where('id', $a['id'])->value('lookup_code'));
        $this->assertSame('2027'.$b['student_id'], DB::table('tbl_2024_etudiant')->where('id', $b['id'])->value('lookup_code'));
    }

    public function test_a_proposed_matricule_taken_in_the_meantime_is_replaced_keeping_its_suffix(): void
    {
        $this->student('20481MG', 'Gestion', 4);

        $d = $this->create($this->registrar(), $this->form(['mention' => 'GEST', 'track' => 'Gestion', 'student_id' => '20481MG', 'level' => 4]));
        // GEST n'a pas ce parcours : refus propre
        $d->assertUnprocessable()->assertJsonPath('code', 'students.unknown_track');
        DB::table('filiere_parcours')->insert(['shortcode' => 'G_GEN', 'description' => 'Gestion générale', 'description_ang' => '', 'departement' => 'GEST', 'cycles' => 'L,M']);

        $r = $this->create($this->registrar(), $this->form(['mention' => 'GEST', 'track' => 'Gestion générale', 'student_id' => '20481MG', 'level' => 4]))->assertCreated();

        $this->assertSame('20482MG', $r->json('data.student_id'));
    }

    public function test_an_empty_matricule_gets_the_next_free_one(): void
    {
        $this->student('30010', 'Informatique', 1);

        $r = $this->create($this->registrar(), $this->form())->assertCreated();

        $this->assertSame('30011', $r->json('data.student_id'));
    }

    public function test_an_address_already_taken_is_replaced_and_the_agent_is_told(): void
    {
        $this->student('1', 'Informatique', 1, ['student_email' => 'rabe.zol@zurcher.edu.mg']);

        $d = $this->create($this->registrar(), $this->form(['email' => 'Rabe.Zol@zurcher.edu.mg']))->assertCreated()->json('data');

        $this->assertSame('rabe.zola@zurcher.edu.mg', $d['email']);
        $this->assertSame('L’adresse rabe.zol@zurcher.edu.mg était déjà prise : rabe.zola@zurcher.edu.mg a été attribuée.', $d['email_notice']);
    }

    public function test_a_free_address_typed_by_hand_is_kept_and_spaces_are_removed(): void
    {
        $d = $this->create($this->registrar(), $this->form(['email' => ' Zo.Rabe @zurcher.edu.mg']))->assertCreated()->json('data');

        $this->assertSame('zo.rabe@zurcher.edu.mg', $d['email']);
        $this->assertNull($d['email_notice']);
    }

    public function test_the_previous_diploma_goes_to_the_right_table_for_each_cycle(): void
    {
        $lic = $this->create($this->registrar(), $this->form(['last_name' => 'Lic', 'level' => 2, 'bacc_date' => '2023-07-05', 'bacc_series' => 'D']))->assertCreated()->json('data');
        $mas = $this->create($this->registrar(), $this->form(['last_name' => 'Mas', 'level' => 4, 'previous_degree' => 'Licence en informatique', 'previous_degree_date' => '2025-09-01']))->assertCreated()->json('data');

        $this->assertSame(['2023-07-05', 'D'], [DB::table('t_2024_bacc')->where('student_id', $lic['student_id'])->value('date_obtent'), DB::table('t_2024_bacc')->where('student_id', $lic['student_id'])->value('bacc_serie')]);
        $this->assertSame(0, DB::table('t_2024_diplome_preced')->where('student_id', $lic['student_id'])->count());
        $this->assertSame(['Licence en informatique', '2025-09-01'], [DB::table('t_2024_diplome_preced')->where('student_id', $mas['student_id'])->value('diplome_name'), DB::table('t_2024_diplome_preced')->where('student_id', $mas['student_id'])->value('date_obtent')]);
        $this->assertSame(0, DB::table('t_2024_bacc')->where('student_id', $mas['student_id'])->count());
    }

    public function test_diploma_fields_of_the_other_cycle_are_refused(): void
    {
        $reg = $this->registrar();

        $this->create($reg, $this->form(['level' => 1, 'previous_degree' => 'X']))->assertUnprocessable()->assertJsonValidationErrors('previous_degree');
        $this->create($reg, $this->form(['level' => 4, 'bacc_series' => 'D']))->assertUnprocessable()->assertJsonValidationErrors('bacc_series');
        $this->assertSame(0, DB::table('tbl_2024_etudiant')->count());
    }

    public function test_required_fields_and_formats_are_validated_before_anything_is_written(): void
    {
        $reg = $this->registrar();
        foreach (['last_name', 'first_name', 'sex', 'birth_date', 'birth_place', 'nationality', 'origin_country', 'marital_status', 'religion', 'region', 'address', 'mention', 'track', 'level', 'academic_year', 'status', 'token'] as $field) {
            $f = $this->form();
            unset($f[$field]);
            $this->create($reg, $f)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([['sex', 2], ['birth_date', '2099-01-01'], ['birth_date', '12/03/2005'], ['region', 99999], ['status', 'Dehors'], ['level', 9], ['level', -1], ['academic_year', '2026-2027'], ['token', 'xyz'],
            ['marital_status', 'Veuf'], ['religion', 'Pastafarisme'], ['email', 'pas-un-mail'], ['size', 'XS'], ['children', 99], ['student_id', 'abc'], ['student_id', '12345678901'], ['cin_region', 99999]] as [$field, $bad]) {
            $this->create($reg, $this->form([$field => $bad]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, DB::table('tbl_2024_etudiant')->count());
        $this->assertSame(0, DB::table('t_etudiant_creation')->count());
    }

    public function test_the_track_must_belong_to_the_mention_and_the_cycle(): void
    {
        $reg = $this->registrar();

        $this->create($reg, $this->form(['track' => 'Pédagogie']))->assertUnprocessable()->assertJsonPath('code', 'students.unknown_track');   // parcours d'une autre mention
        $this->create($reg, $this->form(['track' => 'Intelligence artificielle', 'level' => 1]))->assertUnprocessable()->assertJsonPath('code', 'students.unknown_track');   // master seulement
        $this->create($reg, $this->form(['track' => 'Développement', 'level' => 4]))->assertUnprocessable()->assertJsonPath('code', 'students.unknown_track');   // licence seulement
        $this->create($reg, $this->form(['track' => 'Inventé']))->assertUnprocessable();
        $this->create($reg, $this->form(['track' => 'Développement', 'level' => 0]))->assertCreated();    // la remise à niveau est en licence
        $this->create($reg, $this->form(['last_name' => 'Autre', 'track' => 'Intelligence artificielle', 'level' => 5]))->assertCreated();
    }

    public function test_a_closed_or_unknown_mention_is_refused_except_the_one_of_the_application(): void
    {
        $reg = $this->registrar();

        $this->create($reg, $this->form(['mention' => 'EDUC', 'track' => 'Pédagogie']))->assertUnprocessable()->assertJsonPath('code', 'students.closed_mention');
        $this->create($reg, $this->form(['mention' => 'ZZZZ']))->assertUnprocessable()->assertJsonPath('code', 'students.unknown_mention');

        $app = $this->candidate('accepte', ['mention' => 'education']);
        $this->create($reg, $this->form(['mention' => 'EDUC', 'track' => 'Pédagogie', 'application_id' => $app]))->assertCreated();
        $this->assertSame('converti', DB::table('candidat')->where('id', $app)->value('statut'));
    }

    public function test_every_mention_has_its_own_range(): void
    {
        $ranges = ['THEO' => '10000', 'GEST' => '20000', 'INFO' => '30000', 'NURS' => '40000', 'EDUC' => '50000', 'COMM' => '60000', 'LANG' => '70000', 'CPRE' => '80000', 'DROI' => '90000'];
        foreach ($ranges as $sigle => $start) {
            if (! DB::table('filiere')->where('filiere_sigle', $sigle)->exists()) {
                DB::table('filiere')->insert(['filiere_id' => 200 + (int) substr($start, 0, 1), 'filiere_sigle' => $sigle, 'filiere_description' => 'Mention '.$sigle, 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '', 'ouverte' => 1]);
            }
            $this->assertSame($start, app(StudentCreation::class)->nextStudentId($sigle, 1), $sigle);
        }
    }

    public function test_generated_passwords_never_contain_look_alike_characters(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', StudentCreation::initialPassword());
        }
    }

    public function test_the_domain_normalises_a_hand_typed_address_on_its_own(): void
    {
        $reg = $this->registrar();
        $form = $this->form(['email' => ' Zo.Rabe @zurcher.edu.mg', 'birth_date' => '2005-03-12']);

        $r = app(StudentCreation::class)->create($reg, $form, $this->token());

        $this->assertSame('zo.rabe@zurcher.edu.mg', $r['email']);
    }

    public function test_an_underscore_in_a_typed_address_is_not_a_wildcard(): void
    {
        $this->student('1', 'Informatique', 1, ['student_email' => 'rabe.zo@zurcher.edu.mg']);

        $d = $this->create($this->registrar(), $this->form(['email' => 'ra_e.zo@zurcher.edu.mg']))->assertCreated()->json('data');

        $this->assertSame('ra_e.zo@zurcher.edu.mg', $d['email']);
        $this->assertNull($d['email_notice']);
    }

    public function test_the_baccalaureate_is_written_up_to_the_third_year_and_needs_both_values(): void
    {
        $reg = $this->registrar();
        $third = $this->create($reg, $this->form(['last_name' => 'Troisieme', 'level' => 3, 'bacc_date' => '2022-07-05', 'bacc_series' => 'C']))->assertCreated()->json('data');

        $this->assertSame(1, DB::table('t_2024_bacc')->where('student_id', $third['student_id'])->count());
        $this->create($reg, $this->form(['bacc_series' => 'C']))->assertUnprocessable()->assertJsonValidationErrors('bacc_date');
        $this->create($reg, $this->form(['bacc_date' => '2022-07-05']))->assertUnprocessable()->assertJsonValidationErrors('bacc_series');
        $this->create($reg, $this->form(['level' => 4, 'bacc_date' => '2022-07-05']))->assertUnprocessable()->assertJsonValidationErrors('bacc_date');
        $this->create($reg, $this->form(['level' => 4, 'previous_degree' => 'Licence']))->assertUnprocessable()->assertJsonValidationErrors('previous_degree_date');
        $this->create($reg, $this->form(['level' => 1, 'previous_degree_date' => '2022-07-05']))->assertUnprocessable()->assertJsonValidationErrors('previous_degree_date');
    }

    public function test_the_same_form_sent_twice_creates_one_student_and_returns_the_existing_one(): void
    {
        $reg = $this->registrar();
        $form = $this->form();

        $first = $this->create($reg, $form)->assertCreated()->json('data');
        $second = $this->create($reg, $form)->assertOk()->json('data');

        $this->assertSame(1, DB::table('tbl_2024_etudiant')->count());
        $this->assertSame(1, DB::table('t_etudiant_creation')->count());
        $this->assertSame([$first['id'], $first['student_id'], true, null], [$second['id'], $second['student_id'], $second['already'], $second['initial_password']], 'le mot de passe n’est rendu qu’une fois');
        $this->assertSame(0, DB::table('t_2024_bacc')->count(), 'rien n’est écrit quand le diplôme précédent n’est pas renseigné');
    }

    public function test_an_existing_student_history_makes_them_a_former_student(): void
    {
        $reg = $this->registrar();
        DB::table('t_2023_notes')->insert(['id_cours' => '1', 'student_id' => '30011', 'semester' => 1, 'annee_scolaire' => '2024 - 2025', 'Sigle' => 'X', 'title_cours' => 'X', 'cours_category' => 1, 'yearlevel' => 1, 'ajout' => 1, 'remove' => 0, 'user_id_entry' => 't']);

        $withHistory = $this->create($reg, $this->form(['last_name' => 'Hist', 'student_id' => '30011', 'is_new' => true]))->assertCreated()->json('data');
        $chosen = $this->create($reg, $this->form(['last_name' => 'Ancien', 'student_id' => '30050', 'is_new' => false]))->assertCreated()->json('data');
        $fresh = $this->create($reg, $this->form(['last_name' => 'Neuf', 'student_id' => '30060', 'is_new' => true]))->assertCreated()->json('data');
        $default = $this->create($reg, $this->form(['last_name' => 'Defaut', 'student_id' => '30070']))->assertCreated()->json('data');

        $new = fn (array $d) => (int) DB::table('tbl_2024_etudiant')->where('id', $d['id'])->value('new_student');
        $this->assertSame([0, 0, 1, 1], [$new($withHistory), $new($chosen), $new($fresh), $new($default)]);
    }

    public function test_the_creation_leaves_one_activity_entry_without_any_password(): void
    {
        $d = $this->create($this->registrar(), $this->form())->assertCreated()->json('data');

        $log = DB::table('t_audit_requete')->where('libelle', 'Étudiants · création')->first();
        $this->assertNotNull($log);
        $this->assertGreaterThanOrEqual(2, DB::table('t_audit_modification')->where('requete_id', $log->id)->count());   // fiche, création
        $dump = json_encode(DB::table('t_audit_modification')->get()).json_encode(DB::table('t_audit_requete')->get());
        $this->assertStringNotContainsString($d['initial_password'], $dump);
    }

    public function test_the_google_account_is_not_created_during_the_request(): void
    {
        config(['registrar.google_admin.key_path' => '']);
        $a = $this->create($this->registrar(), $this->form(['last_name' => 'Sans']))->assertCreated()->json('data');
        $this->assertSame(['non_configure', 'L’API Google Admin n’est pas configurée.'], [$a['google']['status'], $a['google']['reason']]);

        config(['registrar.google_admin.key_path' => base_path('tests/fixtures/google-test-key.json')]);
        \Illuminate\Support\Facades\Http::fake();   // aucun appel réseau pendant la requête
        $b = $this->create($this->registrar(), $this->form(['last_name' => 'Avec']))->assertCreated()->json('data');

        $this->assertSame(['en_attente', null], [$b['google']['status'], $b['google']['reason']]);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_the_google_status_is_readable_and_unknown_students_are_not_found(): void
    {
        $d = $this->create($this->registrar(), $this->form())->assertCreated()->json('data');
        $reg = $this->registrar();

        $this->api($reg)->getJson("/api/v1/staff/students/{$d['id']}/google")->assertOk()->assertJsonPath('data.status', 'non_configure');
        $this->api($reg)->getJson('/api/v1/staff/students/999999/google')->assertNotFound()->assertJsonPath('code', 'students.creation_not_found');
    }

    // ------------------------------------------------------------------ conversion d'une candidature

    public function test_creating_from_an_accepted_application_converts_and_archives_it(): void
    {
        $app = $this->candidate('accepte');
        $reg = $this->registrar(['prenom' => 'Hary', 'nom' => 'Rabe']);

        $d = $this->create($reg, $this->form(['application_id' => $app]))->assertCreated()->json('data');

        $c = DB::table('candidat')->where('id', $app)->first();
        $this->assertSame(['converti', $d['student_id'], '2026-11-15 10:00:00', '2026-11-15 10:00:00', 'Hary Rabe'], [$c->statut, $c->student_id, $c->date_traitement, $c->archive_le, $c->archive_par]);
        $this->assertTrue($d['application_converted']);
        $this->assertSame($app, $d['application_id']);
        $this->assertSame(0, DB::table('t_candidat_notification')->count(), 'une licence a déjà reçu son courriel à l’acceptation');
    }

    public function test_an_archive_made_earlier_keeps_its_date_and_author(): void
    {
        $app = $this->candidate('accepte', ['archive_le' => '2026-10-01 08:00:00', 'archive_par' => 'Autre Agent']);

        $this->create($this->registrar(), $this->form(['application_id' => $app]))->assertCreated();

        $c = DB::table('candidat')->where('id', $app)->first();
        $this->assertSame(['2026-10-01 08:00:00', 'Autre Agent'], [$c->archive_le, $c->archive_par]);
    }

    public function test_a_non_accepted_or_converted_application_refuses_the_creation_and_writes_nothing(): void
    {
        $reg = $this->registrar();
        $fiche = $this->student('UAZ-9');
        $done = $this->candidate('converti', ['student_id' => 'UAZ-9']);
        $new = $this->candidate('nouveau');
        $before = DB::table('tbl_2024_etudiant')->count();

        $this->create($reg, $this->form(['application_id' => $done]))->assertStatus(409)->assertJsonPath('code', 'students.application_converted')->assertJsonPath('student_file_id', $fiche);
        $this->create($reg, $this->form(['application_id' => $new]))->assertStatus(409)->assertJsonPath('code', 'students.application_not_accepted');
        $this->create($reg, $this->form(['application_id' => 999999]))->assertNotFound();

        $this->assertSame($before, DB::table('tbl_2024_etudiant')->count());
        $this->assertSame('nouveau', DB::table('candidat')->where('id', $new)->value('statut'));
    }

    public function test_a_master_applicant_gets_the_credentials_email_task_and_a_licence_one_does_not(): void
    {
        $master = $this->candidate('accepte', ['diplome_vise' => 'master']);
        $noMail = $this->candidate('accepte', ['diplome_vise' => 'master', 'email' => '']);
        $reg = $this->registrar();

        $a = $this->create($reg, $this->form(['last_name' => 'Mas', 'level' => 4, 'application_id' => $master]))->assertCreated()->json('data');
        $this->create($reg, $this->form(['last_name' => 'Sans', 'level' => 4, 'application_id' => $noMail]))->assertCreated();

        $t = DB::table('t_candidat_notification')->where('candidat_id', $master)->first();
        $this->assertSame(['email', 'identifiants', 'en_attente'], [$t->canal, $t->decision, $t->statut]);
        $this->assertSame(['ignoree', 'Le candidat n’a pas laissé d’adresse email.'], [DB::table('t_candidat_notification')->where('candidat_id', $noMail)->value('statut'), DB::table('t_candidat_notification')->where('candidat_id', $noMail)->value('raison')]);
        $this->assertNotNull($a['email']);
    }

    public function test_creating_without_an_application_leaves_candidates_untouched(): void
    {
        $other = $this->candidate('accepte');

        $this->create($this->registrar(), $this->form())->assertCreated();

        $this->assertSame('accepte', DB::table('candidat')->where('id', $other)->value('statut'));
    }
}
