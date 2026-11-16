<?php

namespace Tests\Feature\Directory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Les fiches (lecture seule) d'un étudiant, d'un cours et d'un enseignant. */
class DossiersTest extends TestCase
{
    private function api(User $u)
    {
        return $this->actingAs($u, 'web')->fromApp();
    }

    private function registrar(array $attrs = []): User
    {
        return User::factory()->staff(3, 'registrar')->create($attrs);
    }

    private function student(array $over = []): int
    {
        return DB::table('tbl_2024_etudiant')->insertGetId(array_merge([
            'student_id' => 'F001', 'student_nom' => 'Andria', 'student_prenom' => 'Zo', 'password' => 'hash-secret', 'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1',
            'remove' => 0, 'etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux', 'annee_etude' => '2', 'new_student' => 0, 'student_email' => 'zo@exemple.test',
            'student_tel' => '0340000000', 'num_cin' => '101 202 303 404', 'num_visa' => 'V-998', 'father_name' => 'Papa', 'sponsor_nom' => 'Sponsor', 'nationalite' => 'Malagasy',
        ], $over));
    }

    private function teacher(array $over = []): array
    {
        $uid = 810000 + random_int(1, 9999);
        $id = DB::table('teacher')->insertGetId(array_merge([
            'uid' => $uid, 'name' => 'Hasina', 'lastName' => 'Rasolofo', 'email' => "h{$uid}@exemple.test", 'sex' => 0, 'remove' => 0, 'phone' => '0320000000', 'diplome' => 'Doctorat',
            'blood_group' => 'O+', 'religion' => 'Adventiste', 'birthday' => '1980-01-01',
        ], $over));

        return [$id, $uid];
    }

    private function course(array $over = []): int
    {
        return DB::table('t_2023_cours')->insertGetId(array_merge([
            'Sigle' => 'INF 100', 'title' => 'Algorithmique', 'id_teacher' => null, 'yearlevel' => 2, 'cout' => 12345, 'cout_lab' => 999, 'ajout' => 0, 'remove' => 0, 'dep_desc' => 'INFO',
            'nb_crd' => 3, 'category' => 1, 'semester' => 1, 'parcours' => 'all',
        ], $over));
    }

    public function test_the_student_file_has_identity_and_academic_data_but_never_secrets(): void
    {
        $id = $this->student();
        DB::table('t_2023_notes')->insert(['id_cours' => '1', 'student_id' => 'F001', 'semester' => 1, 'annee_scolaire' => '2025-2026', 'Sigle' => 'X', 'title_cours' => 'X', 'cours_category' => 1, 'yearlevel' => 2, 'ajout' => 1, 'remove' => 0, 'user_id_entry' => 't']);

        $r = $this->api($this->registrar())->getJson("/api/v1/staff/students/{$id}")->assertOk();
        $d = $r->json('data');

        $this->assertSame(['F001', 'Andria', 'Zo', 'zo@exemple.test', 'Informatique', 'Licence 2', 'Malagasy'], [$d['student_id'], $d['last_name'], $d['first_name'], $d['email'], $d['mention'], $d['level_label'], $d['nationality']]);
        foreach (['hash-secret', '101 202 303 404', 'V-998', 'Papa', 'Sponsor'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($d), "fuite : {$secret}");
        }
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertSame(['suspended' => false, 'left_university' => false, 'graduated' => false, 'file_complete' => false, 'reenrolment_authorised' => false], $d['status']);
    }

    public function test_a_removed_or_unknown_student_file_is_not_found(): void
    {
        $gone = $this->student(['student_id' => 'F002', 'remove' => 1]);
        $reg = $this->registrar();

        $this->api($reg)->getJson("/api/v1/staff/students/{$gone}")->assertNotFound()->assertJsonPath('code', 'directory.not_found');
        $this->api($reg)->getJson('/api/v1/staff/students/99999999')->assertNotFound();
    }

    public function test_a_file_outside_the_accounts_cycle_is_not_found(): void
    {
        $licence = $this->student(['student_id' => 'F003', 'annee_etude' => '1']);
        $master = $this->student(['student_id' => 'F004', 'annee_etude' => '4']);
        $noLevel = $this->student(['student_id' => 'F005', 'annee_etude' => '']);
        $forLicence = $this->registrar(['perimetre_cycle' => 'L']);

        $this->api($forLicence)->getJson("/api/v1/staff/students/{$licence}")->assertOk();
        $this->api($forLicence)->getJson("/api/v1/staff/students/{$master}")->assertNotFound();
        $this->api($forLicence)->getJson("/api/v1/staff/students/{$noLevel}")->assertNotFound();
        $this->api($this->registrar())->getJson("/api/v1/staff/students/{$master}")->assertOk();
    }

    public function test_student_files_are_for_staff_only(): void
    {
        $id = $this->student();

        foreach ([User::factory()->staff(7, 'teacher')->create(), User::factory()->create(), User::factory()->staff(9, 'work_education')->create(), User::factory()->staff(3, 'registrar')->inactive()->create()] as $who) {
            $this->api($who)->getJson("/api/v1/staff/students/{$id}")->assertForbidden();
        }
        $this->api(User::factory()->staff(6, 'chef_mention')->create(['perimetre_mention' => 'Education']))->getJson("/api/v1/staff/students/{$id}")->assertOk();   // fiche commune
    }

    public function test_the_course_file_shows_its_teacher_and_enrolments_but_no_prices(): void
    {
        [$tid, $uid] = $this->teacher();
        DB::table('filiere_parcours')->insert(['shortcode' => 'RES', 'description' => 'Réseaux', 'description_ang' => '', 'departement' => 'INFO']);
        $c = $this->course(['id_teacher' => $uid, 'parcours' => 'RES', 'sigle_affiche' => 'INF-A', 'title_english' => 'Algorithms', 'lab' => 1]);
        foreach ([['S1', '2025-2026'], ['S2', '2025-2026'], ['S2', '2025-2026'], ['S3', '2024-2025']] as [$s, $y]) {
            DB::table('t_2023_notes')->insert(['id_cours' => (string) $c, 'student_id' => $s, 'semester' => 1, 'annee_scolaire' => $y, 'Sigle' => 'X', 'title_cours' => 'X', 'cours_category' => 1, 'yearlevel' => 2, 'ajout' => 1, 'remove' => 0, 'user_id_entry' => 't']);
        }

        $d = $this->api($this->registrar())->getJson("/api/v1/staff/courses/{$c}")->assertOk()->json('data');

        $this->assertSame(['INF-A', 'Algorithmique', 'Algorithms', 'Réseaux', 'Licence 2', true], [$d['code'], $d['title'], $d['title_english'], $d['track'], $d['level_label'], $d['lab']]);
        $this->assertSame(['id' => $tid, 'name' => 'HASINA Rasolofo'], $d['teacher']);
        $this->assertSame([['year' => '2025-2026', 'students' => 2], ['year' => '2024-2025', 'students' => 1]], $d['enrolments']);
        $this->assertStringNotContainsString('12345', json_encode($d));
        $this->assertStringNotContainsString('999', json_encode($d));
    }

    public function test_course_files_follow_the_cycle_and_the_removal_and_are_for_superadmin_and_registrar(): void
    {
        $l = $this->course(['yearlevel' => 1]);
        $m = $this->course(['yearlevel' => 5, 'Sigle' => 'INF 500']);
        $gone = $this->course(['remove' => 1, 'Sigle' => 'INF 999']);
        $licence = $this->registrar(['perimetre_cycle' => 'L']);

        $this->api($licence)->getJson("/api/v1/staff/courses/{$l}")->assertOk();
        $this->api($licence)->getJson("/api/v1/staff/courses/{$m}")->assertNotFound();
        $this->api($this->registrar())->getJson("/api/v1/staff/courses/{$gone}")->assertNotFound();
        foreach ([User::factory()->staff(2, 'admin')->create(), User::factory()->staff(4, 'comptabilite')->create(), User::factory()->staff(7, 'teacher')->create(), User::factory()->create()] as $who) {
            $this->api($who)->getJson("/api/v1/staff/courses/{$l}")->assertForbidden();
        }
        $this->api(User::factory()->staff(1, 'superadmin')->create())->getJson("/api/v1/staff/courses/{$l}")->assertOk();
    }

    public function test_the_teacher_file_lists_their_courses_and_hides_private_details(): void
    {
        [$tid, $uid] = $this->teacher();
        $this->course(['id_teacher' => $uid, 'title' => 'Zèbre', 'Sigle' => 'INF 2']);
        $this->course(['id_teacher' => $uid, 'title' => 'Algo', 'Sigle' => 'INF 1']);
        $this->course(['id_teacher' => $uid, 'title' => 'Supprimé', 'remove' => 1, 'Sigle' => 'INF 3']);
        $this->course(['id_teacher' => null, 'title' => 'Autre', 'Sigle' => 'INF 4']);

        $d = $this->api($this->registrar())->getJson("/api/v1/staff/teachers/{$tid}")->assertOk()->json('data');

        $this->assertSame(['Hasina', 'Rasolofo', 'Doctorat', '0320000000'], [$d['name'], $d['last_name'], $d['diploma'], $d['phone']]);
        $this->assertSame(['Algo', 'Zèbre'], array_column($d['courses'], 'title'), 'ses cours, par titre, hors supprimés');
        foreach (['O+', 'Adventiste', '1980-01-01'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($d), "fuite : {$private}");
        }
    }

    public function test_a_teacher_file_shows_only_the_courses_of_the_accounts_cycle(): void
    {
        [$tid, $uid] = $this->teacher();
        $this->course(['id_teacher' => $uid, 'title' => 'Licence', 'yearlevel' => 1, 'Sigle' => 'A 1']);
        $this->course(['id_teacher' => $uid, 'title' => 'Master', 'yearlevel' => 4, 'Sigle' => 'A 2']);

        $d = $this->api($this->registrar(['perimetre_cycle' => 'M']))->getJson("/api/v1/staff/teachers/{$tid}")->json('data');

        $this->assertSame(['Master'], array_column($d['courses'], 'title'));
    }

    public function test_teacher_files_are_for_superadmin_and_registrar_and_removed_ones_are_gone(): void
    {
        [$tid] = $this->teacher();
        [$gone] = $this->teacher(['remove' => 1]);

        $this->api($this->registrar())->getJson("/api/v1/staff/teachers/{$gone}")->assertNotFound();
        $this->api($this->registrar())->getJson('/api/v1/staff/teachers/99999999')->assertNotFound();
        foreach ([User::factory()->staff(2, 'admin')->create(), User::factory()->staff(7, 'teacher')->create(), User::factory()->create()] as $who) {
            $this->api($who)->getJson("/api/v1/staff/teachers/{$tid}")->assertForbidden();
        }
    }
}
