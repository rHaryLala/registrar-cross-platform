<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Enseignants, cours et étudiants inscrits FICTIFS pour les tests des registres de présence (base *_test uniquement). */
trait MakesTeacherData
{
    private int $teacherCounter = 0;

    private int $courseCounter = 0;

    protected string $year = '2026 - 2027';

    /** Un compte enseignant relié à sa fiche `teacher` (uid) ; renvoie [compte, uid]. @return array{0: User, 1: int} */
    protected function makeTeacher(string $name = 'Andry', string $lastName = 'Rasoa', bool $linked = true): array
    {
        $uid = 700000 + (++$this->teacherCounter);
        DB::table('teacher')->insert(['uid' => $uid, 'name' => $name, 'lastName' => $lastName, 'email' => strtolower($name).$uid.'@exemple.test', 'sex' => 0, 'remove' => 0]);
        $user = User::factory()->staff(7, 'teacher')->create(['nom' => $lastName, 'prenom' => $name, 'teacher_uid' => $linked ? $uid : null, 'mail' => 'compte'.$uid.'@exemple.test']);

        return [$user, $uid];
    }

    /** Un cours dont $teacherUid est titulaire. */
    protected function makeCourse(int $teacherUid, string $code = 'INF 101', string $title = 'Introduction à l’informatique'): int
    {
        return DB::table('t_2023_cours')->insertGetId([
            'Sigle' => $code.' '.(++$this->courseCounter), 'title' => $title, 'id_teacher' => $teacherUid, 'yearlevel' => 1,
            'cout' => 0, 'cout_lab' => 0, 'ajout' => 0, 'remove' => 0,
        ]);
    }

    /**
     * Inscrit des étudiants (fiche + ligne de notes) à un cours. $students : matricule => [nom, prénom] ; l'option « suspended »
     * ou « retrait_universite » les exclut de l'appel.
     *
     * @param  array<string, array{0: string, 1: string}>  $students
     * @param  array<string, array<string, mixed>>  $flags
     */
    protected function enrol(int $courseId, array $students, int $semester = 1, ?string $year = null, array $flags = []): void
    {
        $year ??= $this->year;
        foreach ($students as $id => [$nom, $prenom]) {
            if (! DB::table('tbl_2024_etudiant')->where('student_id', $id)->exists()) {
                DB::table('tbl_2024_etudiant')->insert([
                    'student_id' => (string) $id, 'student_nom' => $nom, 'student_prenom' => $prenom, 'password' => '', 'abonment' => 0,
                    'annee_scolaire' => '2025-2026', 'period' => 'S1', 'remove' => 0,
                ] + ($flags[$id] ?? []));
            }
            DB::table('t_2023_notes')->insert([
                'id_cours' => (string) $courseId, 'student_id' => (string) $id, 'semester' => $semester, 'annee_scolaire' => $year,
                'Sigle' => 'X', 'title_cours' => 'X', 'cours_category' => 1, 'yearlevel' => 1, 'ajout' => 1, 'remove' => 0, 'user_id_entry' => 'test',
            ]);
        }
    }

    /** Un registre déjà ouvert pour le cours, avec ses séances prévues. */
    protected function openSheet(int $courseId, ?int $planned, int $semester = 1, ?string $year = null, ?int $teacherUid = null): int
    {
        return DB::table('t_presence_feuille')->insertGetId([
            'cours_id' => $courseId, 'sigle' => 'INF', 'titre_cours' => 'Cours', 'teacher_uid' => $teacherUid, 'annee_scolaire' => $year ?? $this->year,
            'semestre' => $semester, 'seances_prevues' => $planned, 'cree_le' => '2026-09-01 08:00:00', 'modifie_le' => '2026-09-01 08:00:00',
        ]);
    }

    /** Un appel complet : un enseignant, un cours, 3 étudiants. @return array{0: User, 1: int, 2: int} compte, cours, uid */
    protected function classroom(): array
    {
        [$user, $uid] = $this->makeTeacher();
        $course = $this->makeCourse($uid);
        $this->enrol($course, ['E001' => ['Andria', 'Zo'], 'E002' => ['Bema', 'Lova'], 'E003' => ['Rabe', 'Tiana']]);

        return [$user, $course, $uid];
    }

    protected function base(): array
    {
        return ['year' => $this->year, 'semester' => 1];
    }
}
