<?php

namespace Tests\Feature\Teacher;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesAttendanceData;
use Tests\Concerns\MakesStudentData;
use Tests\Concerns\MakesTeacherData;
use Tests\TestCase;

/** Qui voit l'espace enseignant (`teacher_space` dans /auth/me) : les enseignants, et le personnel qui est aussi enseignant. */
class TeacherSpaceTest extends TestCase
{
    use MakesAttendanceData;
    use MakesStudentData;
    use MakesTeacherData;

    private function me(User $u): array
    {
        return $this->actingAs($u, 'web')->fromApp()->getJson('/api/v1/auth/me')->assertOk()->json('data');
    }

    public function test_a_teacher_always_has_the_space_even_without_a_linked_record(): void
    {
        [$linked] = $this->makeTeacher();
        $unlinked = User::factory()->staff(7, 'teacher')->create(['nom' => 'Inconnu', 'prenom' => 'Personne', 'mail' => 'p@exemple.test', 'teacher_uid' => null]);
        $chef = User::factory()->staff(6, 'chef_mention')->create(['teacher_uid' => null, 'nom' => 'Chef', 'prenom' => 'Sans fiche']);

        $this->assertSame([true, true, true], [$this->me($linked)['teacher_space'], $this->me($unlinked)['teacher_space'], $this->me($chef)['teacher_space']]);
    }

    public function test_a_staff_member_who_is_also_a_teacher_gets_the_space_without_losing_their_role(): void
    {
        // Compte admin dont le nom est rangé « nom, prénoms » alors que la fiche enseignant les range à l'envers : retrouvée quand même.
        DB::table('teacher')->insert(['uid' => 797001, 'name' => 'Rakotomahefa', 'lastName' => 'Andriamirindra', 'sex' => 0, 'remove' => 0]);
        $admin = User::factory()->staff(2, 'admin')->create(['nom' => 'Rakotomahefa', 'prenom' => 'Andriamirindra', 'teacher_uid' => null]);
        $course = $this->makeCourse(797001);
        $this->enrol($course, ['E001' => ['A', 'a']]);

        $me = $this->me($admin);

        $this->assertSame(['admin', 2, true], [$me['role'], $me['level'], $me['teacher_space']]);
        $this->actingAs($admin, 'web')->fromApp()->getJson('/api/v1/teacher/courses?year='.rawurlencode($this->year))->assertOk()->assertJsonPath('data.courses.0.id', $course);
    }

    public function test_a_staff_member_who_is_not_a_teacher_does_not_get_the_space(): void
    {
        $registrar = User::factory()->staff(3, 'registrar')->create(['nom' => 'Personne', 'prenom' => 'Aucune fiche', 'mail' => 'rien@exemple.test', 'teacher_uid' => null]);
        $accounting = User::factory()->staff(4, 'comptabilite')->create();

        $this->assertSame([false, false], [$this->me($registrar)['teacher_space'], $this->me($accounting)['teacher_space']]);
    }

    public function test_an_ambiguous_match_gives_no_space(): void
    {
        DB::table('teacher')->insert([['uid' => 797002, 'name' => 'Homo', 'lastName' => 'Nyme', 'sex' => 0, 'remove' => 0], ['uid' => 797003, 'name' => 'Homo', 'lastName' => 'Nyme', 'sex' => 0, 'remove' => 0]]);
        $admin = User::factory()->staff(2, 'admin')->create(['nom' => 'Homo', 'prenom' => 'Nyme', 'teacher_uid' => null, 'mail' => 'x@exemple.test']);

        $this->assertFalse($this->me($admin)['teacher_space']);
    }

    public function test_a_student_and_an_inactive_teacher_never_have_it(): void
    {
        [$teacher] = $this->makeTeacher();
        $teacher->update(['etat' => 0]);

        $this->assertFalse($this->me(User::factory()->create())['teacher_space']);
        $this->assertFalse(\App\Support\UserPayload::from($teacher->refresh())['teacher_space']);
    }
}
