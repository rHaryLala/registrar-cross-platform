<?php

namespace Tests\Feature\Me;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesEnrollmentData;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** « Mes informations » : la fiche de l'étudiant connecté, en lecture seule, jamais celle d'un autre. */
class ProfileTest extends TestCase
{
    use MakesEnrollmentData;
    use MakesStudentData;

    private function profile(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp()->getJson('/api/v1/me/profile');
    }

    public function test_every_section_is_returned_with_the_files_values(): void
    {
        $regionId = DB::table('region')->insertGetId(['region' => 'Vakinankaratra', 'chefLieu' => 'Antsirabe', 'province' => 'Antananarivo']);
        $u = $this->makeStudent('4001', 'Rakoto', 'Fanja');
        $this->setStudentFile('4001', [
            'dateNaissance' => '2001-03-12', 'lieuNaissance' => 'Antsirabe', 'sex' => '0', 'nationalite' => 'Malagasy', 'pays_origine' => 'Madagascar',
            'num_cin' => '101 202 303 404', 'cin_date_delivre' => '2019-05-01', 'cin_region' => 'Vakinankaratra',
            'student_tel' => '034 00 000 00', 'student_email' => 'f@example.test', 'student_adresse' => 'Lot 12', 'student_region' => (string) $regionId,
            'etude_envisage' => 'Informatique', 'etude_option' => 'Génie logiciel', 'annee_etude' => 2, 'status' => 'Interne', 'religion' => 'Adventiste', 'abonment' => 1,
            'situationf' => 'Célibataire', 'nom_conjoint' => '', 'nb_enfant' => '0',
            'father_name' => 'Rakoto Jean', 'father_prof' => 'Enseignant', 'mother_name' => 'Soa Marie', 'mother_prof' => 'Commerçante', 'parent_tel' => '033 11 111 11', 'parent_adresse' => 'Lot 3',
            'sponsor_nom' => 'Église', 'sponsor_prenom' => 'Locale', 'sponsor_tel' => '032 22 222 22', 'sponsor_adresse' => 'Centre',
        ]);

        $d = $this->profile($u)->assertOk()->json('data');

        $this->assertSame(['4001', 'Fanja', 'Rakoto', 'Licence 2', 'Ancien'], [$d['student_id'], $d['first_name'], $d['last_name'], $d['level_label'], $d['student_type']]);
        $this->assertSame(['2001-03-12', 'Antsirabe', 'Féminin', 'Malagasy', 'Madagascar'], array_values($d['identity']));
        $this->assertSame(['101 202 303 404', '2019-05-01', 'Vakinankaratra'], array_values($d['id_document']));
        $this->assertSame(['034 00 000 00', 'f@example.test', 'Lot 12', 'Vakinankaratra'], array_values($d['contact']));
        $this->assertSame(['Informatique', 'Génie logiciel', 'Licence 2', '2025-2026', 'Interne', 'Adventiste', true], array_values($d['academic']));
        $this->assertSame(['Rakoto Jean', 'Enseignant', 'Soa Marie', 'Commerçante', '033 11 111 11', 'Lot 3'], array_values($d['family']));
        $this->assertSame(['Célibataire', null, '0'], array_values($d['civil']));
        $this->assertSame(['Église', 'Locale', '032 22 222 22', 'Centre'], array_values($d['sponsor']));
        $this->assertFalse($d['status']['suspended']);
        $this->assertFalse($d['status']['withdrawn']);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function levels(): array
    {
        return ['remise à niveau' => [0, 'Remise à niveau'], 'L1' => [1, 'Licence 1'], 'L3' => [3, 'Licence 3'], 'M1' => [4, 'Master 1'], 'M2' => [5, 'Master 2'], 'hors liste' => [7, 'Niveau 7']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('levels')]
    public function test_the_level_label_follows_the_legacy_page(int $level, string $label): void
    {
        $u = $this->makeStudent('4002');
        $this->setStudentFile('4002', ['annee_etude' => $level]);

        $d = $this->profile($u)->json('data');

        $this->assertSame($label, $d['level_label']);
        $this->assertSame($label, $d['academic']['level_label']);
    }

    public function test_student_type_and_sex_follow_the_legacy_page(): void
    {
        foreach ([[1, 'Nouveau'], [10, 'Spécial'], [0, 'Ancien'], [5, 'Ancien']] as $i => [$code, $type]) {
            $u = $this->makeStudent('410'.$i);
            $this->setStudentFile('410'.$i, ['new_student' => $code]);
            $this->assertSame($type, $this->profile($u)->json('data.student_type'), "new_student {$code}");
        }
        foreach ([['0', 'Féminin'], ['1', 'Masculin'], ['', null], ['M', 'Masculin']] as $i => [$code, $label]) {
            $u = $this->makeStudent('411'.$i);
            $this->setStudentFile('411'.$i, ['sex' => $code]);
            $this->assertSame($label, $this->profile($u)->json('data.identity.sex'), "sex « {$code} »");
        }
    }

    public function test_the_cafeteria_subscription_is_true_only_when_subscribed(): void
    {
        $u = $this->makeStudent('4020');
        $this->setStudentFile('4020', ['abonment' => 0]);
        $this->assertFalse($this->profile($u)->json('data.academic.cafeteria'));
        $this->setStudentFile('4020', ['abonment' => 1]);
        $this->assertTrue($this->profile($u)->json('data.academic.cafeteria'));
    }

    public function test_blank_values_are_null_not_dashes_and_spaces_are_trimmed(): void
    {
        $u = $this->makeStudent('4003');
        $this->setStudentFile('4003', ['lieuNaissance' => '   ', 'nationalite' => '  Malagasy  ', 'student_tel' => '']);

        $d = $this->profile($u)->json('data');

        $this->assertNull($d['identity']['birth_place']);
        $this->assertSame('Malagasy', $d['identity']['nationality']);
        $this->assertNull($d['contact']['phone']);
    }

    public function test_invalid_and_zero_dates_are_null(): void
    {
        $u = $this->makeStudent('4004');
        // L'ancienne base contient des dates « zéro » : le mode strict de MySQL les refuserait à l'écriture du test.
        $mode = DB::selectOne('SELECT @@sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            foreach (['0000-00-00', '2001-02-30', 'hier', ''] as $bad) {
                $this->setStudentFile('4004', ['dateNaissance' => $bad, 'cin_date_delivre' => $bad]);
                $this->assertNull($this->profile($u)->json('data.identity.birth_date'), json_encode($bad));
                $this->assertNull($this->profile($u)->json('data.id_document.cin_date'), json_encode($bad));
            }
            $this->setStudentFile('4004', ['dateNaissance' => '2001-02-28 00:00:00']);
            $this->assertSame('2001-02-28', $this->profile($u)->json('data.identity.birth_date'));
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);   // la connexion est partagée par les tests suivants
        }
    }

    public function test_the_region_is_looked_up_and_an_unknown_one_is_null(): void
    {
        $id = DB::table('region')->insertGetId(['region' => 'Itasy', 'chefLieu' => 'Miarinarivo', 'province' => 'Antananarivo']);
        $u = $this->makeStudent('4005');

        $this->setStudentFile('4005', ['student_region' => (string) $id]);
        $this->assertSame('Itasy', $this->profile($u)->json('data.contact.region'));
        foreach (['', '99999999', 'abc', '0'] as $v) {
            $this->setStudentFile('4005', ['student_region' => $v]);
            $this->assertNull($this->profile($u)->json('data.contact.region'), json_encode($v));
        }
    }

    public function test_suspension_and_withdrawal_follow_the_legacy_thresholds(): void
    {
        $u = $this->makeStudent('4006');
        $this->setStudentFile('4006', ['suspended' => 1, 'motif_suspension' => 'Frais impayés', 'retrait_universite' => 1]);
        $d = $this->profile($u)->json('data.status');
        $this->assertSame([true, 'Frais impayés', false], [$d['suspended'], $d['suspension_reason'], $d['withdrawn']]);   // retrait 1 : pas encore un retrait

        $this->setStudentFile('4006', ['suspended' => 0, 'retrait_universite' => 2]);
        $d = $this->profile($u)->json('data.status');
        $this->assertSame([false, true], [$d['suspended'], $d['withdrawn']]);

        $this->setStudentFile('4006', ['suspended' => 2, 'retrait_universite' => 0]);
        $this->assertFalse($this->profile($u)->json('data.status.suspended'));   // seule la valeur 1 suspend
    }

    public function test_the_most_recent_non_removed_row_is_used(): void
    {
        $u = $this->makeStudent('4007', 'Ancien', 'Nom');   // 2025-2026
        $this->setStudentFile('4007', ['etude_envisage' => 'Mention 2025']);
        $row = fn (array $x) => DB::table('tbl_2024_etudiant')->insert(array_merge(['student_id' => '4007', 'student_nom' => 'Ancien', 'student_prenom' => 'Nom', 'password' => '', 'abonment' => 0, 'period' => 'S1'], $x));
        $row(['annee_scolaire' => '2026-2027', 'remove' => 0, 'etude_envisage' => 'Mention 2026']);
        $row(['annee_scolaire' => '2027-2028', 'remove' => 1, 'etude_envisage' => 'Ligne retirée']);

        $this->assertSame('Mention 2026', $this->profile($u)->json('data.academic.mention'));
    }

    public function test_a_student_never_sees_another_students_file(): void
    {
        $a = $this->makeStudent('4008', 'Alice', 'A');
        $this->makeStudent('4009', 'Secret', 'Bob');
        $this->setStudentFile('4009', ['num_cin' => '999-SECRET', 'student_tel' => '000-SECRET']);

        $res = $this->profile($a)->assertOk();

        $this->assertStringNotContainsString('SECRET', $res->getContent());
        $this->assertSame('4008', $res->json('data.student_id'));
    }

    public function test_no_secret_column_is_ever_returned(): void
    {
        $u = $this->makeStudent('4010');
        $this->setStudentFile('4010', ['password' => 'Mot-De-Passe-Clair-1']);

        $res = $this->profile($u)->assertOk();

        $this->assertStringNotContainsString('Mot-De-Passe-Clair-1', $res->getContent());
        $this->assertStringNotContainsStringIgnoringCase('password', $res->getContent());
    }

    public function test_the_response_is_never_cached(): void
    {
        $u = $this->makeStudent('4011');

        $this->assertStringContainsString('no-store', (string) $this->profile($u)->headers->get('Cache-Control'));
    }

    public function test_a_student_account_without_any_file_gets_a_clear_404(): void
    {
        $u = User::factory()->create(['student_id' => 'SANS-FICHE']);

        $this->profile($u)->assertStatus(404)->assertJsonPath('code', 'profile.not_found');
    }

    public function test_the_photo_flag_is_present(): void
    {
        $u = $this->makeStudent('4012');

        $this->assertFalse($this->profile($u)->json('data.has_photo'));
    }

    public function test_staff_and_anonymous_are_kept_out_and_no_parameter_selects_another_student(): void
    {
        $this->getJson('/api/v1/me/profile')->assertStatus(401);
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff, 'web')->fromApp()->getJson('/api/v1/me/profile')->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');

        $a = $this->makeStudent('4013');
        $this->makeStudent('4014', 'Autre', 'Etudiant');
        $this->assertSame('4013', $this->actingAs($a, 'web')->fromApp()->getJson('/api/v1/me/profile?student_id=4014')->json('data.student_id'));
    }

    public function test_the_page_is_read_only(): void
    {
        $u = $this->makeStudent('4015');

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($u, 'web')->fromApp()->json($method, '/api/v1/me/profile', ['student_nom' => 'Pirate'])->assertStatus(405);
        }
        $this->assertNotSame('Pirate', DB::table('tbl_2024_etudiant')->where('student_id', '4015')->value('student_nom'));
    }
}
