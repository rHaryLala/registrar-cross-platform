<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un étudiant entre avec le mot de passe de SA fiche (tbl_2024_etudiant.password), par matricule ou e-mail,
 * même sans compte utilisateur : le compte est alors créé, comme l'ancien code le fait pour Google.
 */
class StudentTableLoginTest extends TestCase
{
    /** Une fiche d'étudiant SANS compte utilisateur. */
    private function file(string $id, string $password = 'RStudent2027', string $email = '', string $year = '2025-2026', int $removed = 0, string $nom = 'Rakoto', string $prenom = 'Fanja'): int
    {
        return DB::table('tbl_2024_etudiant')->insertGetId([
            'student_id' => $id, 'student_nom' => $nom, 'student_prenom' => $prenom, 'student_email' => $email, 'password' => $password,
            'abonment' => 0, 'annee_scolaire' => $year, 'period' => 'S1', 'remove' => $removed,
        ]);
    }

    private function login(string $identifier, string $password)
    {
        return $this->fromApp()->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => $password]);
    }

    private function log(string $event): ?object
    {
        return DB::table('security_logs')->where('event_type', $event)->orderByDesc('id')->first();
    }

    public function test_by_matricule_without_any_account_the_student_gets_in_and_the_account_is_created(): void
    {
        $this->file('2001');

        $this->login('2001', 'RStudent2027')->assertOk()->assertJsonPath('data.role', 'student')->assertJsonPath('data.student_id', '2001')->assertJsonPath('data.first_name', 'Fanja');

        $u = User::query()->where('student_id', '2001')->sole();
        $this->assertSame(['2001', 'student', 'student', 8, 1], [$u->pseudo, $u->privilege, $u->user_type, (int) $u->level, (int) $u->etat]);
        $this->assertSame('student_table', json_decode($this->log('login_success')->details, true)['via']);
        $this->assertNotNull($this->log('student_account_created'));
    }

    public function test_the_new_session_stays_valid_on_the_next_request(): void
    {
        $this->file('2002');
        $this->login('2002', 'RStudent2027')->assertOk();
        $this->app['auth']->forgetGuards();   // un vrai serveur relit l'utilisateur à chaque requête

        $this->fromApp()->getJson('/api/v1/me/home')->assertOk()->assertJsonPath('data.student.student_id', '2002');
    }

    public function test_by_email_in_any_case_and_with_spaces(): void
    {
        $this->file('2003', 'Abc12345', 'Fanja.Rakoto@Zurcher.edu.mg');

        $this->login('  fanja.rakoto@zurcher.EDU.mg ', 'Abc12345')->assertOk()->assertJsonPath('data.student_id', '2003');
    }

    public function test_a_wrong_password_is_refused_with_the_generic_message_and_creates_nothing(): void
    {
        $this->file('2004');

        $res = $this->login('2004', 'pas-le-bon')->assertStatus(401)->assertJsonPath('code', 'auth.invalid_credentials');

        $this->assertSame(0, User::query()->where('student_id', '2004')->count());
        $this->assertSame('bad_password', json_decode($this->log('login_failed')->details, true)['reason']);
        $this->assertStringNotContainsString('pas-le-bon', $res->getContent());
        $this->assertStringNotContainsString('pas-le-bon', (string) $this->log('login_failed')->details);
    }

    public function test_the_password_is_case_sensitive(): void
    {
        $this->file('2005', 'RStudent2027');

        $this->login('2005', 'rstudent2027')->assertStatus(401);
    }

    public function test_an_unknown_matricule_or_email_gets_the_same_answer_as_a_wrong_password(): void
    {
        $a = $this->login('999999', 'RStudent2027')->assertStatus(401)->json();
        $this->file('2006');
        $b = $this->login('2006', 'faux')->assertStatus(401)->json();
        $c = $this->login('inconnu@zurcher.edu.mg', 'RStudent2027')->assertStatus(401)->json();

        $this->assertSame($a['code'], $b['code']);
        $this->assertSame($a['detail'], $b['detail']);
        $this->assertSame($a['code'], $c['code']);
    }

    public function test_an_empty_stored_password_never_opens_the_account(): void
    {
        $this->file('2007', '');

        $this->login('2007', '')->assertStatus(422);        // refusé dès la validation : un mot de passe vide n'existe pas
        $this->login('2007', ' ')->assertStatus(422);
        $this->login('2007', 'n-importe-quoi')->assertStatus(401);
        $this->assertSame(0, User::query()->where('student_id', '2007')->count());
    }

    public function test_surrounding_spaces_typed_by_a_phone_keyboard_are_ignored(): void
    {
        $this->file('2008', 'RStudent2027');

        $this->login('2008', 'RStudent2027 ')->assertOk();
    }

    public function test_the_stored_password_is_never_modified_or_copied(): void
    {
        $id = $this->file('2009', 'RStudent2027');

        $this->login('2009', 'RStudent2027')->assertOk();

        $this->assertSame('RStudent2027', DB::table('tbl_2024_etudiant')->where('id', $id)->value('password'));
        $account = User::query()->where('student_id', '2009')->sole();
        $this->assertStringNotContainsString('RStudent2027', (string) $account->password);
        $this->assertStringStartsWith('$2y$', $account->password);   // mot de passe ALÉATOIRE haché : seule la fiche fait foi
        $this->assertFalse(password_verify('RStudent2027', $account->password));
    }

    public function test_a_bcrypt_password_in_the_student_table_works_too(): void
    {
        $this->file('2010', password_hash('Futur-Mdp-1', PASSWORD_BCRYPT));

        $this->login('2010', 'Futur-Mdp-1')->assertOk();
        $this->login('2010', 'faux')->assertStatus(401);
    }

    // ---------------------------------------------------------------------------- comptes déjà existants

    public function test_an_existing_google_created_account_is_reused_not_duplicated(): void
    {
        $this->file('2020', 'RStudent2027');
        $existing = User::factory()->create(['pseudo' => '2020', 'student_id' => '2020']);   // mot de passe : celui de la fabrique

        $this->login('2020', 'RStudent2027')->assertOk()->assertJsonPath('data.id', $existing->id);

        $this->assertSame(1, User::query()->where('student_id', '2020')->count());
        $this->assertNull($this->log('student_account_created'));
    }

    public function test_the_accounts_own_password_still_works(): void
    {
        $this->file('2021', 'RStudent2027');
        User::factory()->create(['pseudo' => '2021', 'student_id' => '2021']);

        $this->login('2021', UserFactory::PASSWORD)->assertOk();
        $this->assertSame('account', json_decode($this->log('login_success')->details, true)['via']);
    }

    public function test_an_account_found_by_email_accepts_the_file_password_too(): void
    {
        $this->file('2022', 'RStudent2027', 'f2022@zurcher.edu.mg');
        $existing = User::factory()->create(['pseudo' => '2022', 'student_id' => '2022', 'mail' => 'f2022@zurcher.edu.mg']);

        $this->login('f2022@zurcher.edu.mg', 'RStudent2027')->assertOk()->assertJsonPath('data.id', $existing->id);
    }

    public function test_an_inactive_account_is_refused_and_no_second_account_is_created(): void
    {
        $this->file('2023', 'RStudent2027');
        User::factory()->inactive()->create(['pseudo' => 'ancien-2023', 'student_id' => '2023']);

        $this->login('2023', 'RStudent2027')->assertStatus(401);

        $this->assertSame(1, User::query()->where('student_id', '2023')->count());
        $this->assertSame('inactive_account', json_decode($this->log('login_failed')->details, true)['reason']);
    }

    public function test_a_staff_account_linked_to_a_matricule_is_never_opened_with_a_student_password(): void
    {
        $this->file('2024', 'RStudent2027');
        // Compte du personnel qui porte, par erreur, le matricule d'un étudiant.
        User::factory()->staff(3, 'registrar')->create(['pseudo' => 'registraire-x', 'student_id' => '2024']);

        $this->login('2024', 'RStudent2027')->assertStatus(401);
        $this->assertSame('account_conflict', json_decode($this->log('login_failed')->details, true)['reason']);
        $this->assertSame(1, User::query()->where('student_id', '2024')->count());
    }

    public function test_a_student_password_cannot_open_a_staff_account_found_by_pseudo(): void
    {
        $this->file('2025', 'RStudent2027');
        User::factory()->staff(3, 'registrar')->create(['pseudo' => '2025x', 'student_id' => '2025']);

        // L'identifiant désigne un compte du personnel : pas de repli sur la fiche étudiante.
        $this->login('2025x', 'RStudent2027')->assertStatus(401);
    }

    public function test_a_pseudo_already_taken_by_another_account_is_never_overwritten(): void
    {
        $this->file('2026', 'RStudent2027');
        $other = User::factory()->staff(3, 'registrar')->create(['pseudo' => '2026']);   // pseudo identique au matricule, autre personne

        $this->login('2026', 'RStudent2027')->assertStatus(401);

        $this->assertSame(0, User::query()->where('student_id', '2026')->count());
        $this->assertSame(1, User::query()->where('pseudo', '2026')->count());
        $this->assertNotNull(User::find($other->id));
    }

    public function test_an_inactive_account_holding_the_matricule_as_pseudo_blocks_the_creation(): void
    {
        $this->file('2029', 'RStudent2027');
        // Compte désactivé, d'une autre personne, dont le pseudo est ce matricule : la recherche par pseudo l'ignore (etat = 0),
        // mais créer un second compte au même pseudo prêterait à confusion.
        User::factory()->inactive()->staff(3, 'registrar')->create(['pseudo' => '2029']);

        $this->login('2029', 'RStudent2027')->assertStatus(401);

        $this->assertSame(0, User::query()->where('student_id', '2029')->count());
        $this->assertSame('account_conflict', json_decode($this->log('login_failed')->details, true)['reason']);
    }

    public function test_a_pseudo_equal_to_another_students_matricule_does_not_borrow_that_students_password(): void
    {
        $this->file('2051', 'MdpDe2051');
        $this->file('2052', 'MdpDe2052');
        User::factory()->create(['pseudo' => '2051x', 'student_id' => '2052']);

        // « 2051x » est le pseudo du compte de 2052 : seul le mot de passe de 2052 (ou du compte) l'ouvre.
        $this->login('2051x', 'MdpDe2051')->assertStatus(401);
        $this->login('2051x', 'MdpDe2052')->assertOk()->assertJsonPath('data.student_id', '2052');
    }

    public function test_the_created_account_does_not_copy_an_email_already_used_by_another_active_account(): void
    {
        User::factory()->create(['pseudo' => 'autre', 'mail' => 'famille@zurcher.edu.mg', 'student_id' => 'X']);
        $this->file('2027', 'RStudent2027', 'famille@zurcher.edu.mg');
        $this->file('2028', 'RStudent2028', 'libre@zurcher.edu.mg');

        $this->login('2027', 'RStudent2027')->assertOk();
        $this->login('2028', 'RStudent2028')->assertOk();

        $this->assertSame('', User::query()->where('student_id', '2027')->value('mail'));
        $this->assertSame('libre@zurcher.edu.mg', User::query()->where('student_id', '2028')->value('mail'));
    }

    // ---------------------------------------------------------------------------- données de la table

    public function test_a_removed_student_cannot_log_in(): void
    {
        $this->file('2030', 'RStudent2027', 'x2030@zurcher.edu.mg', '2024-2025', removed: 1);

        $this->login('2030', 'RStudent2027')->assertStatus(401);
        $this->login('x2030@zurcher.edu.mg', 'RStudent2027')->assertStatus(401);
        $this->assertSame(0, User::query()->where('student_id', '2030')->count());
    }

    public function test_only_the_most_recent_file_password_counts(): void
    {
        $this->file('2031', 'AncienMdp1', '', '2023-2024');
        $this->file('2031', 'NouveauMdp2', '', '2025-2026');

        $this->login('2031', 'AncienMdp1')->assertStatus(401);
        $this->login('2031', 'NouveauMdp2')->assertOk();
    }

    public function test_a_shared_email_is_told_apart_by_the_password(): void
    {
        $this->file('2032', 'MdpAlice1', 'famille@zurcher.edu.mg', nom: 'Rakoto', prenom: 'Alice');
        $this->file('2033', 'MdpBob22', 'famille@zurcher.edu.mg', nom: 'Rakoto', prenom: 'Bob');

        $this->login('famille@zurcher.edu.mg', 'MdpAlice1')->assertOk()->assertJsonPath('data.student_id', '2032');
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->login('famille@zurcher.edu.mg', 'MdpBob22')->assertOk()->assertJsonPath('data.student_id', '2033');
        // L'adresse partagée n'est copiée sur aucun des deux comptes créés : elle resterait ambiguë.
        $this->assertSame(['', ''], [User::query()->where('student_id', '2032')->value('mail'), User::query()->where('student_id', '2033')->value('mail')]);
    }

    public function test_siblings_still_get_in_by_email_when_one_of_them_already_has_an_account_with_that_email(): void
    {
        // Compte créé autrefois par Google pour l'aînée, avec l'adresse commune.
        $this->file('2036', 'MdpAlice1', 'duo@zurcher.edu.mg', nom: 'Rakoto', prenom: 'Alice');
        $this->file('2037', 'MdpBob22', 'duo@zurcher.edu.mg', nom: 'Rakoto', prenom: 'Bob');
        $alice = User::factory()->create(['pseudo' => '2036', 'student_id' => '2036', 'mail' => 'duo@zurcher.edu.mg']);

        $this->login('duo@zurcher.edu.mg', 'MdpAlice1')->assertOk()->assertJsonPath('data.id', $alice->id);
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->login('duo@zurcher.edu.mg', 'MdpBob22')->assertOk()->assertJsonPath('data.student_id', '2037');
    }

    public function test_a_shared_email_with_the_same_password_is_refused_rather_than_guessed_when_sharing_is_allowed(): void
    {
        config(['registrar.student_password_max_sharing' => 0]);   // partage toléré : seule l'ambiguïté reste
        $this->file('2034', 'MemeMdp1', 'jumeaux@zurcher.edu.mg', nom: 'Rakoto', prenom: 'Alice');
        $this->file('2035', 'MemeMdp1', 'jumeaux@zurcher.edu.mg', nom: 'Rakoto', prenom: 'Bob');

        $res = $this->login('jumeaux@zurcher.edu.mg', 'MemeMdp1')->assertStatus(401);

        $this->assertSame('auth.ambiguous_email', $res->json('code'));
        $this->assertStringContainsString('matricule', $res->json('detail'));
        $this->assertSame(0, User::query()->whereIn('student_id', ['2034', '2035'])->count());
        // Avec le matricule, aucune ambiguïté.
        $this->login('2034', 'MemeMdp1')->assertOk()->assertJsonPath('data.student_id', '2034');
    }

    // ------------------------------------------------------------ mot de passe commun à plusieurs étudiants

    public function test_a_password_shared_by_several_students_opens_no_account_by_default(): void
    {
        foreach (['2060', '2061', '2062'] as $id) {
            $this->file($id, 'MotDePasseInitial', "e{$id}@zurcher.edu.mg", nom: 'N'.$id);
        }

        $this->login('2060', 'MotDePasseInitial')->assertStatus(401);
        $this->login('e2061@zurcher.edu.mg', 'MotDePasseInitial')->assertStatus(401);

        $this->assertSame(0, User::query()->whereIn('student_id', ['2060', '2061', '2062'])->count());
        $this->assertSame('shared_password', json_decode($this->log('login_failed')->details, true)['reason']);
    }

    public function test_the_refusal_of_a_shared_password_does_not_tell_it_apart_from_a_wrong_password(): void
    {
        $this->file('2063', 'MotDePasseInitial');
        $this->file('2064', 'MotDePasseInitial');
        $this->file('2065', 'Personnel-Unique-9');

        $shared = $this->login('2063', 'MotDePasseInitial')->assertStatus(401)->json();
        $wrong = $this->login('2063', 'faux-faux')->assertStatus(401)->json();

        // Aucun oracle : on ne confirme pas qu'un mot de passe commun est « le bon » pour ce matricule.
        $this->assertSame($wrong['code'], $shared['code']);
        $this->assertSame($wrong['detail'], $shared['detail']);
        // Le message guide quand même l'étudiant qui a ce mot de passe initial.
        $this->assertStringContainsString('mot de passe initial', $shared['detail']);
        $this->assertStringContainsString('registrariat', $shared['detail']);
        // Un mot de passe PERSONNEL (unique) fonctionne.
        $this->login('2065', 'Personnel-Unique-9')->assertOk();
    }

    public function test_the_sharing_limit_can_be_lifted_knowingly(): void
    {
        config(['registrar.student_password_max_sharing' => 0]);
        $this->file('2066', 'MotDePasseInitial');
        $this->file('2067', 'MotDePasseInitial');

        $this->login('2066', 'MotDePasseInitial')->assertOk()->assertJsonPath('data.student_id', '2066');
    }

    public function test_the_limit_can_allow_a_small_number_of_holders(): void
    {
        config(['registrar.student_password_max_sharing' => 2]);   // une fratrie de deux
        foreach (['2068', '2069', '2070'] as $id) {
            $this->file($id, 'FratrieMdp');
        }
        $this->file('2071', 'DeuxSeulement1');
        $this->file('2072', 'DeuxSeulement1');

        $this->login('2071', 'DeuxSeulement1')->assertOk();      // 2 porteurs : toléré
        $this->login('2068', 'FratrieMdp')->assertStatus(401);   // 3 porteurs : refusé
    }

    public function test_several_rows_of_the_same_student_or_removed_students_do_not_count_as_sharing(): void
    {
        $this->file('2073', 'Personnel-Mdp-1', '', '2023-2024');
        $this->file('2073', 'Personnel-Mdp-1', '', '2025-2026');                 // même étudiant, deux lignes
        $this->file('2074', 'Personnel-Mdp-1', '', '2020-2021', removed: 1);      // étudiant retiré : n'est plus un porteur

        $this->login('2073', 'Personnel-Mdp-1')->assertOk()->assertJsonPath('data.student_id', '2073');
    }

    public function test_sharing_is_case_sensitive_like_the_passwords_themselves(): void
    {
        $this->file('2075', 'Abcdefgh1');
        $this->file('2076', 'ABCDEFGH1');   // un autre mot de passe : pas un partage

        $this->login('2075', 'Abcdefgh1')->assertOk();
    }

    public function test_a_shared_password_still_works_for_an_account_that_has_its_own_password_login(): void
    {
        // La règle ne touche QUE le mot de passe de la fiche : le mot de passe du compte reste valable.
        $this->file('2077', 'MotDePasseInitial');
        $this->file('2078', 'MotDePasseInitial');
        User::factory()->create(['pseudo' => '2077', 'student_id' => '2077']);

        $this->login('2077', UserFactory::PASSWORD)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->login('2077', 'MotDePasseInitial')->assertStatus(401);
    }

    public function test_the_switch_turns_the_file_password_login_off_without_touching_account_logins(): void
    {
        config(['registrar.student_password_login' => false]);
        $this->file('2040', 'RStudent2027');
        User::factory()->create(['pseudo' => 'compte-2040', 'student_id' => '2040']);

        $this->login('2040', 'RStudent2027')->assertStatus(401);                       // fiche : coupé (pas de compte créé non plus)
        $this->assertSame(1, User::query()->where('student_id', '2040')->count());
        $this->login('compte-2040', UserFactory::PASSWORD)->assertOk();                // compte : toujours possible
    }

    public function test_the_login_throttle_still_applies_to_the_new_path(): void
    {
        $this->file('2041', 'RStudent2027');
        for ($i = 0; $i < 10; $i++) {
            $this->login('2041', 'faux'.$i)->assertStatus(401);
        }

        $this->login('2041', 'RStudent2027')->assertStatus(429);
        $this->assertSame(0, User::query()->where('student_id', '2041')->count());
    }
}
